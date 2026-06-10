<?php
/**
 * Characterization test for Decker_Tasks::register_rest_routes().
 *
 * Pins every route key, HTTP method and per-route capability before the
 * registration is converted to a table-dispatch helper.
 *
 * @package Decker
 */

class DeckerTasksRestRoutesLockInTest extends Decker_Test_Base {

	/**
	 * Board ID.
	 */
	private $board_id;

	/**
	 * Administrator user used to seed fixtures.
	 */
	private $admin;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		// The board factory requires edit_posts to create the term, so seed
		// fixtures as an administrator before tests switch the current user.
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->board_id = self::factory()->board->create();
		$this->assertNotWPError( $this->board_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		wp_delete_user( $this->admin );
		parent::tear_down();
	}

	/**
	 * Lock all routes, their HTTP methods and capability gating.
	 */
	public function test_all_decker_task_routes_registered_with_expected_permissions() {
		$routes = rest_get_server()->get_routes( 'decker/v1' );

		$expected = array(
			'/decker/v1/tasks/(?P<id>\d+)/mark_relation'   => 'POST',
			'/decker/v1/tasks/(?P<id>\d+)/unmark_relation' => 'POST',
			'/decker/v1/tasks/(?P<id>\d+)/order'           => 'PUT',
			'/decker/v1/tasks/(?P<id>\d+)/stack'           => 'PUT',
			'/decker/v1/tasks/(?P<id>\d+)/leave'           => 'POST',
			'/decker/v1/tasks/(?P<id>\d+)/assign'          => 'POST',
			'/decker/v1/fix-order/(?P<board_id>\d+)'       => 'POST',
			'/decker/v1/tasks/(?P<id>\d+)/update_due_date' => 'POST',
			'/decker/v1/tasks/search'                      => 'GET',
			'/decker/v1/tasks/(?P<id>\d+)/clone'           => 'POST',
			'/decker/v1/tasks/(?P<id>\d+)/merge'           => 'POST',
		);

		foreach ( $expected as $route => $method ) {
			$this->assertArrayHasKey( $route, $routes, "Route {$route} must be registered." );

			$methods = array();
			foreach ( $routes[ $route ] as $handler ) {
				$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
			}
			$this->assertContains( $method, $methods, "Route {$route} must accept {$method}." );
		}
	}

	/**
	 * Lock the per-route capability table: subscriber is denied manage_options
	 * routes but the minimum-role assign route is gated by editor role.
	 */
	public function test_route_capabilities_enforced() {
		$task = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// fix-order requires manage_options -> 403 for subscriber.
		$request = new WP_REST_Request( 'POST', '/decker/v1/fix-order/' . $this->board_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );

		// update_due_date requires manage_options -> 403 for subscriber.
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task . '/update_due_date' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );

		// assign requires minimum role (editor) -> denied for subscriber.
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task . '/assign' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'user_id', $subscriber );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );

		// An administrator passes the minimum-role gate on assign.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task . '/assign' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'user_id', $admin );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
	}
}
