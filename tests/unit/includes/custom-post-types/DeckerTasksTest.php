<?php
/**
 * Class Test_Decker_Tasks
 *
 * @package Decker
 */

class DeckerTasksTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;
	private $task_id;
	private $label_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that post types and taxonomies are registered
		do_action( 'init' );

		// Create an editor user using WordPress factory
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		// Create a board using our custom factory
		$this->board_id = self::factory()->board->create();

		// Create a test label using our custom factory
		$this->label_id = self::factory()->label->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		if ( $this->task_id ) {
			wp_delete_post( $this->task_id, true );
		}
		if ( $this->board_id ) {
			wp_delete_term( $this->board_id, 'decker_board' );
		}
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Test that an editor can create a task using our custom factory.
	 */
	public function test_editor_can_create_task() {
		wp_set_current_user( $this->editor );

		// Create a task using our custom factory with all available fields
		$task_result = self::factory()->task->create(
			array(
				'post_title'     => 'Test Task',
				'post_content'   => 'Task description',
				'post_author'    => $this->editor,
				'author'         => $this->editor,
				'board'          => $this->board_id,
				'stack'          => 'to-do',
				'max_priority'   => false,
				'duedate'        => null,
				'assigned_users' => array( $this->editor ),
				'labels'         => array( $this->label_id ),
			)
		);

		// Verify task creation was successful
		$this->assertNotWPError( $task_result, 'The task should be created successfully.' );
		$this->task_id = $task_result;

		// Verify basic task properties
		$task = get_post( $this->task_id );
		$this->assertEquals( 'Test Task', $task->post_title, 'The task title should match.' );
		$this->assertEquals( 'Task description', $task->post_content, 'The task description should match.' );
		$this->assertEquals( $this->editor, $task->post_author, 'The task author should match.' );

		// Verify taxonomy assignments
		$assigned_boards = wp_get_post_terms( $this->task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->board_id, $assigned_boards, 'The board should be assigned to the task.' );

		$assigned_labels = wp_get_post_terms( $this->task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->label_id, $assigned_labels, 'The label should be assigned to the task.' );

		// Verify meta fields
		$stack = get_post_meta( $this->task_id, 'stack', true );
		$this->assertEquals( 'to-do', $stack, 'The stack should be set to to-do.' );

		$assigned_users = get_post_meta( $this->task_id, 'assigned_users', true );
		$this->assertContains( $this->editor, $assigned_users, 'The editor should be assigned to the task.' );
	}

	/**
	 * Test that tasks can be assigned to multiple boards and labels.
	 */
	public function test_assign_multiple_boards_and_labels_to_task() {
		wp_set_current_user( $this->editor );

		// Create additional boards using our custom factory
		$board_ids = array( $this->board_id );
		for ( $i = 0; $i < 2; $i++ ) {
			$board_ids[] = self::factory()->board->create();
		}

		// Create additional labels using our custom factory
		$label_ids = array( $this->label_id );
		for ( $i = 0; $i < 2; $i++ ) {
			$label_ids[] = self::factory()->label->create();
		}

		// Create a task with multiple boards and labels using our custom factory
		$task_result = self::factory()->task->create(
			array(
				'post_title'   => 'Task with Multiple Terms',
				'post_content' => 'Task with multiple boards and labels',
				'post_author'  => $this->editor,
				'board'        => $board_ids[0], // Primary board
				'stack'        => 'to-do',
				'labels'       => $label_ids,
			)
		);

		$this->assertNotWPError( $task_result, 'The task should be created successfully.' );
		$task_id = $task_result;

		// Verify terms are assigned
		$assigned_boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$assigned_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );

		// Verify primary board assignment
		$this->assertContains( $board_ids[0], $assigned_boards, 'The primary board should be assigned to the task.' );

		// Verify all labels are assigned
		foreach ( $label_ids as $label_id ) {
			$this->assertContains( $label_id, $assigned_labels, "Label {$label_id} should be assigned to the task." );
		}

		// Verify the number of assigned terms
		$this->assertCount( count( $label_ids ), $assigned_labels, 'All labels should be assigned to the task.' );
	}

	/**
	 * Test that tasks are ordered correctly when created, archived, or deleted.
	 */
	public function test_task_ordering() {

		wp_set_current_user( $this->editor );

		$board_id = self::factory()->board->create();

		// Create tasks with different menu orders.

		$task1_id = self::factory()->task->create(
			array(
				'board' => $board_id,
			)
		);

		$task2_id = self::factory()->task->create(
			array(
				'board' => $board_id,
			)
		);

		// Verify order.
		$tasks = get_posts(
			array(
				'post_type'  => 'decker_task',
				'orderby'    => 'menu_order',
				'order'      => 'ASC',
				'fields'     => 'ids',
				'tax_query'  => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_id,
					),
				),
				'meta_query' => array(
					array(
						'key'     => 'stack',
						'value'   => 'to-do',
						'compare' => '=',
					),
				),
			)
		);

		$this->assertEquals( array( $task1_id, $task2_id ), $tasks, 'Tasks should be ordered by menu_order.' );

		// Delete a task and verify order.
		wp_delete_post( $task1_id );
		$tasks = get_posts(
			array(
				'post_type'  => 'decker_task',
				'orderby'    => 'menu_order',
				'order'      => 'ASC',
				'fields'     => 'ids',
				'tax_query'  => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_id,
					),
				),
				'meta_query' => array(
					array(
						'key'     => 'stack',
						'value'   => 'to-do',
						'compare' => '=',
					),
				),
			)
		);

		$this->assertEquals( array( $task2_id ), $tasks, 'Remaining tasks should be ordered correctly after deletion.' );
	}

	/**
	 * Test that a user-date relation can be created and removed.
	 */
	public function test_user_date_relation() {

		wp_set_current_user( $this->editor );

		// Create a task.
		$task_id = self::factory()->task->create();

		$this->assertNotWPError( $task_id, 'Task creation failed.' );

		// Create user and date for relation.
		$user_id = $this->factory->user->create();
		$date    = '2024-11-30';

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
