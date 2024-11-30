<?php
/**
 * Class Test_Decker_Tasks
 *
 * @package Decker
 */

class Test_Decker_Tasks extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that post types and taxonomies are registered.
		do_action( 'init' );

		// Create roles for testing.
		add_role(
			'test_editor',
			'Test Editor',
			array(
				'read'       => true,
				'edit_posts' => true,
			)
		);

		add_role(
			'test_subscriber',
			'Test Subscriber',
			array(
				'read' => true,
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_role( 'test_editor' );
		remove_role( 'test_subscriber' );

		parent::tear_down();
	}

	/**
	 * Test that an editor can create a task.
	 */
	public function test_editor_can_create_task() {
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		wp_set_current_user( $editor->ID );

		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create a task.
		$task_id = wp_insert_post(
			array(
				'post_title'   => 'Test Task',
				'post_type'    => 'decker_task',
				'post_status'  => 'publish',
				'meta_input'   => array(
					'stack' => 'to-do',
				),
			)
		);

		$this->assertNotWPError( $task_id, 'The task should be created successfully.' );
		$this->assertEquals( 'Test Task', get_post( $task_id )->post_title, 'The task title should match.' );
	}

	/**
	 * Test that boards and labels can be assigned to a task.
	 */
	public function test_assign_boards_and_labels_to_task() {
		// Create terms for boards and labels.

		// error_log( print_r( wp_insert_term( 'Board 1', 'decker_board' ), true ) );

		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		wp_set_current_user( $editor->ID );

		$board_id = wp_insert_term( 'Board 1', 'decker_board' )['term_id'];
		$label_id = wp_insert_term( 'Label 1', 'decker_label' )['term_id'];

		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create a task and assign the terms.
		$task_id = wp_insert_post(
			array(
				'post_title'   => 'Task with Terms',
				'post_type'    => 'decker_task',
				'post_status'  => 'publish',
				'tax_input'    => array(
					'decker_board' => array( $board_id ),
					'decker_label' => array( $label_id ),
				),
			)
		);

		$this->assertNotWPError( $task_id, 'The task should be created successfully.' );

		// Verify terms are assigned.
		$assigned_boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$assigned_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );

		$this->assertContains( $board_id, $assigned_boards, 'The board should be assigned to the task.' );
		$this->assertContains( $label_id, $assigned_labels, 'The label should be assigned to the task.' );
	}

	/**
	 * Test that tasks are ordered correctly when created, archived, or deleted.
	 */
	public function test_task_ordering() {

		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create tasks with different menu orders.
		$task1_id = wp_insert_post(
			array(
				'post_title'   => 'Task 1',
				'post_type'    => 'decker_task',
				'post_status'  => 'publish',
				'menu_order'   => 1,
			)
		);

		$task2_id = wp_insert_post(
			array(
				'post_title'   => 'Task 2',
				'post_type'    => 'decker_task',
				'post_status'  => 'publish',
				'menu_order'   => 2,
			)
		);

		// Verify order.
		$tasks = get_posts(
			array(
				'post_type'   => 'decker_task',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);

		$this->assertEquals( array( $task1_id, $task2_id ), $tasks, 'Tasks should be ordered by menu_order.' );

		// Delete a task and verify order.
		wp_delete_post( $task1_id );
		$tasks = get_posts(
			array(
				'post_type'   => 'decker_task',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);

		$this->assertEquals( array( $task2_id ), $tasks, 'Remaining tasks should be ordered correctly after deletion.' );
	}

	/**
	 * Test that a user-date relation can be created and removed.
	 */
	public function test_user_date_relation() {
		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create a task.
		$task_id = wp_insert_post(
			array(
				'post_title'  => 'Task with Relation',
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $task_id, 'Task creation failed.' );

		// Create user and date for relation.
		$user_id = $this->factory->user->create();
		$date = '2024-11-30';

		// Add a user-date relation.
		$relation = array(
			array(
				'user_id' => $user_id,
				'date'    => $date,
			),
		);

		update_post_meta( $task_id, '_user_date_relations', $relation );

		// Verify the relation exists.
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations, 'The relation should be an array.' );
		$this->assertNotEmpty( $relations, 'The relation should exist.' );
		$this->assertEquals( $user_id, $relations[0]['user_id'], 'The user ID should match.' );
		$this->assertEquals( $date, $relations[0]['date'], 'The date should match.' );

		// Remove the relation.
		delete_post_meta( $task_id, '_user_date_relations' );

		// Verify the relation has been removed.
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertEmpty( $relations, 'The relation should be removed.' );
	}
}
