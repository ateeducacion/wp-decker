<?php
/**
 * Integration tests for the "For today" quick action.
 *
 * Covers the Decker_Task_Today_Manager relation service and the
 * PUT /decker/v1/tasks/{id}/today REST endpoint: current-user identity,
 * idempotency, relation preservation, permissions, lock independence,
 * task immutability and absence of revisions.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskTodayQuickActionTest
 */
class DeckerTaskTodayQuickActionTest extends Decker_Test_Base {

	/**
	 * First editor user.
	 *
	 * @var int
	 */
	private $user_a;

	/**
	 * Second editor user.
	 *
	 * @var int
	 */
	private $user_b;

	/**
	 * Board fixture.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Relation service under test.
	 *
	 * @var Decker_Task_Today_Manager
	 */
	private $manager;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->user_a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->user_b = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->user_a );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$this->manager = new Decker_Task_Today_Manager();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->user_a );
		wp_delete_user( $this->user_b );
		parent::tear_down();
	}

	/**
	 * Get today's date in the same format used by the relation storage.
	 *
	 * @return string The Y-m-d date.
	 */
	private function today(): string {
		return ( new DateTime() )->format( 'Y-m-d' );
	}

	/**
	 * Dispatch a request to the today endpoint.
	 *
	 * @param int   $task_id The task ID.
	 * @param array $body    The JSON body.
	 * @return WP_REST_Response The dispatched response.
	 */
	private function dispatch_today( int $task_id, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task_id . '/today' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Read the raw relations array for a task.
	 *
	 * @param int $task_id The task ID.
	 * @return array The relations.
	 */
	private function relations( int $task_id ): array {
		$raw = get_post_meta( $task_id, '_user_date_relations', true );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * The today endpoint is registered.
	 */
	public function test_today_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/decker/v1/tasks/(?P<id>\d+)/today', $routes );
	}

	/**
	 * Marking uses the authenticated current user, not a client-supplied id.
	 */
	public function test_mark_uses_current_user_identity() {
		$response = $this->dispatch_today( $this->task_id, array( 'marked' => true ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['marked'] );
		$this->assertTrue( $data['changed'] );
		$this->assertSame( $this->user_a, $data['user_id'] );

		$this->assertTrue( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );
		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_b ) );
	}

	/**
	 * The endpoint accepts `marked` from the query string, so it works even on
	 * hosts that drop or do not parse the PUT request body.
	 */
	public function test_mark_accepts_query_string_parameter() {
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $this->task_id . '/today' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_query_params( array( 'marked' => 'true' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['marked'] );
		$this->assertTrue( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );
	}

	/**
	 * A client-supplied user_id is rejected and never marks another user.
	 */
	public function test_client_supplied_user_id_is_rejected() {
		$response = $this->dispatch_today(
			$this->task_id,
			array(
				'marked'  => true,
				'user_id' => $this->user_b,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_b ) );
		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );
	}

	/**
	 * Marking is idempotent: a second mark reports no change.
	 */
	public function test_mark_is_idempotent() {
		$first  = $this->dispatch_today( $this->task_id, array( 'marked' => true ) )->get_data();
		$second = $this->dispatch_today( $this->task_id, array( 'marked' => true ) )->get_data();

		$this->assertTrue( $first['changed'] );
		$this->assertFalse( $second['changed'] );
		$this->assertTrue( $second['marked'] );

		$mine = array_filter(
			$this->relations( $this->task_id ),
			function ( $relation ) {
				return (int) $relation['user_id'] === $this->user_a && $relation['date'] === $this->today();
			}
		);
		$this->assertCount( 1, $mine );
	}

	/**
	 * Unmarking removes only the current user's today relation.
	 */
	public function test_unmark_removes_only_current_relation() {
		$this->manager->mark_for_today( $this->task_id, $this->user_a );

		$response = $this->dispatch_today( $this->task_id, array( 'marked' => false ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['marked'] );
		$this->assertTrue( $data['changed'] );
		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );
	}

	/**
	 * Unmarking is idempotent.
	 */
	public function test_unmark_is_idempotent() {
		$first  = $this->dispatch_today( $this->task_id, array( 'marked' => false ) )->get_data();
		$second = $this->dispatch_today( $this->task_id, array( 'marked' => false ) )->get_data();

		$this->assertSame( 200, 200 );
		$this->assertFalse( $first['changed'] );
		$this->assertFalse( $second['changed'] );
		$this->assertFalse( $second['marked'] );
	}

	/**
	 * Changing one user's relation preserves the other user's relation.
	 */
	public function test_preserves_other_users_relations() {
		$this->manager->mark_for_today( $this->task_id, $this->user_a );
		$this->manager->mark_for_today( $this->task_id, $this->user_b );

		// User A unmarks themselves.
		$this->dispatch_today( $this->task_id, array( 'marked' => false ) );

		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );
		$this->assertTrue( $this->manager->is_marked_for_today( $this->task_id, $this->user_b ) );
	}

	/**
	 * Changing today's relation preserves relations for other dates.
	 */
	public function test_preserves_other_dates() {
		$yesterday = ( new DateTime( '-1 day' ) )->format( 'Y-m-d' );
		update_post_meta(
			$this->task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->user_a,
					'date'    => $yesterday,
				),
				array(
					'user_id' => $this->user_a,
					'date'    => $this->today(),
				),
			)
		);

		$this->dispatch_today( $this->task_id, array( 'marked' => false ) );

		$dates = wp_list_pluck( $this->relations( $this->task_id ), 'date' );
		$this->assertContains( $yesterday, $dates );
		$this->assertNotContains( $this->today(), $dates );
	}

	/**
	 * Invalid tasks are rejected with 404.
	 */
	public function test_invalid_task_returns_404() {
		$this->assertSame( 404, $this->dispatch_today( 999999, array( 'marked' => true ) )->get_status() );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 404, $this->dispatch_today( $page_id, array( 'marked' => true ) )->get_status() );
	}

	/**
	 * A malformed marked parameter is rejected with 400.
	 */
	public function test_malformed_marked_is_rejected() {
		$response = $this->dispatch_today( $this->task_id, array( 'marked' => 'banana' ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Users without edit permission are forbidden.
	 */
	public function test_subscriber_is_forbidden() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertSame( 403, $this->dispatch_today( $this->task_id, array( 'marked' => true ) )->get_status() );

		wp_delete_user( $subscriber );
	}

	/**
	 * Unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_is_rejected() {
		wp_set_current_user( 0 );

		$status = $this->dispatch_today( $this->task_id, array( 'marked' => true ) )->get_status();
		$this->assertContains( $status, array( 401, 403 ) );
	}

	/**
	 * Archived tasks are rejected with a state conflict.
	 */
	public function test_archived_task_is_rejected() {
		wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			)
		);

		$this->assertSame( 409, $this->dispatch_today( $this->task_id, array( 'marked' => true ) )->get_status() );
	}

	/**
	 * The quick action works while another user owns the task edit lock and
	 * never touches that lock.
	 */
	public function test_action_is_independent_of_edit_lock() {
		$locks = new Decker_Task_Locks();
		$locks->acquire_lock( $this->task_id, $this->user_a );
		$lock_before = get_post_meta( $this->task_id, '_edit_lock', true );

		// User B performs the quick action.
		wp_set_current_user( $this->user_b );
		$response = $this->dispatch_today( $this->task_id, array( 'marked' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->manager->is_marked_for_today( $this->task_id, $this->user_b ) );

		// User A still owns the unchanged lock.
		$this->assertSame( $lock_before, get_post_meta( $this->task_id, '_edit_lock', true ) );
		$info = $locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertTrue( $info['owned_by_current_user'] );
	}

	/**
	 * The quick action leaves every shared task field untouched.
	 */
	public function test_shared_task_fields_are_immutable() {
		$before = get_post( $this->task_id );
		$meta_keys = array( 'stack', 'max_priority', 'duedate', 'responsable', 'hidden', 'assigned_users' );
		$meta_before = array();
		foreach ( $meta_keys as $key ) {
			$meta_before[ $key ] = get_post_meta( $this->task_id, $key, true );
		}
		$board_before  = wp_get_post_terms( $this->task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$labels_before = wp_get_post_terms( $this->task_id, 'decker_label', array( 'fields' => 'ids' ) );

		$this->dispatch_today( $this->task_id, array( 'marked' => true ) );

		$after = get_post( $this->task_id );
		$this->assertSame( $before->post_title, $after->post_title );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_excerpt, $after->post_excerpt );
		$this->assertSame( $before->post_status, $after->post_status );
		$this->assertSame( (int) $before->post_author, (int) $after->post_author );
		$this->assertSame( $before->post_date, $after->post_date );
		$this->assertSame( $before->post_modified, $after->post_modified );
		$this->assertSame( (int) $before->menu_order, (int) $after->menu_order );

		foreach ( $meta_keys as $key ) {
			$this->assertSame( $meta_before[ $key ], get_post_meta( $this->task_id, $key, true ), "Meta {$key} changed." );
		}
		$this->assertSame( $board_before, wp_get_post_terms( $this->task_id, 'decker_board', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $labels_before, wp_get_post_terms( $this->task_id, 'decker_label', array( 'fields' => 'ids' ) ) );
	}

	/**
	 * The quick action does not create a post revision.
	 */
	public function test_no_revision_is_created() {
		$revisions_before = count( wp_get_post_revisions( $this->task_id ) );

		$this->dispatch_today( $this->task_id, array( 'marked' => true ) );

		$this->assertSame( $revisions_before, count( wp_get_post_revisions( $this->task_id ) ) );
	}

	/**
	 * The manager reports and toggles state correctly in isolation.
	 */
	public function test_manager_set_today_state_reports_changed() {
		$this->assertFalse( $this->manager->is_marked_for_today( $this->task_id, $this->user_a ) );

		$marked = $this->manager->set_today_state( $this->task_id, $this->user_a, true );
		$this->assertTrue( $marked['changed'] );
		$this->assertTrue( $marked['marked'] );

		$again = $this->manager->set_today_state( $this->task_id, $this->user_a, true );
		$this->assertFalse( $again['changed'] );

		$removed = $this->manager->set_today_state( $this->task_id, $this->user_a, false );
		$this->assertTrue( $removed['changed'] );
		$this->assertFalse( $removed['marked'] );
	}
}
