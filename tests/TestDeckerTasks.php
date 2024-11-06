<?php
/**
 * Test class for Decker_Tasks.
 *
 * @package Decker
 */

use WP_Mock\Tools\TestCase;

require_once dirname( __DIR__ ) . '/includes/custom-post-types/class-decker-tasks.php';

/**
 * Class Test_Decker_Tasks
 *
 * @coversDefaultClass \Decker_Tasks
 */
class TestDeckerTasks extends TestCase {

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
	 * Test register_post_type method.
	 *
	 * @covers ::register_post_type
	 */
	public function test_register_post_type() {
		$decker_tasks = new Decker_Tasks();

		\WP_Mock::expectActionAdded( 'init', array( $decker_tasks, 'register_post_type' ) );

		$decker_tasks->register_post_type();

		$this->assertHooksAdded();
	}

	/**
	 * Test add_custom_post_status method.
	 *
	 * @covers ::add_custom_post_status
	 */
	public function test_add_custom_post_status() {
		$decker_tasks = new Decker_Tasks();

		\WP_Mock::expectActionAdded( 'init', array( $decker_tasks, 'add_custom_post_status' ) );

		$decker_tasks->add_custom_post_status();

		$this->assertHooksAdded();
	}
}
