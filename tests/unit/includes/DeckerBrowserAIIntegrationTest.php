<?php
/**
 * Tests for AI integration configuration.
 *
 * @package Decker
 */

/**
 * Unit tests for AI integration configuration.
 */
class DeckerBrowserAIIntegrationTest extends Decker_Test_Base {

	/**
	 * The AI REST route should be registered for server-side Gemini requests.
	 */
	public function test_ai_rest_route_is_registered() {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		new Decker_AI_Manager();
		do_action( 'rest_api_init' );

		$this->assertArrayHasKey(
			'/decker/v1/ai/improve',
			$wp_rest_server->get_routes()
		);
	}

	/**
	 * AI config should default to disabled when the setting is missing.
	 */
	public function test_ai_config_defaults_to_disabled() {
		update_option( 'decker_settings', array() );

		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertFalse( $config['enabled'] );
	}

	/**
	 * AI config should be disabled when the setting is turned off.
	 */
	public function test_ai_config_respects_disabled_setting() {
		update_option(
			'decker_settings',
			array(
				'ai_enabled' => '0',
			)
		);

		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertFalse( $config['enabled'] );
	}

	/**
	 * AI config should expose the custom prompt template from settings.
	 */
	public function test_ai_config_uses_custom_prompt_template() {
		update_option(
			'decker_settings',
			array(
				'ai_prompt' => 'Custom AI prompt {{task_context}}',
			)
		);

		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertSame(
			'Custom AI prompt {{task_context}}',
			$config['prompts']['prompt_template']
		);
	}

	/**
	 * AI config should default to the browser Gemini Nano provider.
	 */
	public function test_ai_config_defaults_to_browser_gemini_nano_provider() {
		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertSame(
			Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO,
			$config['provider']
		);
		$this->assertFalse( $config['server_available'] );
	}

	/**
	 * AI config should expose only the simplified four browser AI actions.
	 */
	public function test_ai_config_exposes_only_simplified_ai_actions() {
		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertArrayHasKey( 'mode_improve_description', $config['strings'] );
		$this->assertArrayHasKey( 'mode_make_actionable', $config['strings'] );
		$this->assertArrayHasKey( 'mode_generate_checklist', $config['strings'] );
		$this->assertArrayHasKey( 'mode_summarize', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_improve_writing', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_make_shorter', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_make_clearer', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_fix_grammar', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_checklist', $config['strings'] );
		$this->assertArrayNotHasKey( 'mode_acceptance_criteria', $config['strings'] );

		$this->assertArrayHasKey( 'improve_description', $config['prompts'] );
		$this->assertArrayHasKey( 'make_actionable', $config['prompts'] );
		$this->assertArrayHasKey( 'generate_checklist', $config['prompts'] );
		$this->assertArrayHasKey( 'summarize', $config['prompts'] );
		$this->assertArrayNotHasKey( 'improve_writing', $config['prompts'] );
		$this->assertArrayNotHasKey( 'make_shorter', $config['prompts'] );
		$this->assertArrayNotHasKey( 'make_clearer', $config['prompts'] );
		$this->assertArrayNotHasKey( 'fix_grammar', $config['prompts'] );
		$this->assertArrayNotHasKey( 'checklist', $config['prompts'] );
		$this->assertArrayNotHasKey( 'acceptance_criteria', $config['prompts'] );
	}

	/**
	 * Default AI prompt text should focus on returning only the task description.
	 */
	public function test_ai_config_prompt_text_focuses_on_task_description_only() {
		$public = new Decker_Public( 'decker', '1.0.0' );
		$method = new ReflectionMethod( $public, 'get_ai_config' );
		$method->setAccessible( true );
		$config = $method->invoke( $public );

		$this->assertStringContainsString(
			'Return only the description content for the task.',
			$config['prompts']['prompt_template']
		);
		$this->assertStringContainsString(
			'Return only the final task description as HTML',
			$config['prompts']['response_format']
		);
		$this->assertStringContainsString(
			'Return only the improved description.',
			$config['prompts']['improve_description']
		);
	}
}
