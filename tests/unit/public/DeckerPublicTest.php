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

		// Verifica que los scripts se hayan cargado.
		$this->assertTrue( wp_script_is( 'config', 'enqueued' ) );
	}
}
