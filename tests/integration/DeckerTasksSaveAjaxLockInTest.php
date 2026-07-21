<?php
/**
 * Characterization tests for Decker_Tasks::handle_save_decker_task().
 *
 * Pins CSV/array parsing of assignees and labels, every isset()-ternary
 * default, the due-date fallback and the mark_for_today round-trip before the
 * sanitization ladder is refactored into reader helpers.
 *
 * @package Decker
 */

class DeckerTasksSaveAjaxLockInTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;

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

		add_filter( 'decker_save_task_send_response', '__return_false' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'decker_save_task_send_response', '__return_false' );
		$_POST = array();
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Lock CSV explode/absint parsing of assignees and labels.
	 */
	public function test_handle_save_decker_task_parses_csv_assignees_and_labels() {
		$u1 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$u2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$l1 = self::factory()->label->create();
		$l2 = self::factory()->label->create();

		$_POST = array(
			'task_id'      => 0,
			'title'        => 'T',
			'description'  => '<p>D</p>',
			'stack'        => 'to-do',
			'board'        => $this->board_id,
			'assignees'    => "$u1,$u2",
			'labels'       => "$l1,$l2",
			'max_priority' => '1',
			'hidden'       => '1',
			'due_date'     => '2025-05-01',
		);

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertTrue( $resp['success'] );
		$task_id = $resp['task_id'];

		$this->assertEquals( array( $u1, $u2 ), get_post_meta( $task_id, 'assigned_users', true ) );

		$labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$this->assertContains( $l1, $labels );
		$this->assertContains( $l2, $labels );

		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ) );
		$this->assertEquals( '2025-05-01', get_post_meta( $task_id, 'duedate', true ) );
	}

	/**
	 * Lock all isset()-ternary defaults and the invalid-due-date fallback.
	 */
	public function test_handle_save_decker_task_defaults_and_invalid_due_date() {
		$_POST = array(
			'task_id'     => 0,
			'title'       => 'T',
			'description' => 'D',
			'stack'       => 'to-do',
			'board'       => $this->board_id,
			'due_date'    => 'not-a-date',
		);

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $resp['success'] );
		$task_id = $resp['task_id'];

		$post = get_post( $task_id );
		$this->assertEquals( $this->editor, (int) $post->post_author, 'Author defaults to current user.' );
		$this->assertEquals( $this->editor, (int) get_post_meta( $task_id, 'responsable', true ), 'Responsable defaults to author.' );
		$this->assertEquals( '0', get_post_meta( $task_id, 'max_priority', true ), 'max_priority defaults to 0.' );
		$this->assertEquals( gmdate( 'Y-m-d' ), get_post_meta( $task_id, 'duedate', true ), 'Invalid due date falls back to today.' );
	}

	/**
	 * Lock the mark_for_today add/remove round-trip.
	 */
	public function test_handle_save_decker_task_mark_for_today_roundtrip() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$base = array(
			'task_id'        => $task_id,
			'title'          => 'T',
			'description'    => 'D',
			'stack'          => 'to-do',
			'board'          => $this->board_id,
			'mark_for_today' => '1',
		);

		$_POST = $base;
		( new Decker_Tasks() )->handle_save_decker_task();

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$found = false;
		foreach ( $relations as $relation ) {
			if ( (int) $relation['user_id'] === $this->editor && gmdate( 'Y-m-d' ) === $relation['date'] ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'mark_for_today must add today relation for current user.' );

		// Save again without mark_for_today: relation removed.
		$_POST = $base;
		unset( $_POST['mark_for_today'] );
		( new Decker_Tasks() )->handle_save_decker_task();

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$found     = false;
		foreach ( (array) $relations as $relation ) {
			if ( (int) $relation['user_id'] === $this->editor && gmdate( 'Y-m-d' ) === $relation['date'] ) {
				$found = true;
			}
		}
		$this->assertFalse( $found, 'Saving without mark_for_today must remove today relation.' );
	}

	/**
	 * Lock that an empty title is rejected with a WP_Error and creates no task.
	 *
	 * handle_save_decker_task() forwards this WP_Error to wp_send_json_error(),
	 * which in WP terminates the request via a bare die() outside an AJAX
	 * context (uncatchable in PHPUnit), so the guard is locked at the
	 * create_or_update_task() level where the WP_Error is actually produced.
	 */
	public function test_invalid_input_is_rejected_and_creates_no_task() {
		$before = wp_count_posts( 'decker_task' )->publish;

		$result = Decker_Tasks::create_or_update_task(
			0,
			'', // Empty title -> missing_field.
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

		$this->assertInstanceOf( WP_Error::class, $result, 'Empty title must return a WP_Error.' );
		$this->assertSame( 'missing_field', $result->get_error_code(), 'Error code must be missing_field.' );

		$after = wp_count_posts( 'decker_task' )->publish;
		$this->assertEquals( $before, $after, 'No task should be created on invalid input.' );
	}
}
