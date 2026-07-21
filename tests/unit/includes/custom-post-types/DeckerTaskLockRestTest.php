<?php
/**
 * REST API tests for the Decker task lock endpoints.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskLockRestTest
 */
class DeckerTaskLockRestTest extends Decker_Test_Base {

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
	 * Dispatch a REST request against the lock routes.
	 *
	 * @param string $method  HTTP method.
	 * @param string $route   The route below /decker/v1.
	 * @return WP_REST_Response The dispatched response.
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/decker/v1' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( $params ) {
			$request->set_query_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The lock endpoint reports an unlocked task before anybody edits it.
	 */
	public function test_get_lock_reports_unlocked() {
		$response = $this->dispatch( 'GET', '/tasks/' . $this->task_id . '/lock' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['locked'] );
		$this->assertFalse( $data['owned_by_current_user'] );
	}

	/**
	 * Acquiring the lock over REST marks the current user as owner.
	 */
	public function test_acquire_lock_marks_owner() {
		$response = $this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['owned_by_current_user'] );
		$this->assertFalse( $data['locked'] );
	}

	/**
	 * A second user sees the card as locked and can take it over.
	 */
	public function test_second_user_sees_locked_and_can_take_over() {
		$this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock' );

		wp_set_current_user( $this->user_b );

		$get = $this->dispatch( 'GET', '/tasks/' . $this->task_id . '/lock' )->get_data();
		$this->assertTrue( $get['locked'] );
		$this->assertFalse( $get['owned_by_current_user'] );
		$this->assertTrue( $get['can_take_over'] );
		$this->assertSame( $this->user_a, $get['owner']['id'] );

		// Acquiring must not steal the lock; it reports the conflict with 409.
		$acquire = $this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock' );
		$this->assertSame( 409, $acquire->get_status() );
		$this->assertFalse( $acquire->get_data()['owned_by_current_user'] );

		// Explicit takeover succeeds.
		$takeover = $this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock/takeover' );
		$this->assertSame( 200, $takeover->get_status() );
		$this->assertTrue( $takeover->get_data()['owned_by_current_user'] );
	}

	/**
	 * Releasing the lock only works for the owner.
	 */
	public function test_release_lock() {
		$generation = $this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock' )->get_data()['generation'];

		// A different user cannot release user A's lock.
		wp_set_current_user( $this->user_b );
		$foreign = $this->dispatch( 'DELETE', '/tasks/' . $this->task_id . '/lock' )->get_data();
		$this->assertFalse( $foreign['released'] );

		// The owner must present its session generation to release.
		wp_set_current_user( $this->user_a );
		$without_token = $this->dispatch( 'DELETE', '/tasks/' . $this->task_id . '/lock' )->get_data();
		$this->assertFalse( $without_token['released'] );

		$owner = $this->dispatch(
			'DELETE',
			'/tasks/' . $this->task_id . '/lock',
			array( 'lock_generation' => $generation )
		)->get_data();
		$this->assertTrue( $owner['released'] );
	}

	/**
	 * Users without edit permission cannot reach the lock endpoints.
	 */
	public function test_subscriber_is_forbidden() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$response = $this->dispatch( 'POST', '/tasks/' . $this->task_id . '/lock' );
		$this->assertSame( 403, $response->get_status() );

		wp_delete_user( $subscriber );
	}

	/**
	 * Invalid task IDs are rejected with a 404.
	 */
	public function test_invalid_task_returns_404() {
		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$response = $this->dispatch( 'GET', '/tasks/' . $page_id . '/lock' );

		$this->assertSame( 404, $response->get_status() );
	}
}
