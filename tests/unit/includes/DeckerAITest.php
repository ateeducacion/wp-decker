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
		$request->set_param( 'mode', 'improve_writing' );
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
		$request->set_param( 'mode', 'improve_writing' );
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
		$request->set_param( 'mode', 'improve_writing' );
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
		$request->set_param( 'mode', 'improve_writing' );
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

		$valid_modes = array(
			'improve_writing',
			'make_shorter',
			'make_clearer',
			'fix_grammar',
			'make_actionable',
			'checklist',
			'acceptance_criteria',
			'summarize',
		);

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

	/**
	 * The provider should come from the new ai_provider setting when present.
	 */
	public function test_get_ai_provider_uses_new_provider_setting() {
		$ai = new Decker_AI_Testable();

		$this->assertEquals(
			'openrouter',
			$ai->expose_get_ai_provider(
				array(
					'ai_provider' => 'openrouter',
				)
			)
		);
	}

	/**
	 * Legacy endpoint settings should still infer the intended provider.
	 */
	public function test_get_ai_provider_infers_provider_from_legacy_endpoint() {
		$ai = new Decker_AI_Testable();

		$this->assertEquals(
			'gemini',
			$ai->expose_get_ai_provider(
				array(
					'openai_api_url' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
				)
			)
		);
	}

	/**
	 * Legacy API key options should still be accepted.
	 */
	public function test_get_api_key_falls_back_to_legacy_option_name() {
		$ai = new Decker_AI_Testable();

		$this->assertEquals(
			'legacy-key',
			$ai->expose_get_api_key(
				array(
					'openai_api_key' => 'legacy-key',
				)
			)
		);
	}

	/**
	 * Legacy model options should still be accepted.
	 */
	public function test_get_model_falls_back_to_legacy_option_name() {
		$ai = new Decker_AI_Testable();

		$this->assertEquals(
			'gpt-5-mini',
			$ai->expose_get_model(
				array(
					'openai_model' => 'gpt-5-mini',
				),
				array(
					'default_model' => 'gemini-2.0-flash',
				)
			)
		);
	}

	/**
	 * Gemini should use the Google OpenAI-compatible endpoint.
	 */
	public function test_get_provider_config_returns_gemini_endpoint() {
		$ai     = new Decker_AI_Testable();
		$config = $ai->expose_get_provider_config( 'gemini', array() );

		$this->assertEquals(
			'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
			$config['endpoint']
		);
	}

	/**
	 * Invalid provider settings should fail gracefully.
	 */
	public function test_invalid_provider_returns_error() {
		update_option(
			'decker_settings',
			array(
				'ai_provider' => 'invalid-provider',
				'ai_api_key'  => 'test-key',
			)
		);

		$ai     = new Decker_AI_Testable();
		$result = $ai->expose_improve_text( 'Sample text', 'improve_writing' );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_ai_provider', $result->get_error_code() );
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

	/**
	 * The prompt should instruct the provider to use the active WordPress locale.
	 */
	public function test_build_prompt_includes_wordpress_locale_instruction() {
		$ai     = new Decker_AI_Testable();
		$prompt = $ai->expose_build_prompt( 'improve_writing', 'Sample text' );

		$this->assertStringContainsString( get_user_locale(), $prompt );
		$this->assertStringContainsString( 'Sample text', $prompt );
	}

	/**
	 * Logged-in users should use their WordPress user locale in AI prompts.
	 */
	public function test_get_prompt_locale_uses_user_locale_for_logged_in_users() {
		wp_set_current_user( $this->editor_id );

		$ai = new Decker_AI_Testable();

		$this->assertEquals( get_user_locale(), $ai->expose_get_prompt_locale() );
	}

	/**
	 * Guests should fall back to the determined WordPress locale in AI prompts.
	 */
	public function test_get_prompt_locale_uses_determined_locale_for_guests() {
		wp_set_current_user( 0 );

		$ai = new Decker_AI_Testable();

		$this->assertEquals( determine_locale(), $ai->expose_get_prompt_locale() );
	}

	/**
	 * The final prompt should include the locale returned by get_prompt_locale().
	 */
	public function test_build_prompt_uses_prompt_locale_value() {
		$ai     = new Decker_AI_Testable_With_Locale( 'ca_ES' );
		$prompt = $ai->expose_build_prompt( 'improve_writing', 'Sample text' );

		$this->assertStringContainsString( 'ca_ES', $prompt );
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

	/**
	 * Expose the protected build_prompt method for testing.
	 *
	 * @param string $mode Rewrite mode.
	 * @param string $text Original content.
	 * @return string Prompt text.
	 */
	public function expose_build_prompt( $mode, $text ) {
		return $this->build_prompt( $mode, $text );
	}

	/**
	 * Expose the protected improve_text method for testing.
	 *
	 * @param string $text Text to improve.
	 * @param string $mode Rewrite mode.
	 * @return string|WP_Error Improved text or error.
	 */
	public function expose_improve_text( $text, $mode ) {
		return $this->improve_text( $text, $mode );
	}

	/**
	 * Expose the protected get_prompt_locale method for testing.
	 *
	 * @return string Locale code.
	 */
	public function expose_get_prompt_locale() {
		return $this->get_prompt_locale();
	}

	/**
	 * Expose the protected get_ai_provider method for testing.
	 *
	 * @param array $settings Plugin settings.
	 * @return string Provider slug.
	 */
	public function expose_get_ai_provider( $settings ) {
		return $this->get_ai_provider( $settings );
	}

	/**
	 * Expose the protected get_api_key method for testing.
	 *
	 * @param array $settings Plugin settings.
	 * @return string API key.
	 */
	public function expose_get_api_key( $settings ) {
		return $this->get_api_key( $settings );
	}

	/**
	 * Expose the protected get_model method for testing.
	 *
	 * @param array $settings        Plugin settings.
	 * @param array $provider_config Provider configuration.
	 * @return string Model identifier.
	 */
	public function expose_get_model( $settings, $provider_config ) {
		return $this->get_model( $settings, $provider_config );
	}

	/**
	 * Expose the protected get_provider_config method for testing.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $settings Plugin settings.
	 * @return array<string, mixed> Provider configuration.
	 */
	public function expose_get_provider_config( $provider, $settings ) {
		return $this->get_provider_config( $provider, $settings );
	}
}

/**
 * Testable subclass with an overridable prompt locale.
 *
 * @internal Only for use in unit tests.
 */
class Decker_AI_Testable_With_Locale extends Decker_AI_Testable {

	/**
	 * Locale to return from get_prompt_locale().
	 *
	 * @var string
	 */
	private $prompt_locale;

	/**
	 * Set the locale used by the test double.
	 *
	 * @param string $prompt_locale Locale code for prompts.
	 */
	public function __construct( $prompt_locale ) {
		$this->prompt_locale = $prompt_locale;
	}

	/**
	 * Return the injected locale for prompt building tests.
	 *
	 * @return string Locale code.
	 */
	protected function get_prompt_locale() {
		return $this->prompt_locale;
	}
}
