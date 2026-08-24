<?php
/**
 * Edge-case integration tests for Decker_Tasks_Rest_Ops.
 *
 * @package Decker
 */

class DeckerTasksRestOpsEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Board term ID.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task post ID.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * REST operations controller.
	 *
	 * @var Decker_Tasks_Rest_Ops
	 */
	private $controller;

	/**
	 * Lock state reader.
	 *
	 * @var Decker_Task_Lock_State
	 */
	private $lock_state;

	/**
	 * Set up fixtures.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->admin_id );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'post_title' => 'REST edge task',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);

		$this->controller = new Decker_Tasks_Rest_Ops( new Decker_Tasks() );
		$this->lock_state = new Decker_Task_Lock_State();
	}

	/**
	 * Clean up fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		wp_delete_user( $this->admin_id );
		wp_delete_user( $this->editor_id );
		parent::tear_down();
	}

	/**
	 * Build a request with the task ID in both URL and regular parameters.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Request route.
	 * @return WP_REST_Request Request object.
	 */
	private function task_request( string $method, string $route ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_url_params( array( 'id' => $this->task_id ) );
		$request->set_param( 'id', $this->task_id );

		return $request;
	}

	/**
	 * Missing identifiers must return a 400 response without changing metadata.
	 *
	 * Both endpoints guard the task ID and the user ID in a single condition, so
	 * each branch is exercised separately.
	 */
	public function test_assign_and_leave_reject_missing_parameters() {
		$missing_task = new WP_REST_Request( 'POST', '/decker/v1/tasks/0/assign' );
		$missing_task->set_url_params( array( 'id' => 0 ) );
		$missing_task->set_param( 'user_id', $this->editor_id );
		$missing_task_response = $this->controller->assign_user_to_task( $missing_task );

		$this->assertSame( 400, $missing_task_response->get_status() );
		$this->assertFalse( $missing_task_response->get_data()['success'] );

		$missing_user          = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/assign' );
		$missing_user_response = $this->controller->assign_user_to_task( $missing_user );

		$this->assertSame( 400, $missing_user_response->get_status() );
		$this->assertFalse( $missing_user_response->get_data()['success'] );

		$leave_missing_task = new WP_REST_Request( 'POST', '/decker/v1/tasks/0/leave' );
		$leave_missing_task->set_url_params( array( 'id' => 0 ) );
		$leave_missing_task->set_param( 'user_id', $this->editor_id );
		$leave_missing_task_response = $this->controller->remove_user_from_task( $leave_missing_task );

		$this->assertSame( 400, $leave_missing_task_response->get_status() );
		$this->assertFalse( $leave_missing_task_response->get_data()['success'] );

		$leave          = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/leave' );
		$leave_response = $this->controller->remove_user_from_task( $leave );

		$this->assertSame( 400, $leave_response->get_status() );
		$this->assertFalse( $leave_response->get_data()['success'] );

		// The factory writes assigned_users through Decker_Task_Writer, so an
		// untouched task holds an empty array rather than an absent meta value.
		$this->assertSame( array(), get_post_meta( $this->task_id, 'assigned_users', true ) );
	}

	/**
	 * Non-task posts must be rejected with 404 and left untouched.
	 */
	public function test_assign_and_leave_reject_non_task_posts() {
		$post_id = self::factory()->post->create();

		$assign = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $post_id . '/assign' );
		$assign->set_url_params( array( 'id' => $post_id ) );
		$assign->set_param( 'user_id', $this->editor_id );
		$assign_response = $this->controller->assign_user_to_task( $assign );

		$this->assertSame( 404, $assign_response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, 'assigned_users', true ) );

		$leave = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $post_id . '/leave' );
		$leave->set_url_params( array( 'id' => $post_id ) );
		$leave->set_param( 'user_id', $this->editor_id );
		$leave_response = $this->controller->remove_user_from_task( $leave );

		$this->assertSame( 404, $leave_response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, 'assigned_users', true ) );
	}

	/**
	 * A scalar legacy assignee value must be normalized before appending a user.
	 */
	public function test_assign_normalizes_scalar_legacy_metadata() {
		update_post_meta( $this->task_id, 'assigned_users', (string) $this->admin_id );

		$request = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/assign' );
		$request->set_param( 'user_id', $this->editor_id );
		$response = $this->controller->assign_user_to_task( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals(
			array( (string) $this->admin_id, $this->editor_id ),
			get_post_meta( $this->task_id, 'assigned_users', true )
		);
	}

	/**
	 * Reassigning an existing user must be idempotent and keep the generation.
	 */
	public function test_duplicate_assign_does_not_emit_hook_or_invalidate_session() {
		update_post_meta( $this->task_id, 'assigned_users', array( $this->editor_id ) );
		$generation = ( new Decker_Task_Locks() )->acquire_lock( $this->task_id, $this->admin_id )['generation'];
		$events     = array();

		$listener = static function ( $task_id, $user_id ) use ( &$events ) {
			$events[] = array( (int) $task_id, (int) $user_id );
		};
		add_action( 'decker_user_assigned', $listener, 10, 2 );

		$request = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/assign' );
		$request->set_param( 'user_id', $this->editor_id );
		$response = $this->controller->assign_user_to_task( $request );

		remove_action( 'decker_user_assigned', $listener, 10 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->editor_id ), get_post_meta( $this->task_id, 'assigned_users', true ) );
		$this->assertSame( array(), $events );
		$this->assertSame( $generation, $this->lock_state->generation( $this->task_id ) );
	}

	/**
	 * Leaving a task without a matching assignment must not rotate the generation.
	 */
	public function test_noop_leave_preserves_assignment_state_and_generation() {
		update_post_meta( $this->task_id, 'assigned_users', array( $this->admin_id ) );
		$generation = ( new Decker_Task_Locks() )->acquire_lock( $this->task_id, $this->admin_id )['generation'];

		$request = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/leave' );
		$request->set_param( 'user_id', $this->editor_id );
		$response = $this->controller->remove_user_from_task( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->admin_id ), get_post_meta( $this->task_id, 'assigned_users', true ) );
		$this->assertSame( $generation, $this->lock_state->generation( $this->task_id ) );
	}

	/**
	 * Removing a real assignment must preserve the remaining user and invalidate sessions.
	 */
	public function test_leave_removes_user_and_invalidates_session() {
		update_post_meta( $this->task_id, 'assigned_users', array( $this->admin_id, $this->editor_id ) );
		$generation = ( new Decker_Task_Locks() )->acquire_lock( $this->task_id, $this->admin_id )['generation'];

		$request = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/leave' );
		$request->set_param( 'user_id', $this->admin_id );
		$response = $this->controller->remove_user_from_task( $request );
		$stored   = get_post_meta( $this->task_id, 'assigned_users', true );

		$this->assertSame( 200, $response->get_status() );
		// Characterization: the endpoint removes with array_diff(), which preserves
		// keys, so the stored value is a sparse array rather than a list. Reindexing
		// it in the endpoint would be an improvement, not a regression.
		$this->assertSame( array( 1 => $this->editor_id ), $stored );
		$this->assertNotSame( $generation, $this->lock_state->generation( $this->task_id ) );
	}

	/**
	 * An empty due-date request is a successful no-op and must not rotate sessions.
	 */
	public function test_due_date_request_without_value_is_a_noop() {
		update_post_meta( $this->task_id, 'duedate', '2026-12-01' );
		$generation = ( new Decker_Task_Locks() )->acquire_lock( $this->task_id, $this->admin_id )['generation'];

		$request  = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/update_due_date' );
		$response = $this->controller->update_task_due_date( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $data['updated_meta'] );
		$this->assertSame( '2026-12-01', get_post_meta( $this->task_id, 'duedate', true ) );
		$this->assertSame( $generation, $this->lock_state->generation( $this->task_id ) );
	}

	/**
	 * Due-date updates must sanitize input, persist it, and rotate the generation.
	 */
	public function test_due_date_update_sanitizes_and_invalidates_session() {
		$generation = ( new Decker_Task_Locks() )->acquire_lock( $this->task_id, $this->admin_id )['generation'];

		$request = $this->task_request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/update_due_date' );
		$request->set_param( 'duedate', " 2026-12-31<script>alert(1)</script> " );
		$response = $this->controller->update_task_due_date( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		// sanitize_text_field() drops the whole <script> block, body included, and
		// trims the surrounding whitespace.
		$this->assertSame( '2026-12-31', $data['updated_meta']['duedate'] );
		$this->assertSame( '2026-12-31', get_post_meta( $this->task_id, 'duedate', true ) );
		$this->assertNotSame( $generation, $this->lock_state->generation( $this->task_id ) );
	}

	/**
	 * Dragging a task in the calendar view must work for the ordinary Decker
	 * users who see that view, not only for administrators. The existing
	 * due-date tests call the controller directly and so never exercised the
	 * route's permission callback.
	 */
	public function test_due_date_route_is_allowed_for_a_non_admin_editor() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/update_due_date' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'duedate', '2027-03-01' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( '2027-03-01', get_post_meta( $this->task_id, 'duedate', true ) );
	}

	/**
	 * The route stays closed to users below the editing bar.
	 */
	public function test_due_date_route_is_forbidden_for_a_subscriber() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/update_due_date' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'duedate', '2027-03-01' );

		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

}
