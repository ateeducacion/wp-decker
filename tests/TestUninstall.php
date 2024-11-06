<?php
/**
 * TestUninstall class
 *
 * @package    Decker
 * @subpackage Decker/tests
 */

require_once dirname( __DIR__ ) . '/uninstall.php';

/**
 * Class Test_Uninstall
 *
 * @coversDefaultClass \Decker_Uninstall
 */
class TestUninstall extends \WP_Mock\Tools\TestCase {

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		\WP_Mock::setUp();
	}

	/**
	 * Tear down the test environment.
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
	}

	/**
	 * Test that uninstall exits when not called from WordPress.
	 *
	 * @covers ::decker_uninstall
	 */
	public function test_uninstall_exits_when_not_called_from_wordpress() {
		// Mock the constant WP_UNINSTALL_PLUGIN to be false.
		\WP_Mock::userFunction(
			'defined',
			array(
				'args'   => array( 'WP_UNINSTALL_PLUGIN' ),
				'return' => false,
			)
		);

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'exit' );

		// Call the uninstall function.
		decker_uninstall();
	}

	/**
	 * Test that uninstall does not exit when called from WordPress.
	 *
	 * @covers ::decker_uninstall
	 */
	public function test_uninstall_does_not_exit_when_called_from_wordpress() {
		// Mock the constant WP_UNINSTALL_PLUGIN to be true.
		\WP_Mock::userFunction(
			'defined',
			array(
				'args'   => array( 'WP_UNINSTALL_PLUGIN' ),
				'return' => true,
			)
		);

		// Call the uninstall function.
		decker_uninstall();
	}

	/**
	 * Test that uninstall removes plugin options.
	 *
	 * @covers ::decker_uninstall
	 */
	public function test_uninstall_removes_options() {
		\WP_Mock::userFunction(
			'delete_option',
			array(
				'times'  => 1,
				'args'   => array( 'decker_plugin_options' ),
				'return' => true,
			)
		);

		decker_uninstall();
	}

	/**
	 * Test that uninstall removes custom tables.
	 *
	 * @covers ::decker_uninstall
	 */
	public function test_uninstall_removes_custom_tables() {
		// Create a mock for $wpdb.
		$wpdb_mock = \Mockery::mock( 'wpdb' );
		$wpdb_mock->shouldReceive( 'query' )
			->once()
			->with( 'DROP TABLE IF EXISTS ' . $wpdb_mock->prefix . 'decker_tasks' );

		// Inject the mock into the uninstall function.
		decker_uninstall( $wpdb_mock );
	}
}
