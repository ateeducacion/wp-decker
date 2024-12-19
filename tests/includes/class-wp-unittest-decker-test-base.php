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

		return $factory;
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
