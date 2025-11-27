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
	 * Test that collaboration module is not enqueued when disabled.
	 */
	public function test_collaboration_not_enqueued_when_disabled() {
		// Disable collaborative editing.
		update_option( 'decker_settings', array( 'collaborative_editing' => '0' ) );

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// The collaboration script should not be present.
		$this->assertStringNotContainsString( 'decker-collaboration.js', $output );
		$this->assertStringNotContainsString( 'deckerCollabConfig', $output );
	}

	/**
	 * Test that collaboration module is enqueued when enabled.
	 */
	public function test_collaboration_enqueued_when_enabled() {
		// Create a test user.
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		// Enable collaborative editing.
		update_option(
			'decker_settings',
			array(
				'collaborative_editing' => '1',
				'signaling_server'      => 'wss://signaling.yjs.dev',
			)
		);

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// The collaboration config and script should be present.
		$this->assertStringContainsString( 'deckerCollabConfig', $output );
		$this->assertStringContainsString( 'decker-collaboration.js', $output );
		$this->assertStringContainsString( 'type="module"', $output );
	}

	/**
	 * Test collaboration config contains correct user information.
	 */
	public function test_collaboration_config_contains_user_info() {
		// Create a test user.
		$user = $this->factory->user->create_and_get(
			array(
				'role'         => 'editor',
				'display_name' => 'Test User',
			)
		);
		wp_set_current_user( $user->ID );

		// Enable collaborative editing.
		update_option(
			'decker_settings',
			array(
				'collaborative_editing' => '1',
				'signaling_server'      => 'wss://custom-server.example.com',
			)
		);

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// Check config contains expected values.
		$this->assertStringContainsString( '"enabled":true', $output );
		$this->assertStringContainsString( 'wss://custom-server.example.com', $output );
		$this->assertStringContainsString( 'Test User', $output );
		$this->assertStringContainsString( '"userId":' . $user->ID, $output );
	}

	/**
	 * Test collaboration config uses default signaling server when not specified.
	 */
	public function test_collaboration_uses_default_signaling_server() {
		// Create a test user.
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		// Enable collaborative editing without specifying signaling server.
		update_option(
			'decker_settings',
			array(
				'collaborative_editing' => '1',
			)
		);

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// Check that default signaling server is used.
		$this->assertStringContainsString( 'wss://signaling.yjs.dev', $output );
	}

	/**
	 * Test collaboration room prefix includes site domain for isolation.
	 */
	public function test_collaboration_room_prefix_includes_site_domain() {
		// Create a test user.
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		// Enable collaborative editing.
		update_option(
			'decker_settings',
			array(
				'collaborative_editing' => '1',
			)
		);

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// Check that room prefix contains 'decker-task-'.
		$this->assertStringContainsString( 'decker-task-', $output );
		$this->assertStringContainsString( 'roomPrefix', $output );
	}

	/**
	 * Test collaboration strings are localized.
	 */
	public function test_collaboration_strings_are_localized() {
		// Create a test user.
		$user = $this->factory->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		// Enable collaborative editing.
		update_option(
			'decker_settings',
			array(
				'collaborative_editing' => '1',
			)
		);

		// Simulate a decker page.
		set_query_var( 'decker_page', 'task' );

		// Capture footer output.
		ob_start();
		$this->decker_public->enqueue_scripts();
		do_action( 'wp_footer' );
		$output = ob_get_clean();

		// Check that strings object is present with expected keys.
		$this->assertStringContainsString( '"strings":', $output );
		$this->assertStringContainsString( 'connecting', $output );
		$this->assertStringContainsString( 'collaborative_mode', $output );
		$this->assertStringContainsString( 'disconnected', $output );
	}
}
