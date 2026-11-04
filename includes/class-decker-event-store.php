<?php
/**
 * Canonical iCalendar resources and their queryable WordPress projection.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Shared calendar validation and persistence. */
class Decker_Event_Store {
	/** Validate a complete calendar resource without repairing invalid input.
	 *
	 * @param string $data Calendar bytes.
	 * @return Sabre\VObject\Component\VCalendar
	 * @throws Exception Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\BadRequest Invalid or forbidden calendar operation.
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
			$uid = null;
			$masters = 0;
			foreach ( $calendar->getComponents() as $event ) {
				if ( 'VTIMEZONE' === $event->name ) {
					continue;
				}
				if ( 'VEVENT' !== $event->name || ! isset( $event->{'DTSTART'}, $event->{'UID'} ) ) {
					throw new Exception( 'VEVENT requires UID and DTSTART.' );
				}
				if ( null !== $uid && $uid !== (string) $event->{'UID'} ) {
					throw new Exception( 'One UID per resource is required.' );
				}
				$uid = (string) $event->{'UID'};
				foreach ( $event->children() as $property ) {
					if ( ! $property instanceof Sabre\VObject\Property\ICalendar\DateTime ) {
						continue; }
					foreach ( $property->getParts() as $value ) {
						if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})Z?)?$/D', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) || ( isset( $parts[4] ) && ( (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ) ) ) {
							throw new Exception( 'Invalid calendar date.' );
						}
					}
				}

				$masters += isset( $event->{'RECURRENCE-ID'} ) ? 0 : 1;
				if ( isset( $event->{'DTEND'}, $event->{'DURATION'} ) ) {
					throw new Exception( 'DTEND and DURATION are mutually exclusive.' );
				}
				if ( isset( $event->{'RRULE'}, $event->{'RDATE'} ) ) {
					throw new Exception( 'Combined RRULE and RDATE are unsupported by the recurrence engine.' ); }
				foreach ( $event->select( 'RDATE' ) as $rdate ) {
					if ( isset( $rdate['VALUE'] ) && 'PERIOD' === (string) $rdate['VALUE'] ) {
						throw new Exception( 'Period-valued RDATE is unsupported.' ); }
				}
				$start = $event->{'DTSTART'}->getDateTime();
				$end = self::end( $event );
				if ( $end < $start || ( isset( $event->{'DTEND'} ) && $end <= $start ) ) {
					throw new Exception( 'Invalid event interval.' );
				}
				foreach ( $event->select( 'RECURRENCE-ID' ) as $rid ) {
					if ( isset( $rid['RANGE'] ) ) {
						throw new Exception( 'RANGE exceptions are not supported.' );
					}
				}
			}
			if ( 1 !== $masters || '' === $uid ) {
				throw new Exception( 'Exactly one master event is required.' );
			}
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

	/** Return the master component.
	 *
	 * @param object $calendar Calendar.
	 * @return object
	 */
	public static function master( $calendar ) {
		foreach ( $calendar->select( 'VEVENT' ) as $event ) {
			if ( ! isset( $event->{'RECURRENCE-ID'} ) ) {
				return $event;
			}
		}
	}

