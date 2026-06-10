<?php
/**
 * Characterization tests for Decker_Tasks::save_meta().
 *
 * Pins field persistence, the two UNCONDITIONAL writes (max_priority and
 * _user_date_relations) and the guard-clause order before save_meta is
 * refactored into helpers.
 *
 * @package Decker
 */

class DeckerTasksSaveMetaLockInTest extends Decker_Test_Base {

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

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$this->board_id = self::factory()->board->create();
		$this->label_id = self::factory()->label->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$_POST = array();
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Lock that a valid save updates all posted fields, board by slug, labels by slug.
	 */
	public function test_save_meta_with_valid_nonce_updates_all_fields() {
		$u1      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$_POST = array(
			'decker_task_nonce'   => wp_create_nonce( 'save_decker_task' ),
			'post_type'           => 'decker_task',
			'duedate'             => '2025-06-30',
			'max_priority'        => '1',
			'stack'               => 'in-progress',
			'id_nextcloud_card'   => '7',
			'decker_labels'       => array( $this->label_id ),
			'decker_board'        => $this->board_id,
			'assigned_users'      => array( $u1 ),
			'user_date_relations' => wp_json_encode(
				array(
					array(
						'user_id' => $u1,
						'date'    => '2025-06-01',
					),
				)
			),
		);

		( new Decker_Tasks() )->save_meta( $task_id, get_post( $task_id ), true );

		$this->assertEquals( '2025-06-30', get_post_meta( $task_id, 'duedate', true ) );
		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ) );
		$this->assertEquals( 'in-progress', get_post_meta( $task_id, 'stack', true ) );
		$this->assertEquals( '7', get_post_meta( $task_id, 'id_nextcloud_card', true ) );
		$this->assertEquals( array( $u1 ), get_post_meta( $task_id, 'assigned_users', true ) );

		$labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->label_id, $labels );

		$boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->board_id, $boards );

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertEquals(
			array(
				array(
					'user_id' => $u1,
					'date'    => '2025-06-01',
				),
			),
			$relations
		);
	}

	/**
	 * Lock the two UNCONDITIONAL writes: when max_priority and
	 * user_date_relations keys are absent, both are cleared.
	 */
	public function test_save_meta_clears_relations_and_max_priority_when_keys_absent() {
		$task_id = self::factory()->task->create(
			array(
				'board'        => $this->board_id,
				'stack'        => 'to-do',
				'max_priority' => true,
			)
		);
		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => '2025-01-01',
				),
			)
		);
		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ) );

		$_POST = array(
			'decker_task_nonce' => wp_create_nonce( 'save_decker_task' ),
			'post_type'         => 'decker_task',
		);

		( new Decker_Tasks() )->save_meta( $task_id, get_post( $task_id ), true );

		$this->assertSame( '', get_post_meta( $task_id, 'max_priority', true ), 'max_priority must be cleared when key absent.' );
		$this->assertSame( array(), get_post_meta( $task_id, '_user_date_relations', true ), '_user_date_relations must be reset when key absent.' );
	}

	/**
	 * Lock the guard-clause order: no metas change when guards block the save.
	 */
	public function test_save_meta_guards_block_changes() {
		// (a) Missing nonce.
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		update_post_meta( $task_id, 'duedate', '2020-01-01' );

		$_POST = array(
			'post_type' => 'decker_task',
			'stack'     => 'done',
			'duedate'   => '2099-12-31',
		);
		( new Decker_Tasks() )->save_meta( $task_id, get_post( $task_id ), true );
		$this->assertEquals( 'to-do', get_post_meta( $task_id, 'stack', true ) );
		$this->assertEquals( '2020-01-01', get_post_meta( $task_id, 'duedate', true ) );

		// (b) Valid nonce but archived post.
		$archived_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		wp_update_post(
			array(
				'ID'          => $archived_id,
				'post_status' => 'archived',
			)
		);
		update_post_meta( $archived_id, 'duedate', '2020-02-02' );

		$_POST = array(
			'decker_task_nonce' => wp_create_nonce( 'save_decker_task' ),
			'post_type'         => 'decker_task',
			'stack'             => 'done',
			'duedate'           => '2099-12-31',
		);
		( new Decker_Tasks() )->save_meta( $archived_id, get_post( $archived_id ), true );
		$this->assertEquals( 'to-do', get_post_meta( $archived_id, 'stack', true ) );
		$this->assertEquals( '2020-02-02', get_post_meta( $archived_id, 'duedate', true ) );

		// (c) Valid nonce but user without edit cap.
		// Clear $_POST first: the save_post hook fires during factory creation, and
		// leftover $_POST from sub-case (b) (stack=done, valid nonce) would otherwise
		// be written while the privileged user is still current, tainting the task.
		$_POST    = array();
		$cap_task = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		update_post_meta( $cap_task, 'duedate', '2020-03-03' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// Explicitly deny the edit_post meta capability so the guard fires
		// regardless of how default-role capabilities resolve in the suite.
		$deny_edit = function ( $allcaps, $caps ) {
			foreach ( $caps as $cap ) {
				$allcaps[ $cap ] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny_edit, 10, 2 );
		$this->assertFalse( current_user_can( 'edit_post', $cap_task ) );

		$_POST = array(
			'decker_task_nonce' => wp_create_nonce( 'save_decker_task' ),
			'post_type'         => 'decker_task',
			'stack'             => 'done',
			'duedate'           => '2099-12-31',
		);
		( new Decker_Tasks() )->save_meta( $cap_task, get_post( $cap_task ), true );

		remove_filter( 'user_has_cap', $deny_edit, 10 );

		$this->assertEquals( 'to-do', get_post_meta( $cap_task, 'stack', true ) );
		$this->assertEquals( '2020-03-03', get_post_meta( $cap_task, 'duedate', true ) );

		wp_set_current_user( $this->editor );
	}
}
