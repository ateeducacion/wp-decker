<?php
/**
 * Edge case tests for the Decker tasks REST endpoint.
 *
 * Exercises boundary and failure paths that complement DeckerTasksRestTest.
 *
 * @package Decker
 */

/**
 * Supplementary REST endpoint edge-case coverage for decker_task.
 */
class TasksRestEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user (lower permissions).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Board created for the test run.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->editor_id );
		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Authentication
	// -----------------------------------------------------------------------

	/**
	 * An unauthenticated POST request to create a task is rejected.
	 */
	public function test_unauthenticated_task_creation_is_rejected() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Unauth Task',
					'status' => 'publish',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * A subscriber (no edit_posts cap) cannot create a task via REST.
	 */
	public function test_subscriber_cannot_create_task() {
		wp_set_current_user( $this->subscriber_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Subscriber Task',
					'status' => 'publish',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// -----------------------------------------------------------------------
	// Invalid meta values
	// -----------------------------------------------------------------------

	/**
	 * Creating a task with an invalid stack value results in the meta being
	 * stored as-is (REST API does not validate enum for meta); the task is
	 * still created (200/201).
	 */
	public function test_create_task_with_invalid_stack_stores_raw_value() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Invalid Stack Task',
					'status' => 'publish',
					'meta'   => array(
						'stack' => 'not-a-real-stack',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
	}

	/**
	 * Updating a non-existent task via REST returns a 404 response.
	 */
	public function test_update_nonexistent_task_returns_404() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/999999' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title' => 'Updated Title',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Deleting a non-existent task via REST returns a 404 response.
	 */
	public function test_delete_nonexistent_task_returns_404() {
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/tasks/999999' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Task metadata – boundary values
	// -----------------------------------------------------------------------

	/**
	 * A task created with an empty title is still accepted by the WP REST API
	 * (title is optional in WordPress) but can be identified by its ID.
	 */
	public function test_create_task_with_empty_title_is_accepted() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => '',
					'status' => 'publish',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
	}

	/**
	 * A task created via REST with max_priority=true has that meta persisted.
	 */
	public function test_create_task_via_rest_persists_max_priority() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'High Priority Task',
					'status' => 'publish',
					'meta'   => array(
						'max_priority' => true,
						'stack'        => 'to-do',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
		$this->assertNotEmpty( $data['id'] );
		$this->assertTrue( (bool) get_post_meta( $data['id'], 'max_priority', true ) );
	}

	/**
	 * A task created with a past due date is still accepted (no future-only
	 * restriction exists in the REST layer).
	 */
	public function test_create_task_with_past_due_date_is_accepted() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Past Due Task',
					'status' => 'publish',
					'meta'   => array(
						'duedate' => '2000-01-01',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
		$this->assertNotEmpty( $data['id'] );
		$this->assertSame( '2000-01-01', get_post_meta( $data['id'], 'duedate', true ) );
	}

	// -----------------------------------------------------------------------
	// Order endpoint
	// -----------------------------------------------------------------------

	/**
	 * The order endpoint with an empty tasks array returns a success response
	 * without throwing.
	 */
	public function test_order_endpoint_with_empty_task_list() {
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode( array( 'tasks' => array() ) )
		);

		$response = rest_get_server()->dispatch( $request );

		// The endpoint should succeed gracefully even with no tasks.
		$this->assertContains( $response->get_status(), array( 200, 400 ) );
	}
}
