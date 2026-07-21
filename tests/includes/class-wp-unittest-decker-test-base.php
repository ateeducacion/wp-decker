<?php
/**
 * Base test class for Decker plugin tests.
 *
 * @package Decker
 */

class Decker_Test_Base extends WP_UnitTestCase {

	/**
	 * Extends the factory method to include custom factories.
	 *
	 * @return WP_UnitTest_Factory The extended factory object.
	 */
	public static function factory() {
		// Retrieve the base factory
		$factory = parent::factory();

		// Add custom factories if they do not already exist
		if ( ! isset( $factory->board ) ) {
			$factory->board = new WP_UnitTest_Factory_For_Decker_Board( $factory );
		}

		if ( ! isset( $factory->label ) ) {
			$factory->label = new WP_UnitTest_Factory_For_Decker_Label( $factory );
		}

		if ( ! isset( $factory->task ) ) {
			$factory->task = new WP_UnitTest_Factory_For_Decker_Task( $factory );
		}

		if ( ! isset( $factory->event ) ) {
			$factory->event = new WP_UnitTest_Factory_For_Decker_Event( $factory );
		}

		return $factory;
	}

	/**
	 * Force an AJAX request context whose wp_die handler throws WPDieException.
	 *
	 * Endpoints that finish with wp_send_json_*() terminate the request: WordPress
	 * calls wp_die() through the AJAX handler when wp_doing_ajax() is true, and a
	 * bare die() otherwise. The PHPUnit bootstrap only overrides the HTML wp_die
	 * handler, so without this both paths abort the whole test run. This forces the
	 * AJAX context and swaps in a throwing AJAX handler so wp_send_json_*() becomes
	 * a catchable WPDieException. Pair with disable_wp_send_json_capture().
	 */
	protected function enable_wp_send_json_capture() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'throwing_ajax_die_handler' ) );
	}

	/**
	 * Remove the filters added by enable_wp_send_json_capture() so no state leaks.
	 */
	protected function disable_wp_send_json_capture() {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'throwing_ajax_die_handler' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	/**
	 * AJAX wp_die handler that throws WPDieException instead of dying.
	 *
	 * @return callable The handler passed to wp_die().
	 */
	public function throwing_ajax_die_handler() {
		return static function ( $message ) {
			throw new WPDieException( is_scalar( $message ) ? (string) $message : '0' );
		};
	}

	// /**
	// * Sets up custom factories before running any tests in the class.
	// *
	// * @param WP_UnitTest_Factory $factory The main factory object.
	// */
	// public static function set_up_before_class( $factory ) {
	// parent::set_up_before_class( $factory );

	// Register custom factories
	// $factory->board  = new WP_UnitTest_Factory_For_Decker_Board( $factory );
	// $factory->label  = new WP_UnitTest_Factory_For_Decker_Label( $factory );
	// $factory->task   = new WP_UnitTest_Factory_For_Decker_Task( $factory );
	// }

	// /**
	// * Cleans up after all tests in the class have been executed.
	// */
	// public static function tear_down_after_class() {
	// parent::tear_down_after_class();
	// Additional cleanup can be added here if necessary.
	// }
}
