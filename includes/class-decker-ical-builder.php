<?php
/**
 * ICal (RFC 5545) serializer for Decker calendar feeds.
 *
 * @link    https://github.com/ateeducacion/wp-decker
 * @since   1.0.0
 *
 * @package Decker
 * @subpackage Decker/includes
 */

/**
 * Stateless iCal serializer extracted from Decker_Calendar::generate_ical().
 *
 * The byte-level output of this class is asserted by the DeckerCalendarICS*
 * test suites; keep the string assembly (terminators, folding rules, escaping)
 * identical to the original implementation.
 */
final class Decker_Ical_Builder {

	/**
	 * Human-readable type names used for the calendar name (X-WR-CALNAME/PRODID).
	 *
	 * @var array
	 */
	private $type_names;

	/**
	 * Constructor.
	 *
	 * @param array $type_names Map of type slug to translated display name.
	 */
	public function __construct( array $type_names ) {
		$this->type_names = $type_names;
	}

	/**
	 * Build the full iCal document for the given events.
	 *
	 * @param array  $events Array of events.
	 * @param string $type   Event type.
	 * @return string
	 */
	public function build( array $events, $type = '' ) {
		$ical   = $this->build_ical_header( $type );
		$events = $this->sort_events_by_start( $events );

		foreach ( $events as $event ) {
			$ical .= $this->build_vevent( $event, $type );
		}

		$ical .= "END:VCALENDAR\r\n";
		return $ical;
	}

	/**
	 * Build the VCALENDAR header block.
	 *
	 * @param string $type Event type.
	 * @return string
	 */
	private function build_ical_header( $type ) {
		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";

		// Add calendar name property.
		$calendar_name = 'Decker';
		if ( $type && isset( $this->type_names[ $type ] ) ) {
			$calendar_name = 'Decker - ' . $this->type_names[ $type ];
		}

		$ical .= 'PRODID:-//' . $this->ical_escape( $calendar_name ) . "//NONSGML Decker//EN\r\n"; // Key property.

		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";
		$ical .= "X-WR-TIMEZONE:UTC\r\n";

		// Set the refresh interval.
		$ttl   = 'PT1H'; // 1h.
		$ical .= "REFRESH-INTERVAL;VALUE=DURATION:$ttl\r\n";
		$ical .= "X-PUBLISHED-TTL:$ttl\r\n";

		// Add a period at the end of the comment.
		$ical .= 'X-WR-CALNAME:' . $this->ical_escape( $calendar_name ) . "\r\n";
		$ical .= 'X-NAME:' . $this->ical_escape( $calendar_name ) . "\r\n";

		return $ical;
	}

	/**
	 * Sort events by ascending start date.
	 *
	 * Sorting on a by-value copy ensures deterministic output and alignment with
	 * test expectations. usort is stable on PHP >= 8.0, so equal-start events
	 * keep their insertion order (events before tasks, each in query order).
	 *
	 * @param array $events Array of events.
	 * @return array Sorted events.
	 */
	private function sort_events_by_start( $events ) {
		usort(
			$events,
			function ( $a, $b ) {
				return strtotime( $a['start'] ) <=> strtotime( $b['start'] );
			}
		);

		return $events;
	}

	/**
	 * Build a single VEVENT block.
	 *
	 * @param array  $event Event data.
	 * @param string $type  Event type.
	 * @return string
	 */
	private function build_vevent( $event, $type ) {
		$ical  = "BEGIN:VEVENT\r\n";
		$ical .= 'UID:' . $event['id'] . "@decker\r\n";
		// Important to let the clients to update an event if modified.
		$ical .= 'SEQUENCE:' . get_post_modified_time( 'U', true, $event['post_id'] ) . "\r\n";
		$ical .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";

		$ical .= $this->build_vevent_dates( $event );

		$ical .= 'SUMMARY:' . $this->ical_escape( $this->build_summary_prefix( $event, $type ) . $event['title'] ) . "\r\n";

		// Split description into 75 character chunks.
		$ical .= $this->fold_ical_property( 'DESCRIPTION', $this->ical_escape( $event['description'] ) );

		if ( ! empty( $event['location'] ) ) {
			$ical .= $this->fold_ical_property( 'LOCATION', $this->ical_escape( $event['location'] ) );
		}

		if ( ! empty( $event['url'] ) ) {
			$ical .= $this->fold_ical_property( 'URL', esc_url_raw( $event['url'] ) );
		}

		$ical .= $this->build_attendee_lines( $event['assigned_users'] );

		$ical .= "END:VEVENT\r\n";

		return $ical;
	}

