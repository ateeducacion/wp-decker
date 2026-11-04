<?php
/**
 * Access rules for the iPhone/iPad CalDAV profile download.
 *
 * @package Decker
 */

/** The profile download runs through the nonce-protected AJAX action. */
class DeckerCaldavProfileDownloadTest extends WP_Ajax_UnitTestCase {
	private static function dav_user() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor', 'user_login' => 'ana' ) );
		update_user_meta( $user->ID, 'decker_calendar_dav_enabled', true );
		return $user;
	}

	public function test_profile_download_requires_capability_and_enabled_dav() {
		$extended = new Decker_User_Extended();
		$owner = self::dav_user();
		wp_set_current_user( $owner->ID );
		$_GET['user_id'] = $owner->ID;
		$_GET['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( 'decker_caldav_profile' );
		$this->assertSame( $owner->ID, $extended->caldav_profile_user()->ID );

		update_user_meta( $owner->ID, 'decker_calendar_dav_enabled', false );
		$this->assertNull( $extended->caldav_profile_user() );
		update_user_meta( $owner->ID, 'decker_calendar_dav_enabled', true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( 'decker_caldav_profile' );
		$this->assertNull( $extended->caldav_profile_user() );
		$this->assert_rejected();

		wp_set_current_user( $owner->ID );
		$_REQUEST['nonce'] = 'forged';
		$this->assert_rejected();
	}

	private function assert_rejected() {
		$this->_last_response = '';
		try {
			$this->_handleAjax( 'decker_caldav_profile' );
			$this->fail( 'The download must stop before sending a profile.' );
		} catch ( WPAjaxDieStopException $error ) {
			$this->assertStringNotContainsString( 'plist', $this->_last_response . $error->getMessage() );
		}
	}
}
