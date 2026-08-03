<?php
/**
 * Tests for the network settings admin screen.
 *
 * The allowlist parsing itself is covered by DeckerNetworkSettingsTest; these
 * cover the network admin page: the capability guard on both the render and
 * the save handler, the CSRF check, and the markup the form emits.
 *
 * @package Decker
 */

class DeckerNetworkSettingsScreenTest extends WP_UnitTestCase {

	/**
	 * The screen under test.
	 *
	 * @var Decker_Network_Settings
	 */
	private $settings;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->settings = new Decker_Network_Settings();
		delete_site_option( Decker_Network_Settings::OPTION_NAME );

		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_site_option( Decker_Network_Settings::OPTION_NAME );
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Sign in as a user holding manage_network_options.
	 *
	 * On a single-site install nobody holds the capability by default, not even
	 * an administrator, so it is granted explicitly.
	 *
	 * @return int The user ID.
	 */
	private function become_network_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $user_id )->add_cap( 'manage_network_options' );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Capture the rendered settings page.
	 *
	 * @return string The captured markup.
	 */
	private function render(): string {
		ob_start();
		$this->settings->render_network_settings_page();
		return ob_get_clean();
	}

	/**
	 * The page renders the allowlist field pre-filled with the stored value.
	 */
	public function test_page_renders_the_stored_allowlist() {
		$this->become_network_admin();
		update_site_option( Decker_Network_Settings::OPTION_NAME, '2,7' );

		$output = $this->render();

		$this->assertStringContainsString( 'Decker Network Settings', $output );
		$this->assertStringContainsString( 'id="decker_allowed_sites"', $output );
		$this->assertStringContainsString( 'name="decker_allowed_sites"', $output );
		$this->assertStringContainsString( 'value="2,7"', $output );

		// The form posts to the network action endpoint with a nonce.
		$this->assertStringContainsString( 'action="edit.php?action=decker_network_settings"', $output );
		$this->assertStringContainsString( 'name="decker_network_settings_nonce"', $output );

		// No success notice without the updated flag.
		$this->assertStringNotContainsString( 'Network settings saved.', $output );
	}

	/**
	 * Returning from a save shows the success notice.
	 */
	public function test_page_shows_the_saved_notice_after_a_save() {
		$this->become_network_admin();
		$_GET['updated'] = 'true';

		$output = $this->render();

		$this->assertStringContainsString( 'notice notice-success', $output );
		$this->assertStringContainsString( 'Network settings saved.', $output );
	}

	/**
	 * A user without manage_network_options cannot view the page.
	 */
	public function test_page_is_denied_without_the_network_capability() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'sufficient permissions' );

		$level = ob_get_level();
		try {
			$this->render();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * A user without manage_network_options cannot save either.
	 */
	public function test_save_is_denied_without_the_network_capability() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$_POST['decker_allowed_sites'] = '9';

		try {
			$this->settings->save_network_settings();
			$this->fail( 'Saving without the capability should have been stopped.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'sufficient permissions', $e->getMessage() );
		}

		$this->assertFalse( get_site_option( Decker_Network_Settings::OPTION_NAME ) );
	}

	/**
	 * Saving without a valid nonce is rejected and stores nothing.
	 */
	public function test_save_is_rejected_without_a_valid_nonce() {
		$this->become_network_admin();

		$_POST['decker_allowed_sites']         = '9';
		$_POST['decker_network_settings_nonce'] = 'not-a-nonce';

		$this->expectException( WPDieException::class );

		try {
			$this->settings->save_network_settings();
		} finally {
			$this->assertFalse( get_site_option( Decker_Network_Settings::OPTION_NAME ) );
		}
	}

	/**
	 * The network menu entry is registered under the network settings page.
	 */
	public function test_network_menu_entry_is_registered() {
		global $submenu;

		$submenu = array();
		$this->become_network_admin();

		$this->settings->add_network_menu();

		$this->assertArrayHasKey( 'settings.php', $submenu );
		$slugs = array_column( $submenu['settings.php'], 2 );
		$this->assertContains( 'decker_network_settings', $slugs );
	}
}
