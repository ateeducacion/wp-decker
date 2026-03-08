<?php
/**
 * Tests for the Decker_AI class REST endpoint.
 *
 * @package Decker
 */

/**
 * Unit tests for the AI text-improvement REST endpoint.
 */
class DeckerAITest extends Decker_Test_Base {

	/** @var WP_REST_Server */
	private $server;

	/** @var int */
	private $subscriber_id;

	/** @var int */
	private $editor_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->editor_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		wp_delete_user( $this->subscriber_id );
		wp_delete_user( $this->editor_id );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Permission / authentication tests
	// -------------------------------------------------------------------------

	/**
	 * Unauthenticated requests should be rejected with 401.
	 */
	public function test_unauthenticated_request_returns_401() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'text', 'Hello world' );
		$request->set_param( 'mode', 'improve' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * A subscriber (has 'read' cap) should pass the permission check.
	 * The request will fail later if no API key is set, but 400/503, not 401/403.
	 */
	public function test_subscriber_passes_permission_check() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'text', 'Hello world' );
		$request->set_param( 'mode', 'improve' );
		$response = $this->server->dispatch( $request );

		// Should not be 401 or 403 — permission check passes.
		$this->assertNotEquals( 401, $response->get_status() );
		$this->assertNotEquals( 403, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Validation tests
	// -------------------------------------------------------------------------

	/**
	 * An invalid mode value should return 400.
	 */
	public function test_invalid_mode_returns_400() {
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'text', 'Hello world' );
		$request->set_param( 'mode', 'invalid_mode_xyz' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * A missing 'text' parameter should return 400.
	 */
	public function test_missing_text_returns_400() {
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'mode', 'improve' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * A missing 'mode' parameter should return 400.
	 */
	public function test_missing_mode_returns_400() {
		wp_set_current_user( $this->editor_id );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'text', 'Hello world' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// API key configuration tests
	// -------------------------------------------------------------------------

	/**
	 * When no API key is configured the endpoint should return 503.
	 */
	public function test_no_api_key_returns_503() {
		wp_set_current_user( $this->editor_id );

		// Ensure no API key is set.
		update_option( 'decker_settings', array() );

		$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_param( 'text', 'Some task description.' );
		$request->set_param( 'mode', 'improve' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 503, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'no_api_key', $data['code'] );
	}

	/**
	 * All valid mode keys should be accepted by the REST schema.
	 */
	public function test_all_valid_modes_pass_schema_validation() {
		wp_set_current_user( $this->editor_id );

		// Ensure no API key so we get a predictable 503 (not a schema 400).
		update_option( 'decker_settings', array() );

		$valid_modes = array( 'improve', 'shorten', 'clarify', 'professionalize', 'proofread' );

		foreach ( $valid_modes as $mode ) {
			$request  = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
			$request->set_param( 'text', 'Sample text' );
			$request->set_param( 'mode', $mode );
			$response = $this->server->dispatch( $request );

			// Should NOT be 400 (which would mean schema rejected the mode).
			$this->assertNotEquals(
				400,
				$response->get_status(),
				"Mode '{$mode}' was unexpectedly rejected by the schema."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Response sanitisation tests (no HTTP call needed — use subclass)
	// -------------------------------------------------------------------------

	/**
	 * The sanitize_response helper strips markdown code fences.
	 */
	public function test_sanitize_response_strips_code_fences() {
		$ai = new Decker_AI_Testable();

		$raw      = "```html\n<p>Hello</p>\n```";
		$expected = '<p>Hello</p>';

		$this->assertEquals( $expected, $ai->expose_sanitize_response( $raw ) );
	}

	/**
	 * sanitize_response trims whitespace.
	 */
	public function test_sanitize_response_trims_whitespace() {
		$ai  = new Decker_AI_Testable();
		$raw = "  \n  <p>Text</p>  \n  ";

		$this->assertEquals( '<p>Text</p>', $ai->expose_sanitize_response( $raw ) );
	}
}

/**
 * Testable subclass that exposes protected methods.
 *
 * @internal Only for use in unit tests.
 */
class Decker_AI_Testable extends Decker_AI {

	/**
	 * Expose the protected sanitize_response method for testing.
	 *
	 * @param string $content Raw content.
	 * @return string Sanitized content.
	 */
	public function expose_sanitize_response( $content ) {
		return $this->sanitize_response( $content );
	}
}
