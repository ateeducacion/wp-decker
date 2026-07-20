<?php
/**
 * Edge case tests for event date sanitization and Knowledge Base REST routes.
 *
 * @package Decker
 */

/**
 * Edge-case coverage for Decker_Events::sanitize_event_datetime().
 */
class EventsSanitizeDatetimeEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Register plugin types before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
	}

	/**
	 * Return an empty string unchanged.
	 */
	public function test_sanitize_event_datetime_returns_empty_for_empty_input() {
		$this->assertSame( '', Decker_Events::sanitize_event_datetime( '' ) );
	}

	/**
	 * Normalize whitespace-only input to an empty string.
	 */
	public function test_sanitize_event_datetime_returns_empty_for_whitespace() {
		$this->assertSame( '', Decker_Events::sanitize_event_datetime( '   ' ) );
	}

	/**
	 * Preserve an already-normalized ISO 8601 UTC value.
	 */
	public function test_sanitize_event_datetime_preserves_iso_utc_with_z() {
		$value = '2025-03-15T10:30:00Z';

		$this->assertSame( $value, Decker_Events::sanitize_event_datetime( $value ) );
	}

	/**
	 * Accept a space-separated UTC date and time.
	 */
	public function test_sanitize_event_datetime_accepts_space_separated_datetime() {
		$value = '2025-06-20 09:00:00';

		$this->assertSame( $value, Decker_Events::sanitize_event_datetime( $value ) );
	}

	/**
	 * Preserve an all-day date.
	 */
	public function test_sanitize_event_datetime_accepts_date_only_format() {
		$value = '2025-12-31';

		$this->assertSame( $value, Decker_Events::sanitize_event_datetime( $value ) );
	}

	/**
	 * Return an empty string for an invalid date.
	 */
	public function test_sanitize_event_datetime_returns_empty_for_invalid_date() {
		$this->assertSame( '', Decker_Events::sanitize_event_datetime( 'not-a-date' ) );
	}

	/**
	 * Normalize an ISO-like value without a UTC suffix.
	 */
	public function test_sanitize_event_datetime_handles_iso_utc_without_trailing_z() {
		$this->assertSame(
			'2025-03-15 10:30:00',
			Decker_Events::sanitize_event_datetime( '2025-03-15T10:30:00' )
		);
	}

	/**
	 * Preserve an ISO 8601 midnight timestamp.
	 */
	public function test_sanitize_event_datetime_preserves_midnight_timestamp() {
		$value = '2025-01-01T00:00:00Z';

		$this->assertSame( $value, Decker_Events::sanitize_event_datetime( $value ) );
	}
}

/**
 * Edge-case coverage for Knowledge Base REST endpoints.
 */
class KnowledgeBaseEdgeCasesTest extends WP_Test_REST_TestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up REST and user fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
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
	 * Return 404 for a missing article.
	 */
	public function test_get_article_returns_404_for_nonexistent_id() {
		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', 999999 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Return 404 when the requested post is not a KB article.
	 */
	public function test_get_article_returns_404_for_non_kb_post() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', $page_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Require a board when creating an article.
	 */
	public function test_save_article_returns_400_when_board_missing_on_create() {
		$response = $this->dispatch_save(
			array(
				'title'   => 'Article Without Board',
				'content' => 'Some content.',
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Reject an update for a missing article.
	 */
	public function test_save_article_returns_404_for_nonexistent_id() {
		$response = $this->dispatch_save(
			array(
				'id'      => 999999,
				'title'   => 'Ghost Article',
				'content' => 'Some content.',
			)
		);

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Require authentication for reads.
	 */
	public function test_get_article_requires_authentication() {
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', 1 );

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Require authentication for writes.
	 */
	public function test_save_article_requires_authentication() {
		wp_set_current_user( 0 );
		$response = $this->dispatch_save(
			array(
				'title'   => 'Anonymous Article',
				'content' => 'Some content.',
				'board'   => 1,
			)
		);

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * Reject a non-existent parent instead of creating an invalid hierarchy.
	 */
	public function test_save_article_rejects_invalid_parent() {
		wp_set_current_user( $this->admin_id );
		$board = wp_insert_term( 'Parent Board', 'decker_board' );
		$this->assertIsArray( $board );

		$response = $this->dispatch_save(
			array(
				'title'     => 'Orphan Article',
				'content'   => 'Content here.',
				'board'     => $board['term_id'],
				'parent_id' => 999999,
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Fall back to the original author when no last-editor metadata exists.
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
		delete_post_meta( $post_id, '_last_editor' );

		$this->assertSame( $this->admin_id, Decker_KB::get_last_editor( $post_id ) );
	}

	/**
	 * Dispatch a Knowledge Base save request.
	 *
	 * @param array $payload Request payload.
	 * @return WP_REST_Response
	 */
	private function dispatch_save( array $payload ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return rest_get_server()->dispatch( $request );
	}
}
