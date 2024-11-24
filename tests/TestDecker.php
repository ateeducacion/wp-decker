<?php
/**
 * Test_Decker class
 *
 * @package    Decker
 * @subpackage Decker/tests
 */

use WP_Mock\Tools\TestCase;

/**
 * Class DeckerTest
 *
 * @package Decker
 */

/**
 * Sample test case.
 */
class TestDecker extends TestCase {

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * Test if the plugin is activated.
	 */
	public function test_plugin_activated() {
		WP_Mock::userFunction(
			'is_plugin_active',
			array(
				'args'   => array( 'decker/decker.php' ),
				'return' => true,
			)
		);

		$this->assertTrue( is_plugin_active( 'decker/decker.php' ) );
	}

	/**
	 * Test if the custom post type 'decker_task' is registered.
	 */
	public function test_custom_post_type_registered() {
		WP_Mock::userFunction(
			'post_type_exists',
			array(
				'args'   => array( 'decker_task' ),
				'return' => true,
			)
		);

		$this->assertTrue( post_type_exists( 'decker_task' ) );
	}

	/**
	 * Test if the custom post status 'archived' is registered.
	 */
	public function test_custom_post_status_registered() {
		WP_Mock::userFunction(
			'get_post_stati',
			array(
				'return' => array( 'archived' => 'Archived' ),
			)
		);

		$statuses = get_post_stati();
		$this->assertArrayHasKey( 'archived', $statuses );
	}

	/**
	 * Test if the settings page is added to the admin menu.
	 */
	public function test_settings_page_added() {
		global $submenu;
		$submenu = array(
			'options-general.php' => array(
				'decker_settings' => 'Decker Settings',
			),
		);

		$this->assertArrayHasKey( 'decker_settings', $submenu['options-general.php'] );
	}
}
