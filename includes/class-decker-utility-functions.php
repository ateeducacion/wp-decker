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
	 * Get the relative time string based on the due date.
	 *
	 * @param DateTime|null $dueDate The due date as a DateTime object. Null if no due date.
	 * @return string The relative time string (e.g., "in 2 days", "3 months ago").
	 */
	public static function getRelativeTime(?DateTime $dueDate ): string {
	    // Return an empty string if no due date is provided
	    if ( empty( $dueDate ) ) {
	        return '';
	    }

	    // Check for DateTime errors (optional, depending on how $dueDate is created)
	    $errors = DateTime::getLastErrors();
	    if ( ! $dueDate || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
	        return ''; // Do nothing if the date is not valid
	    }

	    // Clone the DateTime object to avoid modifying the original
	    $dueDateCloned = clone $dueDate;

	    // Set the time to 23:59:59 to consider the entire day
	    $dueDateCloned->setTime(23, 59, 59);

	    // Get the current time
	    $now = new DateTime();

	    // Calculate the difference between now and the due date
	    $interval = $now->diff( $dueDateCloned );

	    // Determine if the due date is in the future
	    $isFuture = $dueDateCloned > $now;

	    // Determine the appropriate relative time string based on the interval
	    if ( $interval->y > 0 ) {
	        return $isFuture
	            /* translators: 1: number of years, 2: 's' for plural or empty for singular */
	            ? sprintf( __( 'in %1$d year%2$s', 'decker' ), $interval->y, $interval->y > 1 ? 's' : '' )
	            /* translators: 1: number of years, 2: 's' for plural or empty for singular */
	            : sprintf( __( '%1$d year%2$s ago', 'decker' ), $interval->y, $interval->y > 1 ? 's' : '' );
	    } elseif ( $interval->m > 0 ) {
	        return $isFuture
	            /* translators: 1: number of months, 2: 's' for plural or empty for singular */
	            ? sprintf( __( 'in %1$d month%2$s', 'decker' ), $interval->m, $interval->m > 1 ? 's' : '' )
	            /* translators: 1: number of months, 2: 's' for plural or empty for singular */
	            : sprintf( __( '%1$d month%2$s ago', 'decker' ), $interval->m, $interval->m > 1 ? 's' : '' );
	    } elseif ( $interval->d > 0 ) {
	        return $isFuture
	            /* translators: 1: number of days, 2: 's' for plural or empty for singular */
	            ? sprintf( __( 'in %1$d day%2$s', 'decker' ), $interval->d, $interval->d > 1 ? 's' : '' )
	            /* translators: 1: number of days, 2: 's' for plural or empty for singular */
	            : sprintf( __( '%1$d day%2$s ago', 'decker' ), $interval->d, $interval->d > 1 ? 's' : '' );
	    } elseif ( $interval->h > 0 ) {
	        return $isFuture
	            /* translators: 1: number of hours, 2: 's' for plural or empty for singular */
	            ? sprintf( __( 'in %1$d hour%2$s', 'decker' ), $interval->h, $interval->h > 1 ? 's' : '' )
	            /* translators: 1: number of hours, 2: 's' for plural or empty for singular */
	            : sprintf( __( '%1$d hour%2$s ago', 'decker' ), $interval->h, $interval->h > 1 ? 's' : '' );
	    } elseif ( $interval->i > 0 ) {
	        return $isFuture
	            /* translators: 1: number of minutes, 2: 's' for plural or empty for singular */
	            ? sprintf( __( 'in %1$d minute%2$s', 'decker' ), $interval->i, $interval->i > 1 ? 's' : '' )
	            /* translators: 1: number of minutes, 2: 's' for plural or empty for singular */
	            : sprintf( __( '%1$d minute%2$s ago', 'decker' ), $interval->i, $interval->i > 1 ? 's' : '' );
	    } else {
	        return $isFuture
	            ? __( 'in a few seconds', 'decker' )
	            : __( 'a few seconds ago', 'decker' );
	    }
	}
}
