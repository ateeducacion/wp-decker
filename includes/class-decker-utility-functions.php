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

	// Log level constants
	const LOG_LEVEL_DEBUG = 1;
	const LOG_LEVEL_INFO = 2;
	const LOG_LEVEL_ERROR = 3;

	// Option name for storing the log level in the database
	const LOG_LEVEL_OPTION_NAME = 'decker_log_level';

	/**
	 * Retrieve the current log level from the database.
	 *
	 * @return int The current log level.
	 */
	public static function get_current_log_level() {
		// Get the log level from the database, default to LOG_LEVEL_INFO if not set
		$log_level = get_option( self::LOG_LEVEL_OPTION_NAME, self::LOG_LEVEL_INFO );

		// Ensure the log level is a valid integer
		if ( ! in_array( $log_level, array( self::LOG_LEVEL_DEBUG, self::LOG_LEVEL_INFO, self::LOG_LEVEL_ERROR ) ) ) {
			$log_level = self::LOG_LEVEL_INFO;
		}

		return $log_level;
	}

	/**
	 * Set the log level in the database.
	 *
	 * @param int $log_level The desired log level.
	 */
	public static function set_log_level( $log_level ) {
		if ( in_array( $log_level, array( self::LOG_LEVEL_DEBUG, self::LOG_LEVEL_INFO, self::LOG_LEVEL_ERROR ) ) ) {
			update_option( self::LOG_LEVEL_OPTION_NAME, $log_level );
		}
	}

	/**
	 * Logs a message to both the PHP error log and the Decker log.
	 *
	 * @param mixed $log The message (or array) to log.
	 * @param int   $log_level The log level of the message (default: LOG_LEVEL_INFO).
	 */
	public static function write_log( $log, $log_level = self::LOG_LEVEL_INFO ) {
		// Get the current log level from the database
		$current_log_level = self::get_current_log_level();

		// Check if the current log level allows logging this message
		if ( $log_level < $current_log_level ) {
			return; // Don't log the message if the log level is too low
		}

		// Convert log to string if it is an array or object
		if ( is_array( $log ) || is_object( $log ) ) {
			$log_message = print_r( $log, true );
		} else {
			$log_message = $log;
		}

		// Add log level to the message
		$log_prefix = '';
		switch ( $log_level ) {
			case self::LOG_LEVEL_DEBUG:
				$log_prefix = '[DEBUG] ';
				break;
			case self::LOG_LEVEL_INFO:
				$log_prefix = '[INFO] ';
				break;
			case self::LOG_LEVEL_ERROR:
				$log_prefix = '[ERROR] ';
				break;
		}

		$log_message = $log_prefix . $log_message;

		// Log to the PHP error log
		error_log( 'Decker: ' . $log_message );

		// Log to the Decker log
		self::log_to_decker_log( $log_message );
	}

	/**
	 * Writes a message to the Decker log stored in the WordPress options table.
	 *
	 * @param string $message The message to log.
	 */
	public static function log_to_decker_log( $message ) {
		$current_log = get_option( 'decker_log', '' );

		// Add a timestamp to each log entry
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$new_entry = '[' . $timestamp . '] ' . $message;

		// Append the new entry to the existing log
		$updated_log = $current_log . "\n" . $new_entry;

		// Save the updated log
		update_option( 'decker_log', $updated_log );
	}


	/**
	 * Example utility function.
	 *
	 * @param string $input The input string.
	 * @return string The modified string.
	 */
	public static function example_function( $input ) {
		return strtoupper( $input );
	}

	/**
	 * Muestra un mensaje de error en el área de administración de WordPress.
	 *
	 * @return void
	 */
	public static function decker_admin_error_notice() {
		$error_msg = get_transient( 'decker_admin_error_msg' );

		if ( $error_msg ) {
			echo '<div class="notice notice-error"><p>' . wp_kses( $error_msg, array( 'strong' => array() ) ) . '</p></div>';
			delete_transient( 'decker_admin_error_msg' );
		}
	}

	/**
	 * Establece un mensaje de error para mostrar en el área de administración.
	 *
	 * @param string $error_msg Mensaje de error a mostrar.
	 * @return void
	 */
	public static function decker_set_admin_error_notice( $error_msg ) {
		set_transient( 'decker_admin_error_msg', $error_msg, 60 );
	}

	/**
	 * Obtener una opción de WordPress.
	 *
	 * @param string $option_name El nombre de la opción.
	 * @return mixed El valor de la opción.
	 */
	public static function decker_get_option( $option_name ) {
		return get_option( $option_name );
	}

	/**
	 * Another example utility function.
	 *
	 * @return bool True.
	 */
	public static function example_utility_function() {
		return true;
	}

	public static function getRelativeTime( $dateString ) {

		if ( empty( $dateString ) ) {
			return '';
		}

		// Verify if the date is valid
		$dueDate = DateTime::createFromFormat( 'Y-m-d H:i:s', $dateString ) ?: new DateTime( $dateString );

		$errors = DateTime::getLastErrors();
		if ( ! $dueDate || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return ''; // Do nothing if the date is not valid
		}

		$now = new DateTime();
		$interval = $now->diff( $dueDate );
		$isFuture = $dueDate > $now; // Check if the date is in the future

		if ( $interval->y > 0 ) {
			return $isFuture ? sprintf( __( 'in %d year%s', 'text-domain' ), $interval->y, $interval->y > 1 ? 's' : '' ) : sprintf( __( 'more than %d year%s ago', 'text-domain' ), $interval->y, $interval->y > 1 ? 's' : '' );
		} elseif ( $interval->m > 0 ) {
			return $isFuture ? sprintf( __( 'in %d month%s', 'text-domain' ), $interval->m, $interval->m > 1 ? 's' : '' ) : sprintf( __( 'more than %d month%s ago', 'text-domain' ), $interval->m, $interval->m > 1 ? 's' : '' );
		} elseif ( $interval->d > 0 ) {
			return $isFuture ? sprintf( __( 'in %d day%s', 'text-domain' ), $interval->d, $interval->d > 1 ? 's' : '' ) : sprintf( __( 'more than %d day%s ago', 'text-domain' ), $interval->d, $interval->d > 1 ? 's' : '' );
		} elseif ( $interval->h > 0 ) {
			return $isFuture ? sprintf( __( 'in %d hour%s', 'text-domain' ), $interval->h, $interval->h > 1 ? 's' : '' ) : sprintf( __( 'more than %d hour%s ago', 'text-domain' ), $interval->h, $interval->h > 1 ? 's' : '' );
		} elseif ( $interval->i > 0 ) {
			return $isFuture ? sprintf( __( 'in %d minute%s', 'text-domain' ), $interval->i, $interval->i > 1 ? 's' : '' ) : sprintf( __( 'more than %d minute%s ago', 'text-domain' ), $interval->i, $interval->i > 1 ? 's' : '' );
		} else {
			return $isFuture ? __( 'in a few seconds', 'text-domain' ) : __( 'more than a few seconds ago', 'text-domain' );
		}
	}
}
