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
	public static function current_user_can_minimum_role() {

		// Get the saved user profile role from plugin options, default to 'editor'.
		$options       = get_option( 'decker_settings', array() );
		$role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// Get all roles in order of hierarchy.
		$roles = wp_roles()->roles;

		// Get the index of the required role in the hierarchy.
		$role_index = array_keys( $roles );
		$required_index = array_search( $role, $role_index );

		// Check the current user's role.
		foreach ( wp_get_current_user()->roles as $user_role ) {
			$user_index = array_search( $user_role, $role_index );
			if ( false !== $user_index && $user_index <= $required_index ) {
				return true; // User has the required role or higher.
			}
		}

		return false; // User does not meet the minimum role.
	}

	/**
	 * Get the relative time string based on the due date.
	 *
	 * @param DateTime|null $due_date The due date as a DateTime object. Null if no due date.
	 * @return string The relative time string (e.g., "in 2 days", "3 months ago").
	 */
	public static function get_relative_time( ?DateTime $due_date ): string {
		// Return an empty string if no due date is provided.
		if ( empty( $due_date ) ) {
			return '';
		}

		// Check for DateTime errors (optional, depending on how $due_date is created).
		$errors = DateTime::getLastErrors();
		if ( ! $due_date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return ''; // Do nothing if the date is not valid.
		}

		// Clone the DateTime object to avoid modifying the original.
		$due_date_cloned = clone $due_date;

		// Set the time to 23:59:59 to consider the entire day.
		$due_date_cloned->setTime( 23, 59, 59 );

		// Get the current time.
		$now = new DateTime();

		// Calculate the difference between now and the due date.
		$interval = $now->diff( $due_date_cloned );

		// Determine if the due date is in the future.
		$is_future = $due_date_cloned > $now;

		// Determine the appropriate relative time string based on the interval.
		if ( $interval->y > 0 ) {
			return $is_future
				/* translators: 1: number of years, 2: 's' for plural or empty for singular */
				? sprintf( __( 'in %1$d year%2$s', 'decker' ), $interval->y, $interval->y > 1 ? 's' : '' )
				/* translators: 1: number of years, 2: 's' for plural or empty for singular */
				: sprintf( __( '%1$d year%2$s ago', 'decker' ), $interval->y, $interval->y > 1 ? 's' : '' );
		} elseif ( $interval->m > 0 ) {
			return $is_future
				/* translators: 1: number of months, 2: 's' for plural or empty for singular */
				? sprintf( __( 'in %1$d month%2$s', 'decker' ), $interval->m, $interval->m > 1 ? 's' : '' )
				/* translators: 1: number of months, 2: 's' for plural or empty for singular */
				: sprintf( __( '%1$d month%2$s ago', 'decker' ), $interval->m, $interval->m > 1 ? 's' : '' );
		} elseif ( $interval->d > 0 ) {
			return $is_future
				/* translators: 1: number of days, 2: 's' for plural or empty for singular */
				? sprintf( __( 'in %1$d day%2$s', 'decker' ), $interval->d, $interval->d > 1 ? 's' : '' )
				/* translators: 1: number of days, 2: 's' for plural or empty for singular */
				: sprintf( __( '%1$d day%2$s ago', 'decker' ), $interval->d, $interval->d > 1 ? 's' : '' );
		} elseif ( $interval->h > 0 ) {
			return $is_future
				/* translators: 1: number of hours, 2: 's' for plural or empty for singular */
				? sprintf( __( 'in %1$d hour%2$s', 'decker' ), $interval->h, $interval->h > 1 ? 's' : '' )
				/* translators: 1: number of hours, 2: 's' for plural or empty for singular */
				: sprintf( __( '%1$d hour%2$s ago', 'decker' ), $interval->h, $interval->h > 1 ? 's' : '' );
		} elseif ( $interval->i > 0 ) {
			return $is_future
				/* translators: 1: number of minutes, 2: 's' for plural or empty for singular */
				? sprintf( __( 'in %1$d minute%2$s', 'decker' ), $interval->i, $interval->i > 1 ? 's' : '' )
				/* translators: 1: number of minutes, 2: 's' for plural or empty for singular */
				: sprintf( __( '%1$d minute%2$s ago', 'decker' ), $interval->i, $interval->i > 1 ? 's' : '' );
		} else {
			return $is_future
				? __( 'in a few seconds', 'decker' )
				: __( 'a few seconds ago', 'decker' );
		}
	}
}
