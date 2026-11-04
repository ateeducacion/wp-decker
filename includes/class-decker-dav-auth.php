<?php
/**
 * Calendar-only HTTP Basic authentication.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** The token is never registered as a WordPress authentication provider. */
class Decker_Dav_Auth extends Sabre\DAV\Auth\Backend\AbstractBasic {
	/** Validate the user's explicit, newly generated DAV credential.
	 *
	 * @param string $username Login.
	 * @param string $password Calendar token.
	 * @return bool
	 */
	protected function validateUserPass( $username, $password ) {
		wp_set_current_user( 0 );
		$user = get_user_by( 'login', $username );
		if ( ! $user || '' === $password || ! get_user_meta( $user->ID, 'decker_calendar_dav_enabled', true ) ) {
			return false;
		}
		$stored = (string) get_user_meta( $user->ID, 'decker_calendar_token', true );
		if ( '' === $stored || ! hash_equals( $stored, $password ) || $user->spam || $user->deleted ) {
			return false;
		}
		wp_set_current_user( $user->ID );
		if ( ! Decker_Dav::allowed() ) {
			wp_set_current_user( 0 );
			return false;
		}
		return true;
	}
}
