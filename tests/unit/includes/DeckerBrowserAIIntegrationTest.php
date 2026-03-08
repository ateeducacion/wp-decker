<?php
/**
 * Tests for browser-only AI integration.
 *
 * @package Decker
 */

/**
 * Unit tests for browser-only AI integration.
 */
class DeckerBrowserAIIntegrationTest extends Decker_Test_Base {

	/**
	 * The legacy AI REST route should no longer be registered.
	 */
	public function test_legacy_ai_rest_route_is_not_registered() {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->assertArrayNotHasKey(
			'/decker/v1/ai/improve',
			$wp_rest_server->get_routes()
		);
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
}
