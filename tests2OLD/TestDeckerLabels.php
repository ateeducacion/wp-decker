<?php
/**
 * Test class for Decker_Labels.
 *
 * @package Decker
 */

use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/includes/custom-post-types/class-decker-labels.php';

/**
 * Class Test_Decker_Labels
 *
 * @coversDefaultClass \Decker_Labels
 */
class TestDeckerLabels extends TestCase {

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		\WP_Mock::setUp();
		parent::setUp();
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Test register_taxonomy method.
	 *
	 * @covers ::register_taxonomy
	 */
	public function test_register_taxonomy() {
		$decker_labels = new Decker_Labels();

		\WP_Mock::expectActionAdded( 'init', array( $decker_labels, 'register_taxonomy' ) );

		$decker_labels->register_taxonomy();

		$this->assertHooksAdded();
	}

	/**
	 * Test customize_columns method.
	 *
	 * @covers ::customize_columns
	 */
	public function test_customize_columns() {
		$decker_labels = new Decker_Labels();
		$columns       = array(
			'name'        => 'Name',
			'description' => 'Description',
		);
		$customized_columns = $decker_labels->customize_columns( $columns );

		$this->assertArrayHasKey( 'color', $customized_columns );
		$this->assertArrayNotHasKey( 'description', $customized_columns );
	}
}
