<?php
/**
 * Edge case tests for Decker_AI_Manager.
 *
 * Covers boundary and failure paths that complement DeckerAIManagerTest.
 *
 * @package Decker
 */

/**
 * Supplementary edge-case coverage for the AI manager REST endpoint.
 */
class AIManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user created for the test run.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );

		// Default settings: AI enabled with Gemini API provider.
		update_option(
			'decker_settings',
			array(
				'ai_enabled'           => '1',
				'ai_provider'          => Decker_AI_Manager::PROVIDER_GEMINI_API,
				'ai_api_key'           => 'test-api-key',
				'ai_model'             => Decker_AI_Manager::DEFAULT_GEMINI_MODEL,
				'minimum_user_profile' => 'editor',
			)
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// AI disabled
	// -----------------------------------------------------------------------

	/**
	 * When ai_enabled is '0', the endpoint returns HTTP 400.
	 */
	public function test_improve_returns_400_when_ai_disabled() {
		update_option(
			'decker_settings',
			array(
				'ai_enabled'           => '0',
				'ai_provider'          => Decker_AI_Manager::PROVIDER_GEMINI_API,
				'ai_api_key'           => 'test-api-key',
				'ai_model'             => Decker_AI_Manager::DEFAULT_GEMINI_MODEL,
				'minimum_user_profile' => 'editor',
			)
		);

		$request = $this->build_improve_request();
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'disabled', $response->get_data()['message'] );
	}

	/**
	 * When ai_enabled is absent from settings, the endpoint returns HTTP 400.
	 */
	public function test_improve_returns_400_when_ai_enabled_key_missing() {
		update_option(
			'decker_settings',
			array(
				'ai_provider'          => Decker_AI_Manager::PROVIDER_GEMINI_API,
				'ai_api_key'           => 'test-api-key',
				'minimum_user_profile' => 'editor',
			)
		);

		$request  = $this->build_improve_request();
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Browser provider – endpoint not needed
	// -----------------------------------------------------------------------

	/**
	 * When the browser Gemini Nano provider is selected, the server endpoint
	 * is not needed and returns HTTP 400 with a clear message.
	 */
	public function test_improve_returns_400_for_browser_provider() {
		update_option(
			'decker_settings',
			array(
				'ai_enabled'           => '1',
				'ai_provider'          => Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO,
				'minimum_user_profile' => 'editor',
			)
		);

		$request  = $this->build_improve_request();
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'browser', $response->get_data()['message'] );
	}

	// -----------------------------------------------------------------------
	// Unauthenticated request
	// -----------------------------------------------------------------------

	/**
	 * An unauthenticated request to the improve endpoint is rejected with
	 * HTTP 401 or 403.
	 */
	public function test_improve_rejects_unauthenticated_request() {
		wp_set_current_user( 0 );

		$request  = $this->build_improve_request();
		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	// -----------------------------------------------------------------------
	// Empty / whitespace-only content
	// -----------------------------------------------------------------------

	/**
	 * Sending a whitespace-only content_text is rejected with HTTP 400.
	 */
	public function test_improve_returns_400_for_whitespace_only_content() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode'         => 'improve_description',
					'task_context' => array(
						'content_text' => '   ',
						'content_html' => '<p>   </p>',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Omitting the task_context parameter entirely produces an HTTP 400 due
	 * to empty content.
	 */
	public function test_improve_returns_400_when_task_context_absent() {
		wp_set_current_user( $this->editor_id );

		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode' => 'improve_description',
					// Deliberately omit task_context.
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// get_supported_providers
	// -----------------------------------------------------------------------

	/**
	 * get_supported_providers() always returns both known provider keys.
	 */
	public function test_get_supported_providers_returns_known_keys() {
		$providers = Decker_AI_Manager::get_supported_providers();

		$this->assertContains( Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO, $providers );
		$this->assertContains( Decker_AI_Manager::PROVIDER_GEMINI_API, $providers );
	}

	// -----------------------------------------------------------------------
	// get_selected_provider / get_api_key
	// -----------------------------------------------------------------------

	/**
	 * When options are empty, get_selected_provider() returns the browser-Nano
	 * default.
	 */
	public function test_get_selected_provider_defaults_to_browser_nano() {
		$provider = Decker_AI_Manager::get_selected_provider( array() );

		$this->assertSame( Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO, $provider );
	}

	/**
	 * When options are empty, get_api_key() returns an empty string.
	 */
	public function test_get_api_key_returns_empty_string_when_not_configured() {
		$key = Decker_AI_Manager::get_api_key( array() );

		$this->assertSame( '', $key );
	}

	/**
	 * When the api_key value is a string of spaces, get_api_key() trims it
	 * and returns an empty string.
	 */
	public function test_get_api_key_trims_whitespace_only_value() {
		$key = Decker_AI_Manager::get_api_key( array( 'ai_api_key' => '   ' ) );

		// sanitize_text_field strips the spaces, leaving ''.
		$this->assertSame( '', trim( $key ) );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build a standard improve-description REST request.
	 *
	 * @return WP_REST_Request
	 */
	private function build_improve_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode'         => 'improve_description',
					'task_context' => array(
						'content_text' => 'Some existing task description.',
						'content_html' => '<p>Some existing task description.</p>',
					),
				)
			)
		);
		return $request;
	}
}
