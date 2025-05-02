<?php
/**
 * Test assigning and unassigning tasks for today.
 *
 * @package Decker
 */
class DeckerTasksAssignTodayTest extends Decker_Test_Base {

	private $task_id;
	private $user_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create a user and set as current user.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$board_id = self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) );

		// Create a task.
		$this->task_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
				'tax_input'    => array(
					'decker_board' => array( $board_id ),
				),
				'meta_input'   => array(
					'stack' => 'to-do',
				),
			)
		);

		// Ensure the task exists.
		$this->assertTrue( get_post( $this->task_id ) instanceof WP_Post, 'Task was not created successfully.' );
	}

	/**
	 * Test direct assignment using add_user_date_relation.
	 */
	public function test_assign_today_direct() {
		$task_instance = new Decker_Tasks();

		// Assign the task for today.
		$task_instance->add_user_date_relation( $this->task_id, $this->user_id );

		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$this->assertCount( 1, $relations );

		$relation = $relations[0];
		$this->assertEquals( $this->user_id, $relation['user_id'] );
		$this->assertEquals( gmdate( 'Y-m-d' ), $relation['date'] );

		// Remove the task for today.
		$task_instance->remove_user_date_relation( $this->task_id, $this->user_id );

		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$this->assertCount( 0, $relations );
	}

	public function test_rest_route_exists() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/decker/v1/tasks/(?P<id>\d+)/mark_relation', $routes, 'REST route not registered.' );
	}

	/**
	 * Test REST API assignment using mark_user_date_relation.
	 */
	public function test_assign_by_rest() {
		$task_instance = new Decker_Tasks();

		// Simulate REST API request.
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/mark_relation' );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_url_params( array( 'id' => $this->task_id ) ); // Explicitly set 'id' parameter

		$response = $task_instance->mark_user_date_relation( $request );

		if ( $response->get_status() !== 200 ) {
			$this->fail( 'REST API response error: ' . json_encode( $response->get_data() ) );
		}

		$this->assertEquals( 200, $response->get_status() );

		// Verify relation is added.
		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$this->assertCount( 1, $relations );

		$relation = $relations[0];
		$this->assertEquals( $this->user_id, $relation['user_id'] );
		$this->assertEquals( gmdate( 'Y-m-d' ), $relation['date'] );
	}

	/**
	 * Test REST API unassignment using unmark_user_date_relation.
	 */
	public function test_unassign_by_rest() {
		$task_instance = new Decker_Tasks();

		// Add initial relation.
		$task_instance->add_user_date_relation( $this->task_id, $this->user_id );

		// Simulate REST API request.
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/unmark_relation' );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_url_params( array( 'id' => $this->task_id ) ); // Explicitly set 'id' parameter

		$response = $task_instance->unmark_user_date_relation( $request );

		if ( $response->get_status() !== 200 ) {
			$this->fail( 'REST API response error: ' . json_encode( $response->get_data() ) );
		}

		$this->assertEquals( 200, $response->get_status() );

		// Verify relation is removed.
		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$this->assertCount( 0, $relations );
	}

	/**
	 * Test handle_save_decker_task function.
	 */
	public function test_handle_save_decker_task() {

		// Temporarily modify the filter to prevent sending response
		add_filter( 'decker_save_task_send_response', '__return_false' );

		// Get a Decker_Tasks with mocked function for "verify_nonce".
		$task_instance = new Decker_Tasks();

		// Generate a valid nonce (though it will be mocked)
		$nonce = wp_create_nonce( 'save_decker_task_nonce' );

		// Simulate AJAX request.
		$_POST = array(
			'task_id'        => $this->task_id,
			'title'          => 'Test Task',
			'description'    => 'Task Description',
			'stack'          => 'to-do',
			'board'          => self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) ),
			'mark_for_today' => true,
			'max_priority'   => true,
			'due_date'       => '1983-02-04',
			'nonce'          => $nonce,
		);

		// Capture JSON response.
		$response_data = $task_instance->handle_save_decker_task();

		// Remove the filter after test
		remove_filter( 'decker_save_task_send_response', '__return_false' );

		$this->assertTrue( $response_data['success'] );
		$this->assertEquals( 'Task saved successfully.', $response_data['message'] );

		$post = get_post( $this->task_id );
		$this->assertEquals( 'Test Task', $post->post_title, 'Task title mismatch.' );
		$this->assertEquals( 'Task Description', $post->post_content, 'Task description mismatch.' );

		$due_date = get_post_meta( $this->task_id, 'duedate', true );
		$this->assertEquals( '1983-02-04', $due_date, 'Task duedate mismatch.' );

		$max_priority = get_post_meta( $this->task_id, 'max_priority', true );
		$this->assertEquals( 1, $max_priority, 'Task max_priority mismatch.' );

		$stack = get_post_meta( $this->task_id, 'stack', true );
		$this->assertEquals( 'to-do', $stack, 'Task stack mismatch.' );

		// Verify relation is added.
		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertIsArray( $relations );
		$this->assertCount( 1, $relations );

		$relation = $relations[0];
		$this->assertEquals( $this->user_id, $relation['user_id'] );
		$this->assertEquals( gmdate( 'Y-m-d' ), $relation['date'] );
	}
}
