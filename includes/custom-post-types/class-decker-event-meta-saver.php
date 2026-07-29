<?php
/**
 * Persists event meta from a form submission.
 *
 * Owns the save_post hook for events and the whole pipeline behind it: the
 * all-day flag, the date normalization rules for all-day and timed events,
 * the optional text fields and the assigned users. Decker_Events keeps thin
 * public delegators so existing callers and tests keep their entry point.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Event_Meta_Saver
 */
class Decker_Event_Meta_Saver {

	/**
	 * Hook the admin-form save.
	 */
	public function __construct() {
		add_action( 'save_post_decker_event', array( $this, 'save_event_meta' ), 10, 3 );
	}

	/**
	 * Process and save meta data from a data array.
	 * This is the core logic, decoupled from $_POST for testability.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data to save (e.g., from $_POST).
	 */
	public function process_and_save_meta( $post_id, $data ) {
		// Save all-day event status and capture the flag for branching.
		$allday = $this->save_allday_flag( $post_id, $data );

		// Process and save dates.
		$start_input = $this->get_date_input( $data, 'event_start' );
		$end_input   = $this->get_date_input( $data, 'event_end' );

		if ( $allday ) {
			$this->save_allday_dates( $post_id, $start_input, $end_input );
		} else {
			$this->save_timed_dates( $post_id, $start_input, $end_input );
		}

		// Save other fields.
		$this->save_optional_text_fields( $post_id, $data );

		// Save assigned users.
		$this->save_assigned_users( $post_id, $data );
	}

	/**
	 * Save the all-day flag as the '1'/'0' string and return it as a bool.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data being saved.
	 * @return bool Whether the event is all-day.
	 */
	private function save_allday_flag( $post_id, $data ) {
		$allday = ! empty( $data['event_allday'] ) && filter_var( $data['event_allday'], FILTER_VALIDATE_BOOLEAN );

		update_post_meta( $post_id, 'event_allday', (bool) $allday ? '1' : '0' );

		return $allday;
	}

	/**
	 * Read a single date input, unslashed and sanitized.
	 *
	 * @param array  $data The data being saved.
	 * @param string $key  The data key to read.
	 * @return string The sanitized value, or '' when absent.
	 */
	private function get_date_input( $data, $key ) {
		return isset( $data[ $key ] ) ? sanitize_text_field( wp_unslash( $data[ $key ] ) ) : '';
	}

	/**
	 * Save the all-day start/end dates (date part only).
	 *
	 * @param int    $post_id     The post ID.
	 * @param string $start_input The sanitized start input.
	 * @param string $end_input   The sanitized end input.
	 */
	private function save_allday_dates( $post_id, $start_input, $end_input ) {
		$start_input = substr( $start_input, 0, 10 );
		$end_input   = substr( $end_input, 0, 10 );

		// Only date part matters.
		$start_date = $start_input ? gmdate( 'Y-m-d', strtotime( $start_input . ' UTC' ) ) : '';
		$end_date   = $end_input ? gmdate( 'Y-m-d', strtotime( $end_input . ' UTC' ) ) : '';

		// Enforce end ≥ start.
		if ( $start_date && $end_date && strtotime( $end_date ) < strtotime( $start_date ) ) {
			$end_date = $start_date;
		}
		if ( $start_date && ! $end_date ) {
			$end_date = $start_date;
		}

		update_post_meta( $post_id, 'event_start', $start_date );
		update_post_meta( $post_id, 'event_end', $end_date );
	}

	/**
	 * Save the timed start/end dates as raw UTC strings.
	 *
	 * @param int    $post_id     The post ID.
	 * @param string $start_input The sanitized start input.
	 * @param string $end_input   The sanitized end input.
	 */
	private function save_timed_dates( $post_id, $start_input, $end_input ) {
		// A missing event_end is copied from start + 1 h.
		if ( '' === $end_input && '' !== $start_input ) {
			$end_input = gmdate( 'Y-m-d H:i:s', strtotime( $start_input . ' UTC' ) + HOUR_IN_SECONDS );
		}

				   // If it comes in YYYY‑MM‑DD format → append 00:00:00.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_input ) ) {
			$start_input .= ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_input ) ) {
					   $end_input .= ' 01:00:00'; // will be corrected below if appropriate.
		}

		list( $start_input, $end_input ) = $this->resolve_timed_range( $start_input, $end_input );

		$start_input = gmdate( 'Y-m-d H:i:00', strtotime( $start_input . ' UTC' ) );
		$end_input   = gmdate( 'Y-m-d H:i:00', strtotime( $end_input . ' UTC' ) );

		// Save raw UTC strings.
		update_post_meta( $post_id, 'event_start', $start_input );
		update_post_meta( $post_id, 'event_end', $end_input );
	}

	/**
	 * Resolve a timed start/end pair: fix malformed start and end ≤ start.
	 *
	 * Timed event: if end missing or end ≤ start, default to start + 1 h (UTC).
	 *
	 * @param string $start_input The normalized start input.
	 * @param string $end_input   The normalized end input.
	 * @return array The resolved array( $start_input, $end_input ).
	 */
	private function resolve_timed_range( $start_input, $end_input ) {
		// 2a) Malformed start?
		if ( $start_input && false === strtotime( $start_input . ' UTC' ) ) {
			$start_input = gmdate( 'Y-m-d H:i:s', 0 );
			$end_input   = gmdate( 'Y-m-d H:i:s', HOUR_IN_SECONDS );
		} else {
			// 2b) Missing end → start + 1 h.
			if ( $start_input && ! $end_input ) {
				$start_ts = strtotime( $start_input . ' UTC' );
				$end_input = gmdate( 'Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS );
			}

			// 2c) End ≤ start → adjust to start + 1 h.
			if ( $start_input && $end_input && strtotime( $end_input . ' UTC' ) <= strtotime( $start_input . ' UTC' ) ) {
				$start_ts  = strtotime( $start_input . ' UTC' );
				$end_input = gmdate( 'Y-m-d H:i:s', $start_ts + HOUR_IN_SECONDS );
			}
		}

		return array( $start_input, $end_input );
	}

	/**
	 * Save the optional text fields, preserving any absent key.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data being saved.
	 */
	private function save_optional_text_fields( $post_id, $data ) {
		$fields_to_save = array(
			'event_location' => 'sanitize_text_field',
			'event_url'      => 'esc_url_raw',
			'event_category' => 'sanitize_text_field',
		);

		foreach ( $fields_to_save as $key => $sanitize_callback ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $post_id, $key, call_user_func( $sanitize_callback, wp_unslash( $data[ $key ] ) ) );
			}
		}
	}

	/**
	 * Save the assigned users; an absent key clears them.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data being saved.
	 */
	private function save_assigned_users( $post_id, $data ) {
		$assigned_users = array();
		if ( isset( $data['event_assigned_users'] ) && is_array( $data['event_assigned_users'] ) ) {
			$assigned_users = array_map( 'intval', $data['event_assigned_users'] );
		}
		update_post_meta( $post_id, 'event_assigned_users', array_filter( $assigned_users ) );
	}

	/**
	 * Save event meta data from admin form submission.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	public function save_event_meta( $post_id, $post, $update ) {
		// Check nonce, user permissions, and autosave.
		if ( ! isset( $_POST['decker_event_meta_box_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['decker_event_meta_box_nonce'] ), 'decker_event_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call the main logic function with the $_POST data.
		$this->process_and_save_meta( $post_id, $_POST );
	}
}
