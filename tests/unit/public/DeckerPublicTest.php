<?php

/**
 * Class Test_Decker_Public
 *
 * @package Decker
 */

class DeckerPublicTest extends Decker_Test_Base {

	/**
	 * Instancia de Decker_Public.
	 *
	 * @var Decker_Public
	 */
	protected $decker_public;

	/**
     * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();

               // Define the WP_TESTS_RUNNING constant.
		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			define( 'WP_TESTS_RUNNING', true );
		}

               // Create an instance of Decker_Public.
		$this->decker_public = new Decker_Public( 'decker', '1.0.0' );
	}

	/**
     * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		parent::tear_down();
	}

	public function test_enqueue_scripts() {
               // Simulate a query_var value.
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'decker_page';
				return $vars;
			}
		);

		set_query_var( 'decker_page', 'analytics' );

		$this->decker_public->enqueue_scripts();

                // Verify that the scripts have been enqueued.
                $this->assertTrue( wp_script_is( 'config', 'enqueued' ) );
	}

	/**
	 * The retired AI endpoint stays unavailable with previously enabled settings.
	 */
	public function test_retired_ai_endpoint_does_not_call_a_provider() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( 'decker_settings', array( 'ai_enabled' => '1', 'ai_provider' => 'gemini_api', 'ai_api_key' => 'retired-api-key' ) );
		$calls = 0;
		$block_http = static function () use ( &$calls ) {
			++$calls;
			return new WP_Error( 'unexpected_ai_request', 'Retired AI attempted an HTTP request.' );
		};
		add_filter( 'pre_http_request', $block_http );
		try {
			$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_param( 'task_context', array( 'content_text' => 'Existing task description' ) );
			$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status() );
			$this->assertSame( 0, $calls );
		} finally {
			remove_filter( 'pre_http_request', $block_http );
		}
	}

	/**
	 * Test merge task UI strings are localized for the task card script.
	 */
	public function test_enqueue_scripts_localizes_merge_task_strings() {
		set_query_var( 'decker_page', 'task' );

		$this->decker_public->enqueue_scripts();

		$data = wp_scripts()->get_data( 'task-card', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString(
			'"merge_task_title":"Merge task"',
			$data
		);
		$this->assertStringContainsString(
			'"merge_task_search_hint":"Type at least 2 characters to search for a destination task."',
			$data
		);
		$this->assertStringContainsString(
			'"select_task_to_merge":"Please select a destination task."',
			$data
		);
	}

}