	/** Obtain the canonical object, applying web changes only to represented fields.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function resource( $id ) {
		Decker_Dav::lock();
		$post = get_post( $id );
		$data = get_post_meta( $id, '_decker_ical', true );
		$calendar = $data ? self::parse( $data ) : new Sabre\VObject\Component\VCalendar();
		if ( ! $data ) {
			$calendar->add(
				'VEVENT',
				array(
					'UID' => wp_generate_uuid4() . '@decker',
					'DTSTAMP' => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
				)
			);
		}
		$event = self::master( $calendar );
		$before = $calendar->serialize();
		if ( $data ) {
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

		$event->{'SUMMARY'} = $post->post_title;
		$event->{'DESCRIPTION'} = $post->post_content;
		$event->{'LOCATION'} = get_post_meta( $id, 'event_location', true );
		$event->{'URL'} = get_post_meta( $id, 'event_url', true );
		if ( ! $data ) {
			self::set_dates( $event, get_post_meta( $id, 'event_start', true ), get_post_meta( $id, 'event_end', true ), get_post_meta( $id, 'event_allday', true ) );
		}
		if ( ! $data || $before !== $calendar->serialize() ) {
			$event->{'DTSTAMP'} = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
			$data = $calendar->serialize();
			update_post_meta( $id, '_decker_ical', wp_slash( $data ) );
		}
		if ( ! get_post_meta( $id, '_decker_dav_uri', true ) ) {
			update_post_meta( $id, '_decker_dav_uri', wp_generate_uuid4() . '.ics' );
		}
		return $data;
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
		$format = $all_day ? 'Y-m-d' : 'Y-m-d H:i:s';
		$dates = array();
		foreach ( array( $start, $end ) as $value ) {
			if ( '' === $value && count( $dates ) ) {
				break;
			}
			$value = $all_day ? substr( $value, 0, 10 ) : str_replace( array( 'T', 'Z' ), array( ' ', '' ), $value );
			$value = preg_replace( '/\.\d+$/', '', $value );
			if ( ! $all_day && 16 === strlen( $value ) ) {
				$value .= ':00';
			}
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, new DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( $format ) !== $value ) {
				throw new Sabre\DAV\Exception\BadRequest( 'Invalid calendar date.' );
			}
			$dates[] = $date;
		}
		if ( isset( $dates[1] ) && $dates[1] <= $dates[0] ) {
			throw new Sabre\DAV\Exception\BadRequest( 'End must follow start.' );
		}
		unset( $event->{'DTSTART'}, $event->{'DTEND'}, $event->{'DURATION'} );
		foreach ( $dates as $index => $date ) {
			$event->add( $index ? 'DTEND' : 'DTSTART', $all_day ? $date->format( 'Ymd' ) : $date, $all_day ? array( 'VALUE' => 'DATE' ) : array() );
		}
	}

	/** Persist a DAV resource and refresh its WordPress projection.
	 *
	 * @param int    $id Existing ID or zero.
	 * @param string $uri DAV name.
	 * @param string $data Calendar bytes.
	 * @return int
	 * @throws Sabre\DAV\Exception\Conflict Invalid or forbidden calendar operation.
	 * @throws Sabre\DAV\Exception\ServiceUnavailable Invalid or forbidden calendar operation.
	 */
	public static function put( $id, $uri, $data ) {
		Decker_Dav::lock();
		$calendar = self::parse( $data );
		$event = self::master( $calendar );
		if ( $id && (string) self::master( self::parse( self::resource( $id ) ) )->{'UID'} !== (string) $event->{'UID'} ) {
			throw new Sabre\DAV\Exception\Conflict( 'UID cannot change.' );
		}
		$id = wp_insert_post(
			array(
				'ID' => $id,
				'post_type' => 'decker_event',
				'post_status' => 'publish',
				'post_title' => wp_slash( sanitize_text_field( (string) $event->{'SUMMARY'} ) ),
				'post_content' => wp_slash( wp_kses_post( (string) $event->{'DESCRIPTION'} ) ),
				'post_author' => $id ? get_post_field( 'post_author', $id ) : get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new Sabre\DAV\Exception\ServiceUnavailable( 'Unable to save event.' );
		}
		update_post_meta( $id, '_decker_dav_uri', $uri );
		update_post_meta( $id, '_decker_ical', wp_slash( $calendar->serialize() ) );
		self::project( $id, $event );
		if ( ! metadata_exists( 'post', $id, 'event_category' ) ) {
			update_post_meta( $id, 'event_category', 'bg-success' );
			update_post_meta( $id, 'event_assigned_users', array( get_current_user_id() ) );
		}
		return $id;
	}

	/** Project dates and represented text from the canonical component.
	 *
	 * @param int    $id Post ID.
	 * @param object $event Component.
	 */
	public static function project( $id, $event ) {
		$all_day = ! $event->{'DTSTART'}->hasTime();
		$format = $all_day ? 'Y-m-d' : 'Y-m-d H:i:s';
		update_post_meta( $id, 'event_allday', $all_day ? '1' : '0' );
		update_post_meta( $id, 'event_start', $event->{'DTSTART'}->getDateTime()->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format ) );
		update_post_meta( $id, 'event_end', self::end( $event )->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format ) );
		update_post_meta( $id, 'event_location', sanitize_text_field( (string) $event->{'LOCATION'} ) );
		update_post_meta( $id, 'event_url', esc_url_raw( (string) $event->{'URL'} ) );
	}

	/** Validate a web date patch, preserving the timezone and recurrence tree.
	 *
	 * @param int    $id Post ID.
	 * @param object $calendar Calendar.
	 * @param array  $data Patch.
	 * @throws Sabre\DAV\Exception\Conflict Invalid or forbidden calendar operation.
	 */
	public static function patch_dates( $id, $calendar, $data ) {
		$event = self::master( $calendar );
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

	/** Apply validated web metadata while preserving opaque calendar properties.
	 *
	 * @param int   $id Post ID.
	 * @param array $data Metadata patch.
	 */
	public static function web_meta( $id, $data ) {
		Decker_Dav::lock();
		$stored = get_post_meta( $id, '_decker_ical', true );
		if ( ! $stored && empty( $data['event_start'] ) && ! array_key_exists( 'event_start', $data ) ) {
			foreach ( array( 'event_category', 'event_assigned_users', 'event_location', 'event_url' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					update_post_meta( $id, $key, 'event_assigned_users' === $key ? array_filter( array_map( 'absint', (array) $data[ $key ] ) ) : ( 'event_url' === $key ? esc_url_raw( $data[ $key ] ) : sanitize_text_field( $data[ $key ] ) ) ); }
			}
			return;
		}
		$calendar = $stored ? self::parse( $stored ) : new Sabre\VObject\Component\VCalendar();
		if ( ! $stored ) {
			$calendar->add(
				'VEVENT',
				array(
					'UID' => wp_generate_uuid4() . '@decker',
					'DTSTAMP' => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
				)
			);
		}
		$event = self::master( $calendar );
		$changes_dates = array_intersect( array_keys( $data ), array( 'event_start', 'event_end', 'event_allday' ) );
		if ( $changes_dates ) {
			self::patch_dates( $id, $calendar, $data );
		}

		foreach ( array(
			'event_location' => 'LOCATION',
			'event_url' => 'URL',
		) as $key => $property ) {
			$data[ $key ] = $data[ $key ] ?? get_post_meta( $id, $key, true );
			if ( isset( $data[ $key ] ) ) {
				$event->$property = 'event_url' === $key ? esc_url_raw( $data[ $key ] ) : sanitize_text_field( $data[ $key ] );
			}
		}
		self::parse( $calendar->serialize() );
		update_post_meta( $id, '_decker_ical', wp_slash( $calendar->serialize() ) );
		self::project( $id, $event );
		foreach ( array( 'event_category', 'event_assigned_users' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, $key, 'event_category' === $key ? sanitize_text_field( $data[ $key ] ) : array_filter( array_map( 'absint', (array) $data[ $key ] ) ) );
			}
		}
	}
}
