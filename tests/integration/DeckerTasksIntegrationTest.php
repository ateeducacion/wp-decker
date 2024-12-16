<?php
/**
 * Class DeckerTasksIntegrationTest
 *
 * @package Decker
 */

/**
 * @group decker
 */
class DeckerTasksIntegrationTest extends WP_UnitTestCase {

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

		$board_id = self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) );

		$label1_id = self::factory()->term->create( array( 'taxonomy' => 'decker_label' ) );
		$label2_id = self::factory()->term->create( array( 'taxonomy' => 'decker_label' ) );

		// Create a task.
		$task_id = $this->create_task(
			'Fix critical bug',
			'This is a critical bug that needs immediate attention.',
			$board_id,
			array( $label1_id, $label2_id ),
			array( $user_id ),
			'to-do',
			true
		);
		$this->assertIsInt( $task_id, 'Task creation failed.' );
	}


	// public function test_asign_user_to_task() {

	// $user_id = self::factory()->user->create( array( 'role' => 'editor' ));

	// $board_id = self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) );

	// Create a task.
	// $task_id = $this->create_task(
	// 'Fix critical bug',
	// 'This is a critical bug that needs immediate attention.',
	// $board_id,
	// [ ],
	// [ $user_id ],
	// 'to-do',
	// true
	// );

	// $task = Task($task_id);

	// $task->get

	// $this->assertIsInt( $task_id, 'Task creation failed.' );
	// }
	// }



	/**
	 * Test task creation and menu_order.
	 */
	public function test_task_menu_order() {

		$board_id = self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) );

		// Create tasks
		$task1_id = $this->create_task(
			'Task 1',
			'Description for Task 1',
			$board_id,
			array(),
			array(),
			'to-do',
			false
		);

		$task2_id = $this->create_task(
			'Task 2',
			'Description for Task 2',
			$board_id,
			array(),
			array(),
			'to-do',
			false
		);

		$task3_id = $this->create_task(
			'Task 3',
			'Description for Task 3',
			$board_id,
			array(),
			array(),
			'to-do',
			false
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
	 * @return int|WP_Error The ID of the created task, or WP_Error on failure.
	 */
	public function create_task( string $title, ?string $description, int $board_id, array $label_ids = array(), array $user_ids = array(), string $stack, bool $max_priority = false ) {
		// Validate required fields.
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_title', __( 'The task title is required.', 'decker' ) );
		}
		if ( empty( $stack ) ) {
			return new WP_Error( 'missing_stack', __( 'The task stack is required.', 'decker' ) );
		}
		if ( $board_id <= 0 || ! term_exists( $board_id, 'decker_board' ) ) {
			return new WP_Error( 'invalid_board', __( 'The board is required and must exist.', 'decker' ) );
		}

		// Create the task using the Decker_Tasks class method.
		$task_id = Decker_Tasks::create_or_update_task(
			0, // Create a new task.
			$title,
			$description ?? '',
			$stack,
			$board_id,
			$max_priority,
			null, // No due date.
			get_current_user_id(),
			$user_ids,
			$label_ids,
			new DateTime(), // Creation date.
			false, // Not archived.
			0 // No next_cloud.
		);

		if ( is_wp_error( $task_id ) ) {
			return $task_id; // Return the error.
		}

		// Store the created task ID for cleanup
		$this->created_posts[] = $task_id;

		return $task_id;
	}

	public function tear_down() {

		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true ); // true to delete permanently
		}

		// Clean array for next test
		$this->created_posts = array();

		parent::tear_down();
	}
}
