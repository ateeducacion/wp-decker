<?php
/**
 * Regression tests for the case-sensitive REST authorization bypass.
 *
 * WordPress matches REST routes case-insensitively (WP_REST_Server::match_request_to_handler()
 * compiles every registered route with the `i` modifier), while the plugin's
 * `rest_pre_dispatch` guards compared the requested route with case-sensitive
 * string functions. A request for `/wp/v2/Decker_Kb` therefore reached the core
 * controller with the plugin guard skipped.
 *
 * These tests dispatch real requests through a real WP_REST_Server with the
 * plugin's hooks registered, for each confirmed case variant.
 *
 * @package Decker
 */

class DeckerRestRouteCaseBypassTest extends Decker_Test_Base {

	/**
	 * REST server used to dispatch the requests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Administrator used to build the fixtures.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber without `edit_posts`.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Editor with `edit_posts`.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Published KB article.
	 *
	 * @var int
	 */
	private $kb_id;

	/**
	 * Draft KB article, used as a control.
	 *
	 * @var int
	 */
	private $kb_draft_id;

	/**
	 * Published task.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Published event.
	 *
	 * @var int
	 */
	private $event_id;

	/**
	 * Approved comment on the protected task.
	 *
	 * @var int
	 */
	private $task_comment_id;

	/**
	 * Public post used as an unrelated control.
	 *
	 * @var int
	 */
	private $public_post_id;

