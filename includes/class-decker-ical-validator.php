<?php
/**
 * Strict validation of supported calendar resources.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Rejects unsupported data without repairing client input. */
class Decker_Ical_Validator {
	/** Validate a complete resource, keeping protocol errors free of client data.
	 *
	 * @param string $data Calendar bytes.
	 * @return Sabre\VObject\Component\VCalendar
	 * @throws Exception Invalid resource size or calendar properties.
	 * @throws Sabre\DAV\Exception\BadRequest Invalid or unsupported resource.
	 */
	public static function parse( $data ) {
		try {
			if ( strlen( $data ) > 1048576 ) {
				throw new Exception( 'Resource too large.' );
			}
			$calendar = Sabre\VObject\Reader::read( $data );
			if ( 'VCALENDAR' !== $calendar->name || isset( $calendar->{'METHOD'} ) ) {
				throw new Exception( 'Only calendar resources are supported.' );
			}
			self::validate_components( $calendar );
			foreach ( $calendar->validate() as $issue ) {
				if ( $issue['level'] >= 3 ) {
					throw new Exception( 'Invalid iCalendar property.' );
				}
			}
			return $calendar;
		} catch ( Throwable $error ) {
			throw new Sabre\DAV\Exception\BadRequest( 'Invalid or unsupported iCalendar resource.' );
		}
	}

	/** Enforce one UID and exactly one master, retaining timezone components.
	 *
	 * @param object $calendar Calendar.
	 * @throws Exception Invalid component identity or master count.
	 */
	private static function validate_components( $calendar ) {
		$uid = null;
		$masters = 0;
		foreach ( $calendar->getComponents() as $event ) {
			if ( 'VTIMEZONE' === $event->name ) {
				continue;
			}
			self::validate_event( $event );
			if ( null !== $uid && $uid !== (string) $event->{'UID'} ) {
				throw new Exception( 'One UID per resource is required.' );
			}
			$uid = (string) $event->{'UID'};
			$masters += isset( $event->{'RECURRENCE-ID'} ) ? 0 : 1;
		}
		if ( 1 !== $masters || '' === $uid ) {
			throw new Exception( 'Exactly one master event is required.' );
		}
	}

	/** Validate the event fields before any projection or persistence.
	 *
	 * @param object $event Component.
	 * @throws Exception Missing required event properties.
	 */
	private static function validate_event( $event ) {
		if ( 'VEVENT' !== $event->name || ! isset( $event->{'DTSTART'}, $event->{'UID'} ) ) {
			throw new Exception( 'VEVENT requires UID and DTSTART.' );
		}
		self::validate_dates( $event );
		self::validate_recurrence( $event );
		self::validate_interval( $event );
	}

	/** Check every date property before PHP can normalize invalid dates.
	 *
	 * @param object $event Event.
	 */
	private static function validate_dates( $event ) {
		foreach ( $event->children() as $property ) {
			if ( ! $property instanceof Sabre\VObject\Property\ICalendar\DateTime ) {
				continue;
			}
			foreach ( $property->getParts() as $value ) {
				self::validate_date_value( $value );
			}
		}
	}

	/** Reject malformed values, nonexistent dates and out-of-range clock fields.
	 *
	 * @param string $value Date property value.
	 * @throws Exception Invalid calendar date or time.
	 */
	private static function validate_date_value( $value ) {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/D', $value, $parts ) ) {
			throw new Exception( 'Invalid calendar date.' );
		}
		if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			throw new Exception( 'Invalid calendar date.' );
		}
		if ( isset( $parts[4] ) && ( (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) ) {
			throw new Exception( 'Invalid calendar date.' );
		}
	}

	/** Reject recurrence constructs the bundled engine cannot expand faithfully.
	 *
	 * @param object $event Event.
	 * @throws Exception Unsupported recurrence construct.
	 */
	private static function validate_recurrence( $event ) {
		if ( isset( $event->{'RRULE'}, $event->{'RDATE'} ) ) {
			throw new Exception( 'Combined RRULE and RDATE are unsupported by the recurrence engine.' );
		}
		foreach ( $event->select( 'RDATE' ) as $rdate ) {
			if ( isset( $rdate['VALUE'] ) && 'PERIOD' === (string) $rdate['VALUE'] ) {
				throw new Exception( 'Period-valued RDATE is unsupported.' );
			}
		}
		foreach ( $event->select( 'RECURRENCE-ID' ) as $rid ) {
			if ( isset( $rid['RANGE'] ) ) {
				throw new Exception( 'RANGE exceptions are not supported.' );
			}
		}
	}

	/** Enforce explicit ends while allowing RFC 5545 implicit durations.
	 *
	 * @param object $event Event.
	 * @throws Exception Conflicting or invalid event interval.
	 */
	private static function validate_interval( $event ) {
		if ( isset( $event->{'DTEND'}, $event->{'DURATION'} ) ) {
			throw new Exception( 'DTEND and DURATION are mutually exclusive.' );
		}
		$start = $event->{'DTSTART'}->getDateTime();
		$end = Decker_Event_Dates::end( $event );
		if ( $end < $start || ( isset( $event->{'DTEND'} ) && $end <= $start ) ) {
			throw new Exception( 'Invalid event interval.' );
		}
	}
}
