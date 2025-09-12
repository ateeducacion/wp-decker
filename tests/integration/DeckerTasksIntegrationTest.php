<?php
/**
 * Class DeckerTasksIntegrationTest
 *
 * @package Decker
 */

/**
 * @group decker
 */
class DeckerTasksIntegrationTest extends Decker_Test_Base {

	private $editor;

	private $created_tasks = array();


	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create an editor user
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Set current user as editor right away
		wp_set_current_user( $this->editor );
	}

	public function test_create_data() {

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

        $board_id = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );

		$label1_id = self::factory()->label->create( array( 'name' => 'Test Label 1' ) );
		$label2_id = self::factory()->label->create( array( 'name' => 'Test Label 2' ) );

		// Create a task.
		$task_id = $this->create_task(
			'Fix critical bug',
			'This is a critical bug that needs immediate attention.',
			$board_id,
			array( $label1_id, $label2_id ),
			array( $user_id ),
			'to-do',
			true,
			new DateTime( '1983-02-04' ),
		);
		$this->assertIsInt( $task_id, 'Task creation failed.' );

		$post = get_post( $task_id );

		$this->assertEquals( 'Fix critical bug', $post->post_title, 'Task title mismatch.' );
		$this->assertEquals( 'This is a critical bug that needs immediate attention.', $post->post_content, 'Task description mismatch.' );

		$due_date = get_post_meta( $task_id, 'duedate', true );
		$this->assertEquals( '1983-02-04', $due_date, 'Task duedate mismatch.' );
	}

	/**
	 * Test task creation and menu_order.
	 */
	public function test_task_menu_order() {

        $board_id = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );

		// Create tasks
		$task1_id = $this->create_task(
			'Task 1',
			'Description for Task 1',
			$board_id,
			array(),
			array(),
			'to-do',
			false,
			new DateTime(),
			new DateTime(),
		);

		$task2_id = $this->create_task(
			'Task 2',
			'Description for Task 2',
			$board_id,
			array(),
			array(),
			'to-do',
			false,
			new DateTime(),
			new DateTime(),
		);

		$task3_id = $this->create_task(
			'Task 3',
			'Description for Task 3',
			$board_id,
			array(),
			array(),
			'to-do',
			false,
			new DateTime(),
			new DateTime(),
		);

		// Verify menu_order is correct
		$this->assert_tasks_in_correct_order( array( $task1_id, $task2_id, $task3_id ) );

		// wp_delete_post( $task2_id, true ); // true to delete permanently

		// $this->assert_tasks_in_correct_order( [ $task1_id, $task3_id ] );
	}

	/**
	 * Verify the menu_order for a list of tasks.
	 *
	 * @param array $task_ids An array of task IDs to check.
	 */
	private function assert_tasks_in_correct_order( array $task_ids ) {
		foreach ( $task_ids as $index => $task_id ) {
			// Get the task menu_order.
			$menu_order = get_post_field( 'menu_order', $task_id );

			// Assert that menu_order matches the expected value (1-based index).
			$expected_order = $index + 1;
			$this->assertEquals(
				$expected_order,
				$menu_order,
				"Task ID {$task_id} has incorrect menu_order. Expected {$expected_order}, got {$menu_order}."
			);
		}
	}

	public function test_task_saved_meta() {
        // Create a term for the board.
        $board_id = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );

        // Create labels.
		$label1_id = self::factory()->label->create( array( 'name' => 'Test Label 1' ) );
		$label2_id = self::factory()->label->create( array( 'name' => 'Test Label 2' ) );

        // Create a user to assign to the task.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

        // Define values for the metadata.
		$title          = 'Test Task with Metadata';
		$description    = 'Testing all saved meta fields';
		$stack          = 'to-do';
		$max_priority   = true;
               $duedate        = new DateTime( '2024-12-25' ); // Example date
		$assigned_users = array( $user_id );
		$labels         = array( $label1_id, $label2_id );

        // Create the task.
		$task_id = $this->create_task(
			$title,
			$description,
			$board_id,
			$labels,
			$assigned_users,
			$stack,
			$max_priority,
			$duedate
		);

           // Verify that the task was created correctly.
		$this->assertIsInt( $task_id, 'Task creation failed.' );

         // Verify that the metadata was saved correctly.
		$this->assertEquals( $stack, get_post_meta( $task_id, 'stack', true ), 'Stack meta mismatch.' );
		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ), 'Max priority meta mismatch.' );
		$this->assertEquals( $duedate->format( 'Y-m-d' ), get_post_meta( $task_id, 'duedate', true ), 'Due date meta mismatch.' );
		$this->assertEquals( $assigned_users, get_post_meta( $task_id, 'assigned_users', true ), 'Assigned users meta mismatch.' );

           // Check labels and terms.
		$saved_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
           sort( $saved_labels ); // Ensure that the order doesn't affect the comparison.
		sort( $labels );
		$this->assertEquals( $labels, $saved_labels, 'Labels meta mismatch.' );

		$saved_board = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertEquals( array( $board_id ), $saved_board, 'Board meta mismatch.' );
	}



	public function test_create_and_update_task() {
        $board_id = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );

		// Create a task.
		$task_id = $this->create_task(
			'Initial Task',
			'Initial Description',
			$board_id,
			array(),
			array(),
			'to-do',
			false,
			new DateTime( '1983-02-04' ),
		);

		$this->assertIsInt( $task_id, 'Task creation failed.' );

		// Verify initial data
		$post = get_post( $task_id );
		$this->assertEquals( 'Initial Task', $post->post_title, 'Task title mismatch.' );
		$this->assertEquals( 'Initial Description', $post->post_content, 'Task description mismatch.' );

		$due_date = get_post_meta( $task_id, 'duedate', true );
		$this->assertEquals( '1983-02-04', $due_date, 'Task due date mismatch.' );

		// Update the task.
		$updated_task_id = $this->update_task(
			$task_id,
			'Updated Task',
			'Updated Description',
			$board_id,
			array(),
			array(),
			'in-progress',
			true,
			new DateTime( '2023-12-15' ),
		);

		$this->assertEquals( $task_id, $updated_task_id, 'Task update failed.' );

		// Verify updated data
		$updated_post = get_post( $task_id );
		$this->assertEquals( 'Updated Task', $updated_post->post_title, 'Updated task title mismatch.' );
		$this->assertEquals( 'Updated Description', $updated_post->post_content, 'Updated task description mismatch.' );

		$updated_due_date = get_post_meta( $task_id, 'duedate', true );
		$this->assertEquals( '2023-12-15', $updated_due_date, 'Updated task due date mismatch.' );
	}

	/**
	 * Create a task for the Decker plugin.
	 *
	 * @param string      $title       The title of the task.
	 * @param string|null $description Optional. The description of the task. Default null.
	 * @param int         $board_id    The ID of the board to associate the task with.
	 * @param array       $label_ids   Optional. An array of label IDs to associate with the task. Default empty array.
	 * @param array       $user_ids    Optional. An array of user IDs to assign to the task. Default empty array.
	 * @param string      $stack       The stack for the task ('to-do', 'in-progress', 'done').
	 * @param bool        $max_priority Optional. Whether the task has maximum priority. Default false.
	 * @param DateTime    $due_date     The due date of the task.
	 * @return int|WP_Error The ID of the created task, or WP_Error on failure.
	 */
	public function create_task( string $title, ?string $description, int $board_id, array $label_ids = array(), array $user_ids = array(), string $stack, bool $max_priority, DateTime $due_date ) {
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'The task title is required.', 'decker' ) );
		}

		$task_id = Decker_Tasks::create_or_update_task(
			0, // Create a new task.
			$title,
			$description ?? '',
			$stack,
			$board_id,
			$max_priority,
			$due_date,
			get_current_user_id(),
			get_current_user_id(),
			false,
			$user_ids,
			$label_ids
		);

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		$this->created_tasks[] = $task_id;

		return $task_id;
	}

	/**
	 * Update a task for the Decker plugin.
	 *
	 * @param int         $task_id     The ID of the task to update.
	 * @param string      $title       The title of the task.
	 * @param string|null $description Optional. The description of the task. Default null.
	 * @param int         $board_id    The ID of the board to associate the task with.
	 * @param array       $label_ids   Optional. An array of label IDs to associate with the task. Default empty array.
	 * @param array       $user_ids    Optional. An array of user IDs to assign to the task. Default empty array.
	 * @param string      $stack       The stack for the task ('to-do', 'in-progress', 'done').
	 * @param bool        $max_priority Optional. Whether the task has maximum priority. Default false.
	 * @param DateTime    $due_date     The due date of the task.
	 * @return int|WP_Error The ID of the updated task, or WP_Error on failure.
	 */
	public function update_task( int $task_id, string $title, ?string $description, int $board_id, array $label_ids = array(), array $user_ids = array(), string $stack, bool $max_priority, DateTime $due_date ) {
		$updated_task_id = Decker_Tasks::create_or_update_task(
			$task_id,
			$title,
			$description ?? '',
			$stack,
			$board_id,
			$max_priority,
			$due_date,
			get_current_user_id(),
			get_current_user_id(),
			false,
			$user_ids,
			$label_ids
		);

		if ( is_wp_error( $updated_task_id ) ) {
			return $updated_task_id;
		}

		return $updated_task_id;
	}



	public function tear_down() {

		foreach ( $this->created_tasks as $post_id ) {
			wp_delete_post( $post_id, true ); // true to delete permanently
		}

		// Clean array for next test
		$this->created_tasks = array();

		parent::tear_down();
	}
}