	/**
	 * Approved comment on the public post.
	 *
	 * @var int
	 */
	private $public_comment_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );
		do_action( 'init' );

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin_id );

		$board_id = self::factory()->board->create(
			array(
				'name'  => 'Route Case Board',
				'color' => '#0099ff',
			)
		);

		$this->task_id = self::factory()->task->create(
			array(
				'post_title' => 'Confidential Task Title',
				'board'      => $board_id,
				'stack'      => 'to-do',
			)
		);

		$this->kb_id = self::factory()->post->create(
			array(
				'post_type'      => 'decker_kb',
				'post_status'    => 'publish',
				'post_title'     => 'Confidential KB Title',
				'post_content'   => 'Confidential KB body.',
				'comment_status' => 'open',
			)
		);

		$this->kb_draft_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_status'  => 'draft',
				'post_title'   => 'Draft KB Title',
				'post_content' => 'Draft KB body.',
			)
		);

		$this->event_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_event',
				'post_status'  => 'publish',
				'post_title'   => 'Confidential Event Title',
				'post_content' => 'Confidential event body.',
			)
		);

		$this->public_post_id = self::factory()->post->create(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => 'Public Post Title',
				'comment_status' => 'open',
			)
		);

		$this->task_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'comment_content'  => 'Confidential task comment.',
				'comment_approved' => 1,
			)
		);

		$this->public_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->public_post_id,
				'comment_content'  => 'Public comment.',
				'comment_approved' => 1,
			)
		);

		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Build the case variants of a `/wp/v2/<base>` route.
	 *
	 * @param string $base   Canonical REST base, e.g. `decker_kb`.
	 * @param string $suffix Optional route suffix, e.g. `/123`.
	 * @return array<string, string> Variant label => route.
	 */
	private function route_variants( $base, $suffix = '' ) {
		return array(
			'canonical'      => '/wp/v2/' . $base . $suffix,
			'mixed case'     => '/wp/v2/' . ucwords( $base, '_' ) . $suffix,
			'upper case'     => '/wp/v2/' . strtoupper( $base ) . $suffix,
			'namespace case' => '/WP/V2/' . $base . $suffix,
		);
	}

	/**
	 * Dispatch a GET request and return the response.
	 *
	 * @param string $route Route to request.
	 * @return WP_REST_Response
	 */
	private function get( $route ) {
		return $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * Assert that a response is a denial and carries no resource payload.
	 *
	 * @param WP_REST_Response $response Dispatched response.
	 * @param string           $route    Route requested, for the failure message.
	 * @param string           $secret   Text that must not appear in the payload.
	 */
	private function assertDeniedWithoutPayload( $response, $route, $secret ) {
		$status = $response->get_status();

		$this->assertContains(
			$status,
			array( 401, 403 ),
			sprintf( 'Route %s should be denied, got HTTP %d.', $route, $status )
		);

		$this->assertStringNotContainsString(
			$secret,
			wp_json_encode( $response->get_data() ),
			sprintf( 'Route %s leaked protected content.', $route )
		);
	}

	/**
	 * Assert that the route actually resolved to a handler.
	 *
	 * Guards the suite against a typo silently turning a bypass test into a 404.
	 *
	 * @param WP_REST_Response $response Dispatched response.
	 * @param string           $route    Route requested, for the failure message.
	 */
	private function assertRouteMatched( $response, $route ) {
		$this->assertNotEquals(
			404,
			$response->get_status(),
			sprintf( 'Route %s did not match any handler; the test would prove nothing.', $route )
		);
	}

	/**
	 * Anonymous users must not read KB articles through any case variant.
	 */
	public function test_anonymous_cannot_read_kb_through_case_variants() {
		foreach ( $this->route_variants( 'decker_kb' ) as $label => $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential KB Title' );
		}

		foreach ( $this->route_variants( 'decker_kb', '/' . $this->kb_id ) as $label => $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential KB Title' );
		}
	}

	/**
	 * Anonymous users must not read tasks through any case variant.
	 */
	public function test_anonymous_cannot_read_tasks_through_case_variants() {
		foreach ( $this->route_variants( 'tasks' ) as $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential Task Title' );
		}

		foreach ( $this->route_variants( 'tasks', '/' . $this->task_id ) as $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential Task Title' );
		}
	}

	/**
	 * Anonymous users must not read events through any case variant.
	 */
	public function test_anonymous_cannot_read_events_through_case_variants() {
		foreach ( $this->route_variants( 'decker_event' ) as $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential Event Title' );
		}

		foreach ( $this->route_variants( 'decker_event', '/' . $this->event_id ) as $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential Event Title' );
		}
	}

	/**
	 * Anonymous users must not read a protected comment through any case variant.
	 */
	public function test_anonymous_cannot_read_protected_comment_through_case_variants() {
		foreach ( $this->route_variants( 'comments', '/' . $this->task_comment_id ) as $route ) {
			$response = $this->get( $route );
			$this->assertRouteMatched( $response, $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Confidential task comment.' );
		}
	}

	/**
	 * Anonymous users must not create a comment on a protected post through a case variant.
	 */
	public function test_anonymous_cannot_create_protected_comment_through_case_variants() {
		add_filter( 'rest_allow_anonymous_comments', '__return_true' );

		foreach ( $this->route_variants( 'comments' ) as $route ) {
			$request = new WP_REST_Request( 'POST', $route );
			$request->set_param( 'post', $this->task_id );
			$request->set_param( 'content', 'Injected comment.' );
			$request->set_param( 'author_name', 'Anon' );
			$request->set_param( 'author_email', 'anon@example.com' );

			$response = $this->server->dispatch( $request );

			$this->assertRouteMatched( $response, $route );
			$this->assertContains(
				$response->get_status(),
				array( 401, 403 ),
				sprintf( 'Route %s allowed anonymous comment creation on a protected post.', $route )
			);
		}

		remove_filter( 'rest_allow_anonymous_comments', '__return_true' );

		$this->assertCount(
			1,
			get_comments( array( 'post_id' => $this->task_id ) ),
			'An anonymous comment was persisted on the protected task.'
		);
	}

	/**
	 * A logged-in user without `edit_posts` stays denied on the CPT endpoints.
	 */
	public function test_subscriber_denied_on_protected_cpts_through_case_variants() {
		wp_set_current_user( $this->subscriber_id );

		$cases = array(
			'decker_kb'    => 'Confidential KB Title',
			'tasks'        => 'Confidential Task Title',
			'decker_event' => 'Confidential Event Title',
		);

		foreach ( $cases as $base => $secret ) {
			foreach ( $this->route_variants( $base ) as $route ) {
				$response = $this->get( $route );
				$this->assertRouteMatched( $response, $route );
				$this->assertDeniedWithoutPayload( $response, $route, $secret );
			}
		}
	}

	/**
	 * Draft KB articles stay hidden from anonymous users on every variant.
	 */
	public function test_draft_kb_never_exposed_through_case_variants() {
		foreach ( $this->route_variants( 'decker_kb', '/' . $this->kb_draft_id ) as $route ) {
			$response = $this->get( $route );
			$this->assertDeniedWithoutPayload( $response, $route, 'Draft KB Title' );
		}
	}

	/**
	 * A user with `edit_posts` keeps full access on the canonical routes.
	 */
	public function test_authorized_user_keeps_access() {
		wp_set_current_user( $this->editor_id );
		$this->assertTrue( current_user_can( 'edit_posts' ) );

		$response = $this->get( '/wp/v2/decker_kb/' . $this->kb_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Confidential KB Title', $response->get_data()['title']['raw'] ?? $response->get_data()['title']['rendered'] );

		$response = $this->get( '/wp/v2/tasks/' . $this->task_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $this->task_id, $response->get_data()['id'] );

		$response = $this->get( '/wp/v2/decker_event/' . $this->event_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $this->event_id, $response->get_data()['id'] );

		$response = $this->get( '/wp/v2/comments/' . $this->task_comment_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertStringContainsString( 'Confidential task comment.', $response->get_data()['content']['rendered'] );
	}

	/**
	 * A user with `edit_posts` also keeps access through the mixed-case variants,
	 * so the fix denies by capability rather than by route spelling.
	 */
	public function test_authorized_user_keeps_access_on_case_variants() {
		wp_set_current_user( $this->editor_id );

		foreach ( $this->route_variants( 'decker_kb', '/' . $this->kb_id ) as $route ) {
			$response = $this->get( $route );
			$this->assertEquals(
				200,
				$response->get_status(),
				sprintf( 'Authorized user was denied on %s.', $route )
			);
			$this->assertEquals( $this->kb_id, $response->get_data()['id'] );
		}
	}

	/**
	 * An authorized user can still write to the protected CPTs.
	 */
	public function test_authorized_user_can_still_write() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_kb/' . $this->kb_id );
		$request->set_param( 'title', 'Renamed KB Title' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Renamed KB Title', get_post( $this->kb_id )->post_title );
	}

	/**
	 * Unrelated public resources keep working for anonymous users.
	 */
	public function test_public_resources_still_readable_anonymously() {
		$response = $this->get( '/wp/v2/posts/' . $this->public_post_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertStringContainsString( 'Public Post Title', $response->get_data()['title']['rendered'] );

		$response = $this->get( '/wp/v2/comments/' . $this->public_comment_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertStringContainsString( 'Public comment.', $response->get_data()['content']['rendered'] );

		$response = $this->get( '/wp/v2/Posts/' . $this->public_post_id );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertStringContainsString( 'Public Post Title', $response->get_data()['title']['rendered'] );
	}

	/**
	 * The comment collection keeps excluding protected post types on every variant.
	 */
	public function test_comment_collection_excludes_protected_comments_on_case_variants() {
		foreach ( $this->route_variants( 'comments' ) as $route ) {
			$response = $this->get( $route );
			$this->assertEquals( 200, $response->get_status(), sprintf( 'Route %s did not return a collection.', $route ) );

			$ids = wp_list_pluck( $response->get_data(), 'id' );
			$this->assertNotContains(
				$this->task_comment_id,
				$ids,
				sprintf( 'Route %s exposed a protected comment in the collection.', $route )
			);
			$this->assertContains(
				$this->public_comment_id,
				$ids,
				sprintf( 'Route %s dropped a public comment from the collection.', $route )
			);
		}
	}
}
