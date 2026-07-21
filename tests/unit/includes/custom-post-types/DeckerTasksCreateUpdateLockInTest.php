<?php
/**
 * Characterization tests for Decker_Tasks::create_or_update_task().
 *
 * Pins validation codes, field persistence, WP_User normalization and the
 * exact hook firing order before the method is refactored into helpers.
 *
 * @package Decker
 */

class DeckerTasksCreateUpdateLockInTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;
	private $label_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$this->board_id = self::factory()->board->create();
		$this->label_id = self::factory()->label->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Lock the validation guard order and error codes.
	 */
	public function test_create_or_update_task_validation_error_codes() {
		// (a) Empty title.
		$result = Decker_Tasks::create_or_update_task(
			0,
			'',
			'desc',
			'to-do',
			$this->board_id,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_field', $result->get_error_code() );

		// (b) Empty stack.
		$result = Decker_Tasks::create_or_update_task(
			0,
			'Title',
			'desc',
			'',
			$this->board_id,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_field', $result->get_error_code() );

		// (c) Invalid stack value.
		$result = Decker_Tasks::create_or_update_task(
			0,
			'Title',
			'desc',
			'bogus',
			$this->board_id,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_field', $result->get_error_code() );

		// (d) Board 0.
		$result = Decker_Tasks::create_or_update_task(
			0,
			'Title',
			'desc',
			'to-do',
			0,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_field', $result->get_error_code() );

		// (e) Nonexistent board term.
		$result = Decker_Tasks::create_or_update_task(
			0,
			'Title',
			'desc',
			'to-do',
			999999,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid', $result->get_error_code() );
	}

	/**
	 * Lock that all fields are persisted on create.
	 */
	public function test_create_persists_all_fields() {
		$u1 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$u2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		$task_id = Decker_Tasks::create_or_update_task(
			0,
			'T',
			'<p>D</p>',
			'in-progress',
			$this->board_id,
			true,
			new DateTime( '2025-03-01' ),
			$this->editor,
			$u2,
			true,
			array( $u1, $u2 ),
			array( $this->label_id ),
			new DateTime( '2024-01-02 03:04:05' ),
			true,
			42
		);

		$this->assertNotWPError( $task_id );
		$this->assertIsInt( $task_id );

		$post = get_post( $task_id );
		$this->assertEquals( 'archived', $post->post_status );
		$this->assertEquals( '2024-01-02 03:04:05', $post->post_date );
		$this->assertEquals( $this->editor, (int) $post->post_author );

		$this->assertEquals( 'in-progress', get_post_meta( $task_id, 'stack', true ) );
		$this->assertEquals( '2025-03-01', get_post_meta( $task_id, 'duedate', true ) );
		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ) );
		$this->assertTrue( (bool) get_post_meta( $task_id, 'hidden', true ) );
		$this->assertEquals( $u2, (int) get_post_meta( $task_id, 'responsable', true ) );
		$this->assertEquals( 42, (int) get_post_meta( $task_id, 'id_nextcloud_card', true ) );
		$this->assertEquals( array( $u1, $u2 ), get_post_meta( $task_id, 'assigned_users', true ) );

		$boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->board_id, $boards );

		$labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->label_id, $labels );
	}

	/**
	 * Lock the wp_list_pluck mutation: WP_User objects become plain IDs in meta,
	 * and no decker_user_assigned action fires on create.
	 */
	public function test_create_accepts_wp_user_objects_for_assigned_users() {
		$u1 = self::factory()->user->create( array( 'role' => 'editor' ) );

		$fired = 0;
		add_action(
			'decker_user_assigned',
			function () use ( &$fired ) {
				$fired++;
			}
		);

		$task_id = Decker_Tasks::create_or_update_task(
			0,
			'T',
			'D',
			'to-do',
			$this->board_id,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array( get_user_by( 'id', $u1 ) ),
			array()
		);

		$this->assertNotWPError( $task_id );
		$this->assertEquals( array( $u1 ), get_post_meta( $task_id, 'assigned_users', true ) );
		$this->assertEquals( 0, $fired, 'decker_user_assigned must not fire on create.' );
	}

	/**
	 * Lock the exact hook firing order on update.
	 */
	public function test_update_fires_hooks_in_current_order() {
		$old_resp = self::factory()->user->create( array( 'role' => 'editor' ) );
		$new_resp = self::factory()->user->create( array( 'role' => 'editor' ) );
		$extra    = self::factory()->user->create( array( 'role' => 'editor' ) );

		$task_id = self::factory()->task->create(
			array(
				'board'          => $this->board_id,
				'stack'          => 'to-do',
				'responsable'    => $old_resp,
				'assigned_users' => array( $this->editor ),
			)
		);
		$this->assertNotWPError( $task_id );

		$sequence = array();
		add_action(
			'decker_stack_transition',
			function ( $id, $from, $to ) use ( &$sequence ) {
				$sequence[] = array( 'decker_stack_transition', $id, $from, $to );
			},
			10,
			3
		);
		add_action(
			'decker_task_completed',
			function ( $id, $stack, $user ) use ( &$sequence ) {
				$sequence[] = array( 'decker_task_completed', $id, $stack, $user );
			},
			10,
			3
		);
		add_action(
			'decker_task_updated',
			function ( $id ) use ( &$sequence ) {
				$sequence[] = array( 'decker_task_updated', $id );
			},
			10,
			1
		);
		add_action(
			'decker_task_responsable_changed',
			function ( $id, $old, $new ) use ( &$sequence ) {
				$sequence[] = array( 'decker_task_responsable_changed', $id, $old, $new );
			},
			10,
			3
		);
		add_action(
			'decker_user_assigned',
			function ( $id, $user ) use ( &$sequence ) {
				$sequence[] = array( 'decker_user_assigned', $id, $user );
			},
			10,
			2
		);

		$result = Decker_Tasks::create_or_update_task(
			$task_id,
			'T',
			'D',
			'done',
			$this->board_id,
			false,
			null,
			$this->editor,
			$new_resp,
			false,
			array( $this->editor, $extra ),
			array()
		);
		$this->assertNotWPError( $result );

		// Filter only the task hooks (ignore any incidental ones).
		$names = array_map(
			function ( $entry ) {
				return $entry[0];
			},
			$sequence
		);

		$this->assertEquals(
			array(
				'decker_stack_transition',
				'decker_task_completed',
				'decker_task_updated',
				'decker_task_responsable_changed',
				'decker_user_assigned',
			),
			$names,
			'Update hooks must fire in this exact order.'
		);

		// Verify arguments.
		$by_name = array();
		foreach ( $sequence as $entry ) {
			$by_name[ $entry[0] ] = $entry;
		}
		$this->assertEquals( array( 'decker_stack_transition', $task_id, 'to-do', 'done' ), $by_name['decker_stack_transition'] );
		$this->assertEquals( array( 'decker_task_completed', $task_id, 'done', get_current_user_id() ), $by_name['decker_task_completed'] );
		$this->assertEquals( array( 'decker_task_updated', $task_id ), $by_name['decker_task_updated'] );
		$this->assertEquals( array( 'decker_task_responsable_changed', $task_id, (int) $old_resp, (int) $new_resp ), $by_name['decker_task_responsable_changed'] );
		$this->assertEquals( array( 'decker_user_assigned', $task_id, $extra ), $by_name['decker_user_assigned'], 'Only the new user must be assigned.' );
	}

	/**
	 * Lock that no stack/created hooks fire when stack is unchanged on update.
	 */
	public function test_no_stack_hooks_when_stack_unchanged_and_no_created_hook_on_update() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$this->assertNotWPError( $task_id );

		$fired = array(
			'decker_stack_transition' => 0,
			'decker_task_completed'   => 0,
			'decker_task_created'     => 0,
			'decker_task_updated'     => 0,
		);
		foreach ( array_keys( $fired ) as $hook ) {
			add_action(
				$hook,
				function () use ( &$fired, $hook ) {
					$fired[ $hook ]++;
				}
			);
		}

		$result = Decker_Tasks::create_or_update_task(
			$task_id,
			'T',
			'D',
			'to-do',
			$this->board_id,
			false,
			null,
			$this->editor,
			$this->editor,
			false,
			array(),
			array()
		);
		$this->assertNotWPError( $result );

		$this->assertEquals( 0, $fired['decker_stack_transition'] );
		$this->assertEquals( 0, $fired['decker_task_completed'] );
		$this->assertEquals( 0, $fired['decker_task_created'] );
		$this->assertEquals( 1, $fired['decker_task_updated'] );
	}
}
