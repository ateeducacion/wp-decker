<?php
/**
 * Class HooksTest
 *
 * @package Decker
 */

/**
 * HooksTest test case.
 */
class HooksTest extends Decker_Test_Base {

	private $user_id;
	private $task_id;
	private $decker_tasks;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->decker_tasks = new Decker_Tasks();

		// Create a user and set as the current user.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// Create a board and a task.
		$board_id = self::factory()->board->create();
	}

	/**
	 * Test hook for task completion using create_or_update_task().
	 * Assuming create_or_update_task() is public/static and can be called directly.
	 */
	public function test_task_completion_hook_via_create_or_update_task() {
		$hook_called = 0;
		add_action(
			'decker_task_completed',
			function ( $task_id, $stack ) use ( &$hook_called ) {

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$this->assertEquals( 'done', $stack );
				$hook_called++;
			},
			10,
			2
		);

		// Call create_or_update_task() from the plugin.
		// First, get current values:
		$title       = 'Title';
		$description = 'Description';
		$stack       = 'in-progress';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array( $this->user_id );
		$labels         = array();

		$this->task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		// This call should trigger the hooks internally if your logic is implemented as discussed.
		$this->task_id = Decker_Tasks::create_or_update_task(
			$this->task_id,
			$title,
			$description,
			'done',
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		$new_stack = get_post_meta( $this->task_id, 'stack', true );
		$this->assertEquals( 'done', $new_stack, 'The task was not marked as completed properly after create_or_update_task().' );

		$this->assertEquals( 1, $hook_called, 'The decker_task_completed hook should have been called once via create_or_update_task().' );
	}

	/**
	 * Test hook for stack transition using create_or_update_task().
	 */
	public function test_stack_transition_hook_via_create_or_update_task() {
		$hook_called = 0;
		add_action(
			'decker_stack_transition',
			function ( $task_id, $old_stack, $new_stack ) use ( &$hook_called ) {

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$this->assertEquals( 'to-do', $old_stack );
				$this->assertEquals( 'in-progress', $new_stack );
				$hook_called++;
			},
			10,
			3
		);

		$title       = 'Title';
		$description = 'Description';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array( $this->user_id );
		$labels         = array();

		$this->task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			'to-do',
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		$this->task_id = Decker_Tasks::create_or_update_task(
			$this->task_id,
			$title,
			$description,
			'in-progress',
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		$new_stack = get_post_meta( $this->task_id, 'stack', true );
		$this->assertEquals( 'in-progress', $new_stack, 'The task did not transition to the new stack properly after create_or_update_task().' );

		$this->assertEquals( 1, $hook_called, 'The decker_stack_transition hook should have been called once via create_or_update_task().' );
	}

	/**
	 * Test hook for task completed via update_task_stack_and_order().
	 */
	public function test_task_completed_hook() {
		$hook_called = 0;

		add_action(
			'decker_task_completed',
			function ( $task_id, $stack ) use ( &$hook_called ) {

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$this->assertEquals( 'done', $stack );
				$hook_called++;
			},
			10,
			2
		);

		// Call create_or_update_task() from the plugin.
		// First, get current values:
		$title       = 'Title';
		$description = 'Description';
		$stack       = 'done';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array( $this->user_id );
		$labels         = array();

		// This call should trigger the hooks internally if your logic is implemented as discussed.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $this->task_id . '/stack' );
		$request->set_param( 'id', $this->task_id );
		$request->set_param( 'board_id', self::factory()->board->create() );
		$request->set_param( 'source_stack', 'to-do' );
		$request->set_param( 'target_stack', 'done' );
		$request->set_param( 'source_order', 1 );
		$request->set_param( 'target_order', 2 );

		$response = $this->decker_tasks->update_task_stack_and_order( $request );

		// Verify the response is successful.
		$this->assertEquals( 200, $response->get_status(), 'Failed to update task stack and order.' );
		$this->assertTrue( $response->get_data()['success'], 'Task update was not successful.' );

		// Verify the hook was called.
		$this->assertEquals( 1, $hook_called, 'The decker_task_completed hook should have been called once.' );
	}

	/**
	 * Test hook for task creation.
	 */
	public function test_task_created_hook() {
		$hook_called = 0;

		add_action(
			'decker_task_created',
			function ( $task_id ) use ( &$hook_called ) {
				// Ensure the task ID is valid.
				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );
				$hook_called++;
			},
			10,
			1
		);

		// Data for creating a new task.
		$title       = 'New Task';
		$description = 'This is a new task created for testing.';
		$stack       = 'to-do';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array();
		$labels         = array();

		// Call create_or_update_task() to create a new task.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0, // Pass 0 to create a new task.
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		// Verify the task was created successfully.
		$this->assertNotWPError( $this->task_id, 'Failed to create a new task.' );
		$this->assertTrue( get_post( $this->task_id ) instanceof WP_Post, 'The created task is not a valid post object.' );

		// Verify the hook was called.
		$this->assertEquals( 1, $hook_called, 'The decker_task_created hook should have been called once.' );
	}


	/**
	 * Test hook for task changed stack via update_task_stack_and_order().
	 */
	public function test_task_changed_stack_hook() {
		$hook_called = 0;

		add_action(
			'decker_stack_transition',
			function ( $task_id, $source_stack, $target_stack ) use ( &$hook_called ) {

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$hook_called++;
			},
			10,
			3
		);

		// Call create_or_update_task() from the plugin.
		// First, get current values:
		$title       = 'Title';
		$description = 'Description';
		$stack       = 'done';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array( $this->user_id );
		$labels         = array();

		// This call should trigger the hooks internally if your logic is implemented as discussed.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $this->task_id . '/stack' );
		$request->set_param( 'id', $this->task_id );
		$request->set_param( 'board_id', self::factory()->board->create() );
		$request->set_param( 'source_stack', 'to-do' );
		$request->set_param( 'target_stack', 'in-progress' );
		$request->set_param( 'source_order', 1 );
		$request->set_param( 'target_order', 2 );

		$response = $this->decker_tasks->update_task_stack_and_order( $request );

		// Verify the response is successful.
		$this->assertEquals( 200, $response->get_status(), 'Failed to update task stack and order.' );
		$this->assertTrue( $response->get_data()['success'], 'Task update was not successful.' );

		// Verify the hook was called.
		$this->assertEquals( 1, $hook_called, 'The decker_stack_transition hook should have been called once.' );
	}


	/**
	 * Test hook for user assignment.
	 */
	public function test_user_assignment_hook() {
		$hook_called = 0;
		add_action(
			'decker_user_assigned',
			function ( $task_id, $user_id ) use ( &$hook_called ) {

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$this->assertEquals( $this->user_id, $user_id, 'The user ID passed to the user assignment hook is incorrect.' );
				$hook_called++;
			},
			10,
			2
		);

		// First, get current values:
		$title       = 'Title';
		$description = 'Description';
		$stack       = 'done';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$assigned_users = array( $this->user_id );
		$labels         = array();

		// This call should trigger the hooks internally if your logic is implemented as discussed.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		// Simulate assigning a user to the task via REST API.
		$task_instance = new Decker_Tasks();
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/assign' );
		$request->set_param( 'id', $this->task_id );
		$request->set_param( 'user_id', $this->user_id );

		$response = $task_instance->assign_user_to_task( $request );

		// Verify the REST API response.
		$this->assertEquals( 200, $response->get_status(), 'REST API did not return a successful response.' );
		$this->assertTrue( $response->get_data()['success'], 'User assignment was not successful.' );

		// Verify the user is now assigned to the task.
		$assigned_users = get_post_meta( $this->task_id, 'assigned_users', true );
		$this->assertIsArray( $assigned_users, 'Assigned users should be an array.' );
		$this->assertContains( $this->user_id, $assigned_users, 'User was not assigned to the task.' );

		// Assert that the hook was called exactly once.
		$this->assertEquals( 1, $hook_called, 'The decker_user_assigned hook should have been called once.' );
	}


	/**
	 * Test hook for task creation with multiple users.
	 */
	public function test_task_created_with_multiple_users_hook() {
		$hook_called = 0;

		// Create additional users.
		$user_2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_3 = self::factory()->user->create( array( 'role' => 'author' ) );
		$assigned_users = array( $this->user_id, $user_2, $user_3 );

		add_action(
			'decker_user_assigned',
			function ( $task_id, $user_id ) use ( &$hook_called, $assigned_users ) {
				$this->assertTrue( in_array( $user_id, $assigned_users ), 'The user ID is not in the assigned users list.' );

				$this->assertIsInt( $task_id, 'The task ID should be an integer.' );
				$this->assertNotEmpty( $task_id, 'The task ID should not be empty.' );
				$this->assertGreaterThan( 0, $task_id, 'The task ID should be greater than 0.' );

				$hook_called++;
			},
			10,
			2
		);

		// Data for creating a new task.
		$title       = 'Task with Multiple Users';
		$description = 'This task is assigned to multiple users.';
		$stack       = 'to-do';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$labels         = array();

		// Call create_or_update_task() to create a new task.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0, // Pass 0 to create a new task.
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		// Verify the task was created successfully.
		$this->assertNotWPError( $this->task_id, 'Failed to create a new task.' );
		$this->assertTrue( get_post( $this->task_id ) instanceof WP_Post, 'The created task is not a valid post object.' );

		// Verify all hooks were called once for each user.
		$this->assertEquals( count( $assigned_users ), $hook_called, 'The decker_user_assigned hook should have been called once for each user.' );
	}

	/**
	 * Test modifying a task to add multiple users and verify hooks.
	 */
	public function test_modify_task_with_new_users_hook() {
		$hook_called = 0;

		// Add additional users.
		$user_2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user_3 = self::factory()->user->create( array( 'role' => 'author' ) );
		$existing_users = array( $this->user_id );
		$new_users = array( $user_2, $user_3 );

		// Data for creating a new task.
		$title       = 'Task with Multiple Users';
		$description = 'This task is assigned to multiple users.';
		$stack       = 'to-do';
		$board       = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$labels         = array();

		// Call create_or_update_task() to create a new task.
		$this->task_id = Decker_Tasks::create_or_update_task(
			0, // Pass 0 to create a new task.
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			array( $author ),
			$labels
		);

		// Set it after the creation and before the update
		add_action(
			'decker_user_assigned',
			function ( $task_id, $user_id ) use ( &$hook_called, $new_users ) {

				$this->assertTrue( in_array( $user_id, $new_users ), 'The hook was called for a user that was not newly assigned.' );
				$hook_called++;
			},
			10,
			2
		);

		// Modify the task to add new users.
		$title        = 'New Task';
		$description  = 'This is a new task created for testing.';
		$stack        = 'to-do';
		$board        = self::factory()->board->create();
		$max_priority = false;
		$duedate      = null;
		$author       = $this->user_id;
		$labels       = array();

		$all_users = array_merge( $existing_users, $new_users );

		$this->assertEquals( $all_users, array( $this->user_id, $user_2, $user_3 ), 'User array does not match expected values.' );

		$this->task_id = Decker_Tasks::create_or_update_task(
			$this->task_id,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$all_users,
			$labels
		);

		// Verify the task was updated successfully.
		$this->assertNotWPError( $this->task_id, 'Failed to update the task.' );
		$this->assertTrue( get_post( $this->task_id ) instanceof WP_Post, 'The updated task is not a valid post object.' );

		// Verify the hook was called only for the new users.
		$this->assertEquals( count( $new_users ), $hook_called, 'The decker_user_assigned hook should have been called once for each new user.' );
	}



	public function tear_down() {
		parent::tear_down();
		remove_all_actions( 'decker_task_completed' );
		remove_all_actions( 'decker_stack_transition' );
		remove_all_actions( 'decker_user_assigned' );
		remove_all_actions( 'decker_task_updated' );
		wp_delete_post( $this->task_id, true );
	}
}
