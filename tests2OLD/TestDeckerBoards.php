<?php
/**
 * Test class for Decker_Boards.
 *
 * @package Decker
 */

use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/includes/custom-post-types/class-decker-boards.php';

/**
 * Class Test_Decker_Boards
 *
 * @coversDefaultClass \Decker_Boards
 */
class TestDeckerBoards extends TestCase {

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
		$decker_boards = new Decker_Boards();

		\WP_Mock::expectActionAdded( 'init', array( $decker_boards, 'register_taxonomy' ) );

		$decker_boards->register_taxonomy();

		$this->assertHooksAdded();
	}

	/**
	 * Test customize_columns method.
	 *
	 * @covers ::customize_columns
	 */
	public function test_customize_columns() {
		$decker_boards = new Decker_Boards();
		$columns       = array(
			'name'        => 'Name',
			'description' => 'Description',
		);
		$customized_columns = $decker_boards->customize_columns( $columns );

		$this->assertArrayHasKey( 'color', $customized_columns );
		$this->assertArrayNotHasKey( 'description', $customized_columns );
	}
}
