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
		return Decker_Ical_Validator::parse( $data );
	}

	/** Resolve RFC 5545 implicit ends and duration.
	 *
	 * @param object $event Event.
	 * @return DateTimeImmutable
	 */
	public static function end( $event ) {
		return Decker_Event_Dates::end( $event );
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
		$calendar = self::calendar( $data );
		$event = self::master( $calendar );
		$before = $calendar->serialize();
		if ( $data ) {
			Decker_Event_Dates::reconcile( $id, $calendar );
		}

		foreach ( array(
			'SUMMARY' => $post->post_title,
			'DESCRIPTION' => $post->post_content,
			'LOCATION' => get_post_meta( $id, 'event_location', true ),
			'URL' => get_post_meta( $id, 'event_url', true ),
		) as $property => $value ) {
			if ( (string) $event->$property !== $value ) {
				$event->$property = $value;
			}
		}
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
		Decker_Event_Dates::set_dates( $event, $start, $end, $all_day );
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
		Decker_Event_Dates::patch_dates( $id, $calendar, $data );
	}

	/** Apply validated web metadata while preserving opaque calendar properties.
	 *
	 * @param int   $id Post ID.
	 * @param array $data Metadata patch.
	 */
	public static function web_meta( $id, $data ) {
		Decker_Dav::lock();
		$stored = get_post_meta( $id, '_decker_ical', true );
		if ( ! $stored && ! array_key_exists( 'event_start', $data ) ) {
			self::save_fields( $id, $data, array( 'event_category', 'event_assigned_users', 'event_location', 'event_url' ) );
			return;
		}
		$calendar = self::calendar( $stored );
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
			$event->$property = self::sanitize_field( $key, $data[ $key ] );
		}
		self::parse( $calendar->serialize() );
		update_post_meta( $id, '_decker_ical', wp_slash( $calendar->serialize() ) );
		self::project( $id, $event );
		self::save_fields( $id, $data, array( 'event_category', 'event_assigned_users' ) );
	}

	/** Read the canonical resource or initialize a new stable event identity.
	 *
	 * @param string $stored Stored resource, or empty for a new event.
	 * @return object Calendar.
	 */
	private static function calendar( $stored ) {
		if ( $stored ) {
			return self::parse( $stored );
		}
		$calendar = new Sabre\VObject\Component\VCalendar();
		$calendar->add(
			'VEVENT',
			array(
				'UID' => wp_generate_uuid4() . '@decker',
				'DTSTAMP' => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			)
		);
		return $calendar;
	}

	/** Persist only metadata explicitly present in a patch.
	 *
	 * @param int   $id Post ID.
	 * @param array $data Metadata patch.
	 * @param array $fields Field names to persist.
	 */
	private static function save_fields( $id, $data, $fields ) {
		foreach ( $fields as $key ) {
			if ( isset( $data[ $key ] ) ) {
				update_post_meta( $id, $key, self::sanitize_field( $key, $data[ $key ] ) );
			}
		}
	}

	/** Apply the existing sanitizer for each represented metadata field.
	 *
	 * @param string $key Field name.
	 * @param mixed  $value Field value.
	 * @return mixed Sanitized value.
	 */
	private static function sanitize_field( $key, $value ) {
		if ( 'event_assigned_users' === $key ) {
			return array_filter( array_map( 'absint', (array) $value ) );
		}
		return 'event_url' === $key ? esc_url_raw( $value ) : sanitize_text_field( $value );
	}
}