	/**
	 * Build the DTSTART/DTEND lines for an event.
	 *
	 * @param array $event Event data.
	 * @return string
	 */
	private function build_vevent_dates( $event ) {

		// Format dates for all-day events or with time.
		if ( ! empty( $event['allDay'] ) && ( true === $event['allDay'] || '1' === $event['allDay'] || 1 === $event['allDay'] ) ) {
			// For all-day events, use format VALUE=DATE and DTEND on the next day.
			// Per RFC 5545 DTEND;VALUE=DATE is exclusive, so emit the day after
			// the stored (inclusive) end date. This also makes single-day all-day
			// events span exactly one day (DTEND = DTSTART + 1 day).
			$start_date = gmdate( 'Ymd', strtotime( $event['start'] ) );
			$end_date   = gmdate( 'Ymd', strtotime( $event['end'] ) + DAY_IN_SECONDS );

			return 'DTSTART;VALUE=DATE:' . $start_date . "\r\n"
				. 'DTEND;VALUE=DATE:' . $end_date . "\r\n";
		}

		$start = gmdate( 'Ymd\THis\Z', strtotime( $event['start'] ) );
		$end   = gmdate( 'Ymd\THis\Z', strtotime( $event['end'] ) );

		return 'DTSTART:' . $start . "\r\n"
			. 'DTEND:' . $end . "\r\n";
	}

	/**
	 * Build the assigned-users prefix for the SUMMARY (events only, not tasks).
	 *
	 * @param array  $event Event data.
	 * @param string $type  Event type.
	 * @return string The display-name list followed by ' » ', or '' when no prefix applies.
	 */
	private function build_summary_prefix( $event, $type ) {
		if ( empty( $type ) || empty( $event['assigned_users'] ) ) {
			return '';
		}

		$display_names = array();
		foreach ( $event['assigned_users'] as $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {

				// Collect display names.
				$display_names[] = $user->display_name;

			}
		}

		// We use » because : had encoding problems in iCal.
		return implode( ', ', $display_names ) . ' » ';
	}

	/**
	 * Fold a property VALUE at 74 chars (property prefix excluded from the width).
	 *
	 * Used for DESCRIPTION/LOCATION/URL. The str_split + empty() sequence is kept
	 * verbatim to preserve the PHP-version edge for empty values.
	 *
	 * @param string $property      Property name (e.g. 'DESCRIPTION').
	 * @param string $escaped_value Already-escaped property value.
	 * @return string
	 */
	private function fold_ical_property( $property, $escaped_value ) {
		$chunks = str_split( $escaped_value, 74 ); // 74 to account for the space after continuation.
		if ( empty( $chunks ) ) {
			return '';
		}

		$ical = $property . ':' . array_shift( $chunks ) . "\r\n";
		foreach ( $chunks as $chunk ) {
			$ical .= ' ' . $chunk . "\r\n";
		}

		return $ical;
	}

	/**
	 * Fold a fully assembled line at 74 chars (property name counted).
	 *
	 * Used only for ATTENDEE, which folds the whole line including the property.
	 *
	 * @param string $line The complete line to fold.
	 * @return string
	 */
	private function fold_ical_line( $line ) {
		$chunks = str_split( $line, 74 );
		$ical   = array_shift( $chunks ) . "\r\n";
		foreach ( $chunks as $chunk ) {
			$ical .= ' ' . $chunk . "\r\n";
		}

		return $ical;
	}

	/**
	 * Build the ATTENDEE lines for the assigned users.
	 *
	 * @param array $assigned_users List of user IDs.
	 * @return string
	 */
	private function build_attendee_lines( $assigned_users ) {
		$ical = '';

		// Add assigned users as attendees with proper line folding.
		if ( ! empty( $assigned_users ) ) {
			foreach ( $assigned_users as $user_id ) {
				$user = get_userdata( $user_id );
				if ( $user && $user->user_email ) {
					$attendee = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;'
						. 'RSVP=TRUE:mailto:' . $user->user_email;
					$ical    .= $this->fold_ical_line( $attendee );
				}
			}
		}

		return $ical;
	}

	/**
	 * Escape special characters for iCal format.
	 *
	 * @param string $string The string to escape.
	 * @return string
	 */
	private function ical_escape( $string ) {
		$string = str_replace( array( "\r\n", "\n", "\r" ), "\\n", $string );
		$string = str_replace( array( ',', ';', ':' ), array( '\,', '\;', '\:' ), $string );
		return $string;
	}
}
