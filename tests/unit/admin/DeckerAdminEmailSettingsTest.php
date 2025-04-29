<?php
/**
 * Class DeckerAdminEmailSettingsTest
 *
 * Tests for email notification settings.
 *
 * @package Decker
 */

class DeckerAdminEmailSettingsTest extends WP_UnitTestCase {

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'decker_settings', array() ); // Reset options for each test.
	}

	/**
	 * Test default value of allow_email_notifications.
	 */
	public function test_default_email_notifications_setting() {
		$settings = get_option( 'decker_settings', array() );
		$this->assertArrayNotHasKey( 'allow_email_notifications', $settings, 'Default setting should not be defined.' );
	}

	/**
	 * Test enabling email notifications.
	 */
	public function test_enable_email_notifications() {
		$settings = array( 'allow_email_notifications' => '1' );
		update_option( 'decker_settings', $settings );

		$saved_settings = get_option( 'decker_settings' );
		$this->assertEquals( '1', $saved_settings['allow_email_notifications'], 'Email notifications should be enabled.' );
	}

	/**
	 * Test disabling email notifications.
	 */
	public function test_disable_email_notifications() {
		$settings = array( 'allow_email_notifications' => '0' );
		update_option( 'decker_settings', $settings );

		$saved_settings = get_option( 'decker_settings' );
		$this->assertEquals( '0', $saved_settings['allow_email_notifications'], 'Email notifications should be disabled.' );
	}

	/**
	 * Test invalid value for email notifications.
	 */
	public function test_invalid_email_notifications_value() {
		$settings = array( 'allow_email_notifications' => 'invalid_value' );
		$validated = ( new Decker_Admin_Settings() )->settings_validate( $settings );

		$this->assertEquals( '0', $validated['allow_email_notifications'], 'Invalid values should be reset to "0".' );
	}
}
