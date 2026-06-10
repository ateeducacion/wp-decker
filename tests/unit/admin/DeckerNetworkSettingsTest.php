<?php
/**
 * Tests for Decker_Network_Settings class.
 *
 * @package Decker\Tests
 */

/**
 * Class DeckerNetworkSettingsTest
 *
 * Unit tests for the Decker_Network_Settings class covering the allowlist
 * retrieval and site permission check logic.
 */
class DeckerNetworkSettingsTest extends WP_UnitTestCase {

	/**
	 * Clean up the site option before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_site_option( Decker_Network_Settings::OPTION_NAME );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_site_option( Decker_Network_Settings::OPTION_NAME );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// get_allowed_sites() tests
	// -----------------------------------------------------------------------

	/**
	 * An empty option should return an empty array (no restriction configured).
	 */
	public function test_get_allowed_sites_returns_empty_array_when_option_not_set() {
		$sites = Decker_Network_Settings::get_allowed_sites();
		$this->assertIsArray( $sites );
		$this->assertEmpty( $sites );
	}

	/**
	 * A single site ID stored in the option should be returned as an array
	 * with one integer element.
	 */
	public function test_get_allowed_sites_returns_single_site() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, '3' );
		$sites = Decker_Network_Settings::get_allowed_sites();
		$this->assertSame( array( 3 ), $sites );
	}

	/**
	 * Multiple site IDs should all be returned as integers.
	 */
	public function test_get_allowed_sites_returns_multiple_sites() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, '1,2,5' );
		$sites = Decker_Network_Settings::get_allowed_sites();
		$this->assertSame( array( 1, 2, 5 ), $sites );
	}

	/**
	 * Extra whitespace around IDs should be trimmed correctly.
	 */
	public function test_get_allowed_sites_trims_whitespace() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, ' 1 , 2 , 3 ' );
		$sites = Decker_Network_Settings::get_allowed_sites();
		$this->assertSame( array( 1, 2, 3 ), $sites );
	}

	// -----------------------------------------------------------------------
	// is_site_allowed() tests
	// -----------------------------------------------------------------------

	/**
	 * When the allowlist is empty every site should be allowed (no restriction).
	 */
	public function test_is_site_allowed_returns_true_when_no_restriction() {
		$this->assertTrue( Decker_Network_Settings::is_site_allowed( 1 ) );
		$this->assertTrue( Decker_Network_Settings::is_site_allowed( 999 ) );
	}

	/**
	 * A site ID present in the allowlist should be allowed.
	 */
	public function test_is_site_allowed_returns_true_for_allowed_site() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, '1,2,3' );
		$this->assertTrue( Decker_Network_Settings::is_site_allowed( 2 ) );
	}

	/**
	 * A site ID absent from the allowlist should not be allowed.
	 */
	public function test_is_site_allowed_returns_false_for_disallowed_site() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, '1,2,3' );
		$this->assertFalse( Decker_Network_Settings::is_site_allowed( 4 ) );
	}

	/**
	 * Passing a string representation of an allowed ID should still return true.
	 */
	public function test_is_site_allowed_accepts_string_id() {
		update_site_option( Decker_Network_Settings::OPTION_NAME, '5' );
		$this->assertTrue( Decker_Network_Settings::is_site_allowed( '5' ) );
	}

	// -----------------------------------------------------------------------
	// save_network_settings() validation tests (via settings_validate helper)
	// -----------------------------------------------------------------------

	/**
	 * The network settings class should exist.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( 'Decker_Network_Settings' ) );
	}

	/**
	 * The OPTION_NAME constant should be defined.
	 */
	public function test_option_name_constant_is_defined() {
		$this->assertSame( 'decker_network_allowed_sites', Decker_Network_Settings::OPTION_NAME );
	}
}
