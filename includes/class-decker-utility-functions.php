<?php
/**
 * Utility functions for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Utility_Functions
 *
 * Contains utility functions used throughout the Decker plugin.
 */
class Decker_Utility_Functions {

	/**
	 * Check if the current user has at least the required role.
	 *
	 * @return bool True if the user has the required role or higher, false otherwise.
	 */
	public static function current_user_has_at_least_minimum_role() {
		// Get the saved user profile role from plugin options, default to 'editor'.
		$options = get_option( 'decker_settings', array() );
		$required_role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// WordPress role hierarchy, ordered from lowest to highest.
		$role_hierarchy = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		// Determine the index of the required role.
		$required_index = array_search( $required_role, $role_hierarchy );

		if ( false === $required_index ) {
			// Invalid role in settings, fallback to the default.
			return false;
		}

		// Check each role of the current user.
		foreach ( wp_get_current_user()->roles as $user_role ) {
			$user_index = array_search( $user_role, $role_hierarchy );

			if ( false !== $user_index && $user_index >= $required_index ) {
				return true; // User has the required role or higher.
			}
		}

		return false; // User does not meet the minimum role requirement.
	}
}
