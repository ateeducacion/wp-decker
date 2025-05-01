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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'in-progress',
				'assigned_users' => array( $this->user_id ),
			)
		);

		// Simulate editing the task via factory update
		$task_id = self::factory()->task->update_object(
			$task_id,
			array(
				'stack' => 'done',
				'assigned_users' => array( $this->user_id ),
			)
		);

		$new_stack = get_post_meta( $task_id, 'stack', true );
		$this->assertEquals( 'done', $new_stack, 'The task was not marked as completed properly after create_or_update_task().' );

		$this->assertEquals( 1, $hook_called, 'The decker_task_completed hook should have been called once via create_or_update_task().' );
	}

	/**
	 * Test hook for stack transition using create_or_update_task().
	 */
	public function test_stack_transition_hook_via_create_or_update_task() {
		$hook_called = 0;

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'to-do',
				'assigned_users' => array( $this->user_id ),
			)
		);

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

		// Simulate editing the task via factory update
		$task_id = self::factory()->task->update_object(
			$task_id,
			array(
				'stack' => 'in-progress',
				'assigned_users' => array( $this->user_id ),
			)
		);

		$new_stack = get_post_meta( $task_id, 'stack', true );
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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'done',
				'assigned_users' => array( $this->user_id ),
			)
		);

		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task_id . '/stack' );
		$request->set_param( 'id', $task_id );
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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'to-do',
				'assigned_users' => array( $this->user_id ),
			)
		);

		// Verify the task was created successfully.
		$this->assertNotWPError( $task_id, 'Failed to create a new task.' );
		$this->assertTrue( get_post( $task_id ) instanceof WP_Post, 'The created task is not a valid post object.' );

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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'done',
				'assigned_users' => array( $this->user_id ),
			)
		);

		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task_id . '/stack' );
		$request->set_param( 'id', $task_id );
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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'done',
				'assigned_users' => array( $this->user_id ),
			)
		);

		// Simulate assigning a user to the task via REST API.
		$task_instance = new Decker_Tasks();
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/assign' );
		$request->set_param( 'id', $task_id );
		$request->set_param( 'user_id', $this->user_id );

		$response = $task_instance->assign_user_to_task( $request );

		// Verify the REST API response.
		$this->assertEquals( 200, $response->get_status(), 'REST API did not return a successful response.' );
		$this->assertTrue( $response->get_data()['success'], 'User assignment was not successful.' );

		// Verify the user is now assigned to the task.
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		$this->assertIsArray( $assigned_users, 'Assigned users should be an array.' );
		$this->assertContains( $this->user_id, $assigned_users, 'User was not assigned to the task.' );

        // The user was already assigned, so the hook should not fire again.
        $this->assertEquals( 0, $hook_called, 'The decker_user_assigned hook should not fire when the user is already assigned.' );

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

		// Create the task.
		$task_id = self::factory()->task->create(
			array(
				'stack' => 'to-do',
				'assigned_users' => array( $this->user_id ),
			)
		);

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

		// Update the task to add two new uses.
		$task_id = self::factory()->task->update_object(
			$task_id,
			array(
				'assigned_users' => $assigned_users,
			)
		);

		// Verify the task was created successfully.
		$this->assertNotWPError( $task_id, 'Failed to create a new task.' );
		$this->assertTrue( get_post( $task_id ) instanceof WP_Post, 'The created task is not a valid post object.' );

		// Verify all hooks were called once for each user.
		$this->assertEquals( 2, $hook_called, 'The decker_user_assigned hook should have been called once for each new user.' );
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

		$task_id = self::factory()->task->create(
			array(
				'stack' => 'to-do',
				'assigned_users' => array( $this->user_id ),
			)
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

		$all_users = array_merge( $existing_users, $new_users );

		$this->assertEquals( $all_users, array( $this->user_id, $user_2, $user_3 ), 'User array does not match expected values.' );

		$task_id = self::factory()->task->update_object(
			$task_id,
			array(
				'assigned_users' => $all_users,
			)
		);

		// Verify the task was updated successfully.
		$this->assertNotWPError( $task_id, 'Failed to update the task.' );
		$this->assertTrue( get_post( $task_id ) instanceof WP_Post, 'The updated task is not a valid post object.' );

		// Verify the hook was called only for the new users.
		$this->assertEquals( count( $new_users ), $hook_called, 'The decker_user_assigned hook should have been called once for each new user.' );
	}



	public function tear_down() {
		parent::tear_down();
		remove_all_actions( 'decker_task_completed' );
		remove_all_actions( 'decker_stack_transition' );
		remove_all_actions( 'decker_user_assigned' );
		remove_all_actions( 'decker_task_updated' );
	}
}
