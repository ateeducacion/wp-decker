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
}
