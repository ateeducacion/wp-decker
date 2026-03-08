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
}
