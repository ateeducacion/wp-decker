<?php
/**
 * Tests for the demo data randomizer.
 *
 * wp_rand is unseedable, so these pin the bounds a probability has to respect
 * rather than any particular draw.
 *
 * @package Decker
 */

/**
 * Class DeckerDemoRandomizerTest
 */
class DeckerDemoRandomizerTest extends Decker_Test_Base {

	/**
	 * Load the generator.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once plugin_dir_path( DECKER_PLUGIN_FILE ) . 'includes/class-decker-demo-data.php';
	}

	/**
	 * A probability of 0 or 1 is absolute.
	 */
	public function test_the_extremes_are_absolute() {
		$random = new Decker_Demo_Randomizer();

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertFalse( $random->random_boolean( 0 ) );
			$this->assertTrue( $random->random_boolean( 1 ) );
		}
	}

	/**
	 * A probability in between actually produces both outcomes.
	 *
	 * The regression this pins: custom_rand() defaults to PHP_INT_MAX, so
	 * dividing it by mt_getrandmax() gave a ratio far above 1 and every draw
	 * came back false — no demo task was ever archived or max priority.
	 */
	public function test_a_middling_probability_yields_both_outcomes() {
		$random = new Decker_Demo_Randomizer();
		$true   = 0;

		for ( $i = 0; $i < 200; $i++ ) {
			if ( $random->random_boolean( 0.5 ) ) {
				++$true;
			}
		}

		// At p = 0.5 over 200 draws, landing outside this range is a 1-in-10^20
		// event; anything that trips it is a bug, not bad luck.
		$this->assertGreaterThan( 50, $true );
		$this->assertLessThan( 150, $true );
	}
}
