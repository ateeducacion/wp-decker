<?php
/**
 * Edge case tests for Decker events (sanitize_event_datetime) and
 * the Knowledge Base REST endpoint.
 *
 * @package Decker
 */

/**
 * Edge-case coverage for Decker_Events::sanitize_event_datetime().
 */
class EventsSanitizeDatetimeEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
	}

	// -----------------------------------------------------------------------
	// sanitize_event_datetime – boundary inputs
	// -----------------------------------------------------------------------

	/**
	 * An empty string is returned as-is (representing "no date").
	 */
	public function test_sanitize_event_datetime_returns_empty_for_empty_input() {
		$result = Decker_Events::sanitize_event_datetime( '' );

		$this->assertSame( '', $result );
	}

	/**
	 * A whitespace-only string is normalized to empty.
	 */
	public function test_sanitize_event_datetime_returns_empty_for_whitespace() {
		$result = Decker_Events::sanitize_event_datetime( '   ' );

		$this->assertSame( '', $result );
	}

	/**
	 * An ISO 8601 UTC string is returned normalized to "Y-m-d H:i:s" form.
	 */
	public function test_sanitize_event_datetime_handles_iso_utc_with_t_and_z() {
		$result = Decker_Events::sanitize_event_datetime( '2025-03-15T10:30:00Z' );

		$this->assertSame( '2025-03-15 10:30:00', $result );
	}

	/**
	 * A "Y-m-d H:i:s" string is accepted and returned as-is.
	 */
	public function test_sanitize_event_datetime_accepts_space_separated_datetime() {
		$result = Decker_Events::sanitize_event_datetime( '2025-06-20 09:00:00' );

		$this->assertSame( '2025-06-20 09:00:00', $result );
	}

	/**
	 * A date-only string "Y-m-d" is accepted and returned as-is (all-day).
	 */
	public function test_sanitize_event_datetime_accepts_date_only_format() {
		$result = Decker_Events::sanitize_event_datetime( '2025-12-31' );

		$this->assertSame( '2025-12-31', $result );
	}

	/**
	 * A completely invalid date string returns an empty string.
	 */
	public function test_sanitize_event_datetime_returns_empty_for_invalid_date() {
		$result = Decker_Events::sanitize_event_datetime( 'not-a-date' );

		$this->assertSame( '', $result );
	}

	/**
	 * An ISO 8601 string already without the trailing Z is handled.
	 */
	public function test_sanitize_event_datetime_handles_iso_utc_without_trailing_z() {
		$result = Decker_Events::sanitize_event_datetime( '2025-03-15T10:30:00' );

		$this->assertSame( '2025-03-15 10:30:00', $result );
	}

	/**
	 * A midnight ISO timestamp is preserved correctly.
	 */
	public function test_sanitize_event_datetime_handles_midnight_timestamp() {
		$result = Decker_Events::sanitize_event_datetime( '2025-01-01T00:00:00Z' );

		$this->assertSame( '2025-01-01 00:00:00', $result );
	}
}

/**
 * Edge-case coverage for the Knowledge Base REST endpoints.
 */
class KnowledgeBaseEdgeCasesTest extends WP_Test_REST_TestCase {

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Administrator user.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		do_action( 'init' );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// GET /decker/v1/kb – non-existent article
	// -----------------------------------------------------------------------

	/**
	 * Requesting a KB article with a non-existent ID returns HTTP 404.
	 */
	public function test_get_article_returns_404_for_nonexistent_id() {
		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', 999999 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Requesting a KB article using a non-KB post ID returns HTTP 404.
	 */
	public function test_get_article_returns_404_for_non_kb_post() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', $page_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// POST /decker/v1/kb – missing board on create
	// -----------------------------------------------------------------------

	/**
	 * Creating a KB article without a board returns HTTP 400.
	 */
	public function test_save_article_returns_400_when_board_missing_on_create() {
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'   => 'Article Without Board',
					'content' => 'Some content.',
					// Deliberately omit 'board'.
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// POST /decker/v1/kb – update of a non-existent article
	// -----------------------------------------------------------------------

	/**
	 * Updating a KB article with a non-existent ID returns a non-200 error.
	 */
	public function test_save_article_returns_error_for_nonexistent_id() {
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'id'      => 999999,
					'title'   => 'Ghost Article',
					'content' => 'Some content.',
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertNotSame( 200, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Unauthenticated access
	// -----------------------------------------------------------------------

	/**
	 * An unauthenticated GET to /decker/v1/kb is rejected.
	 */
	public function test_get_article_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', 1 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * An unauthenticated POST to /decker/v1/kb is rejected.
	 */
	public function test_save_article_requires_authentication() {
		wp_set_current_user( 0 );

		$board_id = wp_insert_term( 'Auth Board', 'decker_board' );
		$board_id = is_array( $board_id ) ? $board_id['term_id'] : $board_id;

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'   => 'Anon Article',
					'content' => 'Some content.',
					'board'   => $board_id,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// -----------------------------------------------------------------------
	// Hierarchical structure – invalid parent
	// -----------------------------------------------------------------------

	/**
	 * Creating a KB article with a non-existent parent_id gracefully creates
	 * the article but the parent is set to 0 (WordPress ignores invalid parents
	 * for hierarchical post types by default).
	 */
	public function test_save_article_with_invalid_parent_creates_successfully() {
		wp_set_current_user( $this->admin_id );

		$board_id = wp_insert_term( 'Parent Board', 'decker_board' );
		$board_id = is_array( $board_id ) ? $board_id['term_id'] : $board_id;

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'title'     => 'Orphan Article',
					'content'   => 'Content here.',
					'board'     => $board_id,
					'parent_id' => 999999,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
	}

	// -----------------------------------------------------------------------
	// get_last_editor fallback
	// -----------------------------------------------------------------------

	/**
	 * get_last_editor() falls back to the post author when no last-editor meta
	 * has been stored.
	 */
	public function test_get_last_editor_fallback_when_no_meta() {
		wp_set_current_user( $this->admin_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_author' => $this->admin_id,
				'post_status' => 'publish',
			)
		);
		delete_post_meta( $post_id, 'decker_last_editor' );

		$last_editor = Decker_KB::get_last_editor( $post_id );

		$this->assertSame( $this->admin_id, $last_editor );
	}
}
