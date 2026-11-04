<?php
/**
 * Event date validation and WordPress projection reconciliation.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Shared exclusive-end and recurrence-preserving date rules. */
class Decker_Event_Dates {
	/** Resolve RFC 5545 implicit ends and duration.
	 *
	 * @param object $event Event.
	 * @return DateTimeImmutable
	 */
	public static function end( $event ) {
		$start = $event->{'DTSTART'}->getDateTime();
		if ( isset( $event->{'DTEND'} ) ) {
			return $event->{'DTEND'}->getDateTime(); }
		if ( isset( $event->{'DURATION'} ) ) {
			return $start->add( $event->{'DURATION'}->getDateInterval() ); }
		return $event->{'DTSTART'}->hasTime() ? $start : $start->modify( '+1 day' );
	}

	/** Set validated dates using exclusive all-day ends.
	 *
	 * @param object $event Component.
	 * @param string $start Start.
	 * @param string $end End or empty.
	 * @param bool   $all_day All-day flag.
	 * @throws Sabre\DAV\Exception\BadRequest Invalid or forbidden calendar operation.
	 */
	public static function set_dates( $event, $start, $end, $all_day ) {
		$dates = array( self::parse_date( $start, $all_day ) );
		if ( '' !== $end ) {
			$dates[] = self::parse_date( $end, $all_day );
			if ( $dates[1] <= $dates[0] ) {
				throw new Sabre\DAV\Exception\BadRequest( 'End must follow start.' );
			}
		}
		unset( $event->{'DTSTART'}, $event->{'DTEND'}, $event->{'DURATION'} );
		self::add_date( $event, 'DTSTART', $dates[0], $all_day );
		if ( isset( $dates[1] ) ) {
			self::add_date( $event, 'DTEND', $dates[1], $all_day );
		}
	}

	/** Normalize supported web input formats and reject invalid dates.
	 *
	 * @param string $value Date input.
	 * @param bool   $all_day Whether only the calendar date is used.
	 * @return DateTimeImmutable
	 * @throws Sabre\DAV\Exception\BadRequest Invalid calendar date.
	 */
	private static function parse_date( $value, $all_day ) {
		$format = $all_day ? 'Y-m-d' : 'Y-m-d H:i:s';
		$value = $all_day ? substr( $value, 0, 10 ) : str_replace( array( 'T', 'Z' ), array( ' ', '' ), $value );
		$value = preg_replace( '/\.\d+$/', '', $value );
		if ( ! $all_day && 16 === strlen( $value ) ) {
			$value .= ':00';
		}
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( $format ) !== $value ) {
			throw new Sabre\DAV\Exception\BadRequest( 'Invalid calendar date.' );
		}
		return $date;
	}

	/** Write the correct iCalendar value type for a validated date.
	 *
	 * @param object            $event Event component.
	 * @param string            $property DTSTART or DTEND.
	 * @param DateTimeImmutable $date Validated date.
	 * @param bool              $all_day Whether to write VALUE=DATE.
	 */
	private static function add_date( $event, $property, $date, $all_day ) {
		$event->add( $property, $all_day ? $date->format( 'Ymd' ) : $date, $all_day ? array( 'VALUE' => 'DATE' ) : array() );
	}

	/** Validate a web date patch, preserving the timezone and recurrence tree.
	 *
	 * @param int    $id Post ID.
	 * @param object $calendar Calendar.
	 * @param array  $data Patch.
	 * @throws Sabre\DAV\Exception\Conflict Invalid or forbidden calendar operation.
	 */
	public static function patch_dates( $id, $calendar, $data ) {
		$event = Decker_Event_Store::master( $calendar );
		$copy = clone $event;
		self::set_dates( $copy, $data['event_start'] ?? get_post_meta( $id, 'event_start', true ), $data['event_end'] ?? ( isset( $event->{'DTEND'} ) ? get_post_meta( $id, 'event_end', true ) : '' ), $data['event_allday'] ?? get_post_meta( $id, 'event_allday', true ) );
		if ( isset( $event->{'DTSTART'} ) && $copy->{'DTSTART'}->getDateTime() == $event->{'DTSTART'}->getDateTime() && self::end( $copy ) == self::end( $event ) && $copy->{'DTSTART'}->hasTime() === $event->{'DTSTART'}->hasTime() ) {
			return; }
		if ( isset( $event->{'RRULE'} ) || isset( $event->{'RDATE'} ) || count( $calendar->select( 'VEVENT' ) ) > 1 ) {
			throw new Sabre\DAV\Exception\Conflict( 'Edit recurring dates in a CalDAV client.' ); }
		unset( $event->{'DTSTART'}, $event->{'DTEND'}, $event->{'DURATION'} );
		$event->add( clone $copy->{'DTSTART'} );
		if ( isset( $copy->{'DTEND'} ) ) {
			$event->add( clone $copy->{'DTEND'} ); }
	}

	/** Reconcile metadata changes made outside the calendar store.
	 *
	 * @param int    $id Post ID.
	 * @param object $calendar Calendar.
	 */
	public static function reconcile( $id, $calendar ) {
		$event = Decker_Event_Store::master( $calendar );
		$format = $event->{'DTSTART'}->hasTime() ? 'Y-m-d H:i:s' : 'Y-m-d';
		$utc = new DateTimeZone( 'UTC' );
		$start = $event->{'DTSTART'}->getDateTime()->setTimezone( $utc )->format( $format );
		$end = self::end( $event )->setTimezone( $utc )->format( $format );
		if ( get_post_meta( $id, 'event_start', true ) !== $start || get_post_meta( $id, 'event_end', true ) !== $end ) {
			self::patch_dates(
				$id,
				$calendar,
				array(
					'event_start' => get_post_meta( $id, 'event_start', true ),
					'event_end' => get_post_meta( $id, 'event_end', true ),
					'event_allday' => get_post_meta( $id, 'event_allday', true ),
				)
			);
		}
	}
}
