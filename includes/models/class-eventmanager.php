<?php
/**
 * The Event Manager class
 *
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

/**
 * The Event Manager class
 */
class EventManager {

	/**
	 * Create or update an event
	 *
	 * @param int      $id             The event ID (0 for new event).
	 * @param string   $title          The event title.
	 * @param string   $description    The event description.
	 * @param DateTime $start_date     The event start date/time.
	 * @param DateTime $end_date       The event end date/time.
	 * @param string   $location       The event location.
	 * @param string   $url            The event URL.
	 * @param string   $category       The event category.
	 * @param array    $assigned_users Array of assigned user IDs.
	 * @return int|WP_Error The event ID or WP_Error on failure.
	 */
	public static function create_or_update_event(
		$id,
		$title,
		$description,
		DateTime $start_date,
		DateTime $end_date,
		$location = '',
		$url = '',
		$category = 'bg-primary',
		$assigned_users = array()
	) {
		// Prepare post data.
		$post_data = array(
			'post_title'   => $title,
			'post_content' => $description,
			'post_type'    => 'decker_event',
			'post_status'  => 'publish',
		);

		if ( $id > 0 ) {
			$post_data['ID'] = $id;
			$event_id = wp_update_post( $post_data );
		} else {
			$event_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}

		// Update meta fields.
		update_post_meta( $event_id, '_event_start', $start_date->format( 'Y-m-d\TH:i:s' ) );
		update_post_meta( $event_id, '_event_end', $end_date->format( 'Y-m-d\TH:i:s' ) );
		update_post_meta( $event_id, '_event_location', $location );
		update_post_meta( $event_id, '_event_url', $url );
		update_post_meta( $event_id, '_event_category', $category );
		update_post_meta( $event_id, '_event_assigned_users', $assigned_users );

		return $event_id;
	}

	/**
	 * Get an event by ID
	 *
	 * @param int $id The event ID.
	 * @return Event|null The event object or null if not found.
	 */
	public static function get_event( $id ) {
		$post = get_post( $id );

		if ( ! $post || 'decker_event' !== $post->post_type ) {
			return null;
		}

		$start_date = new DateTime( get_post_meta( $id, '_event_start', true ) );
		$end_date = new DateTime( get_post_meta( $id, '_event_end', true ) );
		$location = get_post_meta( $id, '_event_location', true );
		$url = get_post_meta( $id, '_event_url', true );
		$category = get_post_meta( $id, '_event_category', true );
		$assigned_users = get_post_meta( $id, '_event_assigned_users', true );

		return Event::create(
			$id,
			$post->post_title,
			$post->post_content,
			$start_date,
			$end_date,
			$location,
			$url,
			$category,
			$assigned_users ? $assigned_users : array()
		);
	}

	/**
	 * Get all events
	 *
	 * @param array $args Optional. Additional arguments for WP_Query.
	 * @return array Array of Event objects.
	 */
	public static function get_events( $args = array() ) {
		$default_args = array(
			'post_type'      => 'decker_event',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		);

		$args = wp_parse_args( $args, $default_args );
		$posts = get_posts( $args );
		$events = array();

		foreach ( $posts as $post ) {
			$event = self::get_event( $post->ID );
			if ( $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Delete an event
	 *
	 * @param int $id The event ID.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_event( $id ) {
		$post = get_post( $id );

		if ( ! $post || 'decker_event' !== $post->post_type ) {
			return false;
		}

		return (bool) wp_delete_post( $id, true );
	}
}
