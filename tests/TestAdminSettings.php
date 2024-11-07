<?php
/**
 * Class TestAdminSettings
 *
 * @package Decker
 */

use WP_Mock\Tools\TestCase;
use WP_Mock as M;

require_once dirname( __DIR__ ) . '/admin/class-decker-admin-settings.php';

/**
 * Class TestAdminSettings
 *
 * @package Decker
 */
class TestAdminSettings extends TestCase {

	/**
	 * SetUp function
	 */
	public function setUp(): void {
		parent::setUp();
		M::setUp();
	}

	/**
	 * TearDown function
	 */
	public function tearDown(): void {
		M::tearDown();
		parent::tearDown();
	}

	/**
	 * Test create_menu function
	 */
	public function test_create_menu() {
		M::expectActionAdded( 'admin_menu', array( 'Decker_Admin_Settings', 'create_menu' ) );

		$settings = new Decker_Admin_Settings();
		$settings->create_menu();

		M::expectHookAdded( 'admin_menu', array( $settings, 'create_menu' ) );
		M::expectFunction( 'add_options_page' )->with(
			'Decker Settings',
			'Decker',
			'manage_options',
			'decker_settings',
			array( $settings, 'options_page' )
		);
		$settings->create_menu();
	}

	/**
	 * Test settings_init function
	 */
	public function test_settings_init() {
		M::expectActionAdded( 'admin_init', array( 'Decker_Admin_Settings', 'settings_init' ) );

		$settings = new Decker_Admin_Settings();
		$settings->settings_init();

		M::expectHookAdded( 'admin_init', array( $settings, 'settings_init' ) );
		M::expectFunction( 'register_setting' )->with( 'decker', 'decker_settings', array( $settings, 'settings_validate' ) );
		$settings->settings_init();
	}

	/**
	 * Test settings_section_callback function
	 */
	public function test_settings_section_callback() {
		$settings = new Decker_Admin_Settings();
		$this->expectOutputString( '<p>Enter your Nextcloud user and access token to configure the Decker plugin.</p>' );
		$settings->settings_section_callback();
	}

	/**
	 * Test settings_validate function
	 */
	public function test_settings_validate() {
		$settings = new Decker_Admin_Settings();

		$input = array(
			'nextcloud_url_base' => 'https://example.com',
		);

		$result = $settings->settings_validate( $input );
		$this->assertEquals( $input, $result );

		$input['nextcloud_url_base'] = 'invalid-url';
		$result = $settings->settings_validate( $input );
		$this->assertEquals( '', $result['nextcloud_url_base'] );
	}

	/**
	 * Test options_page function
	 */
	public function test_options_page() {
		$settings = new Decker_Admin_Settings();
		ob_start();
		$settings->options_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form action="options.php" method="post">', $output );
		$this->assertStringContainsString( '<h2>Decker Settings</h2>', $output );
	}
}
