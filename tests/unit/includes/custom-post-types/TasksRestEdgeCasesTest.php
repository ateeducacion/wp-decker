<?php
/**
 * Edge case tests for the task REST endpoint.
 *
 * @package Decker
 */

/**
 * Supplementary REST endpoint edge-case coverage for decker_task.
 */
class TasksRestEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up users and REST routes.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Restore the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Reject unauthenticated task creation.
	 */
	public function test_unauthenticated_task_creation_is_rejected() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_task_create(
			array(
				'title'  => 'Unauthenticated Task',
				'status' => 'publish',
			)
		);

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Prevent a subscriber from creating tasks.
	 */
	public function test_subscriber_cannot_create_task() {
		wp_set_current_user( $this->subscriber_id );
		$response = $this->dispatch_task_create(
			array(
				'title'  => 'Subscriber Task',
				'status' => 'publish',
			)
		);

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Reject an invalid stack value registered through REST metadata schema.
	 */
	public function test_create_task_with_invalid_stack_is_rejected() {
		$response = $this->dispatch_task_create(
			array(
				'title'  => 'Invalid Stack Task',
				'status' => 'publish',
				'meta'   => array( 'stack' => 'not-a-real-stack' ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Return 404 when updating a missing task.
	 */
	public function test_update_nonexistent_task_returns_404() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/999999' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( array( 'title' => 'Updated Title' ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Return 404 when deleting a missing task.
	 */
	public function test_delete_nonexistent_task_returns_404() {
		$request = new WP_REST_Request( 'DELETE', '/wp/v2/tasks/999999' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Reject a task with an empty title.
	 */
	public function test_create_task_with_empty_title_is_rejected() {
		$response = $this->dispatch_task_create(
			array(
				'title'  => '',
				'status' => 'publish',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Persist the maximum-priority flag through REST metadata.
	 */
	public function test_create_task_via_rest_persists_max_priority() {
		$response = $this->dispatch_task_create(
			array(
				'title'  => 'High Priority Task',
				'status' => 'publish',
				'meta'   => array(
					'max_priority' => true,
					'stack'        => 'to-do',
				),
			)
		);
		$data = $response->get_data();

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
		$this->assertNotEmpty( $data['id'] );
		$this->assertTrue( (bool) get_post_meta( $data['id'], 'max_priority', true ) );
	}

	/**
	 * Accept a due date in the past because no future-only rule exists.
	 */
	public function test_create_task_with_past_due_date_is_accepted() {
		$response = $this->dispatch_task_create(
			array(
				'title'  => 'Past Due Task',
				'status' => 'publish',
				'meta'   => array( 'duedate' => '2000-01-01' ),
			)
		);
		$data = $response->get_data();

		$this->assertContains( $response->get_status(), array( 200, 201 ) );
		$this->assertNotEmpty( $data['id'] );
		$this->assertSame( '2000-01-01', get_post_meta( $data['id'], 'duedate', true ) );
	}

	/**
	 * Dispatch a task creation request.
	 *
	 * @param array $payload Request payload.
	 * @return WP_REST_Response
	 */
	private function dispatch_task_create( array $payload ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $payload ) );

		return rest_get_server()->dispatch( $request );
	}
}
