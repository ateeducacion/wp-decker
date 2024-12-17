<?php

/**
 * Class Test_Decker_Public
 *
 * @package Decker
 */

class DeckerPublicTest extends WP_UnitTestCase {

	/**
	 * Instancia de Decker_Public.
	 *
	 * @var Decker_Public
	 */
	protected $decker_public;

	/**
	 * Configuración antes de cada test.
	 */
	public function set_up(): void {
		parent::set_up();

		// Definir la constante WP_TESTS_RUNNING .
		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			define( 'WP_TESTS_RUNNING', true );
		}

		// Crear una instancia de Decker_Public .
		$this->decker_public = new Decker_Public( 'decker', '1.0.0' );
	}

	/**
	 * Limpiar después de cada test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		parent::tear_down();
	}

	public function test_enqueue_scripts() {
		// Simula un valor de query_var.
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
