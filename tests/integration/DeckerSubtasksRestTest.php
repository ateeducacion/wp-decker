<?php
/**
 * Integration tests for the subtask REST routes.
 *
 * Covers the write boundary: the feature flag, the object capability, the
 * archived and stale-form rejections, the per-field sanitization applied to
 * whatever a client submits, and the promotion of an item into a real task.
 *
 * @package Decker
 */

/**
 * Class DeckerSubtasksRestTest
 */
class DeckerSubtasksRestTest extends Decker_Test_Base {

	/**
	 * Task owner and editor.
	 *
	 * @var int
	 */
	private $user_a;

	/**
	 * A second editor, not on the task.
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
	 * Lock manager.
	 *
	 * @var Decker_Task_Locks
	 */
	private $locks;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'decker_settings', array( 'subtasks_enabled' => '1' ) );

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->user_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_b = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->user_a );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'post_title'  => 'Parent task',
				'board'       => $this->board_id,
				'stack'       => 'to-do',
				'responsable' => $this->user_a,
				'duedate'     => '2026-12-01',
			)
		);

		$this->locks = new Decker_Task_Locks();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		update_option( 'decker_settings', array() );
		parent::tear_down();
	}

	/**
	 * Dispatch a list replacement.
	 *
	 * @param array       $items      The items to submit.
	 * @param string|null $generation The editor session generation, when any.
	 * @param int|null    $task_id    The task to write to, defaulting to the fixture.
	 * @return WP_REST_Response The response.
	 */
	private function put_items( array $items, $generation = null, $task_id = null ) {
		$task_id = $task_id ?? $this->task_id;
		$request = new WP_REST_Request( 'PUT', "/decker/v1/tasks/{$task_id}/subtasks" );
		$request->set_header( 'content-type', 'application/json' );

		$body = array( 'items' => $items );
		if ( null !== $generation ) {
			$body['lock_generation'] = $generation;
		}
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Dispatch a promotion.
	 *
	 * @param int $item_id The item to promote.
	 * @return WP_REST_Response The response.
	 */
	private function convert_item( int $item_id ) {
		$request = new WP_REST_Request( 'POST', "/decker/v1/tasks/{$this->task_id}/subtasks/{$item_id}/convert" );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The routes exist once the feature is registered.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/decker/v1/tasks/(?P<id>\d+)/subtasks', $routes );
		$this->assertArrayHasKey( '/decker/v1/tasks/(?P<id>\d+)/subtasks/(?P<item_id>\d+)/convert', $routes );
	}

	/**
	 * A valid write stores the list and returns it with its progress.
	 */
	public function test_replace_stores_the_list_and_returns_progress() {
		$response = $this->put_items(
			array(
				array(
					'title'  => 'Locate the appearances',
					'status' => 'done',
				),
				array( 'title' => 'Replace them' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 1, $data['progress']['done'] );
		$this->assertSame( 2, $data['progress']['total'] );
		$this->assertSame( 50, $data['progress']['percent'] );

		$this->assertCount( 2, Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * The stored list is the sanitized one, not what the client sent.
	 */
	public function test_submitted_values_are_sanitized() {
		$response = $this->put_items(
			array(
				array(
					'title'    => '  <script>alert(1)</script>Step  ',
					'status'   => 'blocked',
					'assignee' => $this->user_b,
					'duedate'  => 'whenever',
				),
			)
		);

		$item = $response->get_data()['items'][0];

		$this->assertStringNotContainsString( '<script>', $item['title'] );
		$this->assertSame( 'to-do', $item['status'] );
		$this->assertSame( 0, $item['assignee'], 'A user who is not on the task cannot own a subtask.' );
		$this->assertSame( '', $item['duedate'] );
	}

	/**
	 * A list over the cap is refused outright rather than silently truncated.
	 */
	public function test_too_many_items_are_refused() {
		$items = array();
		for ( $i = 0; $i <= Decker_Subtasks::MAX_ITEMS; $i++ ) {
			$items[] = array( 'title' => 'Step ' . $i );
		}

		$response = $this->put_items( $items );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'decker_subtasks_too_many', $response->get_data()['code'] );
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * The routes are closed while the feature is off.
	 */
	public function test_write_is_refused_when_the_feature_is_disabled() {
		update_option( 'decker_settings', array() );

		$response = $this->put_items( array( array( 'title' => 'Step' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'decker_subtasks_disabled', $response->get_data()['code'] );
	}

	/**
	 * A user without edit rights on the task is refused.
	 */
	public function test_user_without_edit_capability_is_refused() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->put_items( array( array( 'title' => 'Step' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * A missing task yields a 404, not a 403.
	 */
	public function test_unknown_task_is_not_found() {
		$response = $this->put_items( array( array( 'title' => 'Step' ) ), null, 999999 );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * An archived card is read-only, subtasks included.
	 */
	public function test_archived_task_is_refused() {
		wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			)
		);

		$response = $this->put_items( array( array( 'title' => 'Step' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'decker_task_archived', $response->get_data()['code'] );
	}

	/**
	 * Another user holding the active lock blocks the write.
	 */
	public function test_write_is_refused_while_another_user_holds_the_lock() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		wp_set_current_user( $this->user_b );
		$response = $this->put_items( array( array( 'title' => 'Step' ) ) );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * A form holding a superseded generation is rejected, which is what stops
	 * a second tab of the same user from overwriting the newer list.
	 */
	public function test_stale_generation_is_refused() {
		$lock = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertNotWPError( $lock );

		$stale = $lock['generation'];
		$this->locks->invalidate_sessions( $this->task_id );

		$response = $this->put_items( array( array( 'title' => 'From the stale tab' ) ), $stale );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * The current session's generation is accepted.
	 */
	public function test_current_generation_is_accepted() {
		$lock = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		$response = $this->put_items( array( array( 'title' => 'From the open tab' ) ), $lock['generation'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * Promoting an item creates a task carrying the item's own fields.
	 */
	public function test_convert_creates_a_task_from_the_item() {
		$this->put_items(
			array(
				array(
					'title'    => 'Becomes its own task',
					'status'   => 'in-progress',
					'assignee' => $this->user_a,
					'duedate'  => '2026-11-20',
				),
			)
		);

		$response = $this->convert_item( 1 );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$new_task_id = $data['task_id'];
		$this->assertSame( 'decker_task', get_post_type( $new_task_id ) );
		$this->assertSame( 'Becomes its own task', get_post( $new_task_id )->post_title );
		$this->assertSame( 'in-progress', get_post_meta( $new_task_id, 'stack', true ) );
		$this->assertSame( '2026-11-20', get_post_meta( $new_task_id, 'duedate', true ) );
		$this->assertSame( (string) $this->user_a, (string) get_post_meta( $new_task_id, 'responsable', true ) );

		$board_terms = wp_get_post_terms( $new_task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertSame( array( $this->board_id ), $board_terms );

		// The item stays, linked to the task it became.
		$items = Decker_Subtasks::get( $this->task_id );
		$this->assertCount( 1, $items );
		$this->assertSame( (int) $new_task_id, $items[0]['task_id'] );
	}

	/**
	 * An item with no date of its own inherits the parent's.
	 */
	public function test_convert_falls_back_to_the_parent_due_date() {
		$this->put_items( array( array( 'title' => 'No date of its own' ) ) );

		$new_task_id = $this->convert_item( 1 )->get_data()['task_id'];

		$this->assertSame( '2026-12-01', get_post_meta( $new_task_id, 'duedate', true ) );
	}

	/**
	 * An item cannot be promoted twice.
	 */
	public function test_convert_is_refused_for_an_already_promoted_item() {
		$this->put_items( array( array( 'title' => 'Step' ) ) );
		$this->convert_item( 1 );

		$response = $this->convert_item( 1 );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'decker_subtask_already_converted', $response->get_data()['code'] );
	}

	/**
	 * Promoting an item that is not in the list is a 404.
	 */
	public function test_convert_of_an_unknown_item_is_not_found() {
		$this->put_items( array( array( 'title' => 'Step' ) ) );

		$this->assertSame( 404, $this->convert_item( 99 )->get_status() );
	}

	/**
	 * A later list write cannot forge or drop a promotion link.
	 */
	public function test_promotion_links_are_not_client_writable() {
		$this->put_items( array( array( 'title' => 'Step' ) ) );
		$new_task_id = $this->convert_item( 1 )->get_data()['task_id'];

		$response = $this->put_items(
			array(
				array(
					'id'      => 1,
					'title'   => 'Step',
					'task_id' => 0,
				),
				array(
					'id'      => 2,
					'title'   => 'Forged link',
					'task_id' => $new_task_id,
				),
			)
		);

		$items = $response->get_data()['items'];

		$this->assertSame( (int) $new_task_id, $items[0]['task_id'], 'The stored link must survive a list write.' );
		$this->assertSame( 0, $items[1]['task_id'], 'A client must not be able to invent a link.' );
	}
}
