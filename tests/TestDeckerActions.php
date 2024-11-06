<?php
/**
 * Test_Decker_Actions class
 *
 * @package    Decker
 * @subpackage Decker/tests
 */

use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/includes/custom-post-types/class-decker-actions.php';

/**
 * Class Test_Decker_Actions
 *
 * @coversDefaultClass \Decker_Actions
 */
class TestDeckerActions extends TestCase {

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
	 * Test the register_taxonomy method.
	 */
	public function test_register_taxonomy() {
		$decker_actions = new Decker_Actions();

		\WP_Mock::expectActionAdded( 'init', array( $decker_actions, 'register_taxonomy' ) );

		$decker_actions->register_taxonomy();

		$this->assertHooksAdded();
	}

	/**
	 * Test the customize_columns method.
	 */
	public function test_customize_columns() {
		$decker_actions = new Decker_Actions();
		$columns = array(
			'name' => 'Name',
			'description' => 'Description',
		);
		$customized_columns = $decker_actions->customize_columns( $columns );

		$this->assertArrayHasKey( 'color', $customized_columns );
		$this->assertArrayNotHasKey( 'description', $customized_columns );
	}
}
