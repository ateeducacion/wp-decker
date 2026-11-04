<?php
/**
 * Device setup helpers: the DAVx⁵ link and the Apple configuration profile.
 *
 * @package Decker
 */

/** One-tap setup must prefill server and login but never carry the token. */
class DeckerCaldavDeviceSetupTest extends WP_UnitTestCase {
	private function editor( $login = 'ana' ) {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor', 'user_login' => $login ) );
		update_user_meta( $user->ID, 'decker_calendar_token', 'secret-dav-token' );
		update_user_meta( $user->ID, 'decker_calendar_dav_enabled', true );
		return $user;
	}

	public function test_davx5_link_prefills_encoded_login_and_server_path() {
		$server = preg_replace( '#^https?://#', '', Decker_Dav::url() );
		$this->assertSame( 'davx5://ana%40centro@' . $server, Decker_Dav::davx5_url( 'ana@centro' ) );
	}

	public function test_apple_profile_is_a_plist_without_the_token() {
		$user = $this->editor();
		$xml = Decker_Dav::apple_profile( $user );
		$this->assertStringNotContainsString( 'secret-dav-token', $xml );
		$this->assertStringNotContainsString( 'CalDAVPassword', $xml );
		$plist = simplexml_load_string( $xml );
		$this->assertNotFalse( $plist );
		$this->assertStringContainsString( '<key>CalDAVHostName</key><string>' . wp_parse_url( Decker_Dav::url(), PHP_URL_HOST ) . '</string>', $xml );
		$this->assertStringContainsString( '<string>com.apple.caldav.account</string>', $xml );
		$this->assertStringContainsString( '<key>CalDAVUsername</key><string>ana</string>', $xml );
		$this->assertStringContainsString( '<key>CalDAVPrincipalURL</key><string>' . Decker_Dav::url() . 'principals/ana/</string>', $xml );
		$this->assertStringContainsString( '<key>CalDAVUseSSL</key><true/>', $xml );
		$this->assertSame( $xml, Decker_Dav::apple_profile( $user ), 'Stable UUIDs let a reinstall replace the account.' );
	}
}
