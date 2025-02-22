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

}
