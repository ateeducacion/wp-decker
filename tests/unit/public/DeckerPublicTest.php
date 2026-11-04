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
	 * Test AI config exposes provider metadata without leaking secrets.
	 */
	public function test_get_ai_config_exposes_provider_metadata() {
		update_option( 'decker_settings', array() );

		$assets     = new Decker_Public_Assets();
		$reflection = new ReflectionMethod( $assets, 'get_ai_config' );
		$reflection->setAccessible( true );

		$config = $reflection->invoke( $assets );

		$this->assertFalse( $config['enabled'] );
		$this->assertSame(
			Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO,
			$config['provider']
		);
		$this->assertFalse( $config['server_available'] );
		$this->assertArrayHasKey( 'api_endpoint', $config );
		$this->assertArrayHasKey( 'ai_unavailable_title', $config['strings'] );
		$this->assertArrayHasKey( 'ai_chrome_unavailable', $config['strings'] );
		$this->assertArrayHasKey( 'ai_edge_unavailable', $config['strings'] );
		$this->assertArrayHasKey( 'ai_browser_unsupported', $config['strings'] );
		$this->assertArrayHasKey( 'ai_api_missing_key', $config['strings'] );
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
