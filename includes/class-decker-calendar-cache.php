<?php
/**
 * Caches the generated iCal feeds and invalidates them on content changes.
 *
 * One transient per feed type plus the mixed "all" feed. Saving, deleting or
 * trashing an event or task, or touching event meta the feed depends on,
 * flushes exactly the variants that could have changed.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Calendar_Cache
 */
class Decker_Calendar_Cache {

	/**
	 * The calendar that generates a feed on cache miss.
	 *
	 * @var Decker_Calendar
	 */
	private $calendar;

	/**
	 * Maps event category CSS classes back to feed type slugs.
	 *
	 * @var array
	 */
	private $category_to_slug;

	/**
	 * Hook the invalidation points.
	 *
	 * @param Decker_Calendar $calendar The calendar that generates feeds.
	 */
	public function __construct( Decker_Calendar $calendar ) {
		$this->calendar         = $calendar;
		$this->category_to_slug = array_flip( $calendar->get_type_map() );

		add_action( 'save_post_decker_event', array( $this, 'flush_cache_for_event' ), 10, 1 );
		add_action( 'save_post_decker_task', array( $this, 'flush_cache_for_task' ), 10, 1 );

		add_action( 'deleted_post', array( $this, 'flush_cache_on_delete' ), 10 );
		add_action( 'trashed_post', array( $this, 'flush_cache_on_delete' ), 10 );

		add_action( 'updated_post_meta', array( $this, 'flush_cache_on_event_meta' ), 10, 4 );
	}

	/**
	 * Return a cached iCal string or regenerate it and cache it.
	 *
	 * @param string $type Event type ( '', 'event', 'absence', ... ).
	 * @return string iCal file contents.
	 */
	public function get_cached_ical( $type = '' ) {
		$key     = Decker_Calendar::TRANSIENT_PREFIX . ( $type ? $type : 'all' );
		$cached  = get_transient( $key );

		// During tests we always bypass the cache for determinism.
		if ( false !== $cached && ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			return $cached;
		}

		$events  = $this->calendar->get_events( $type );
		$ics     = $this->calendar->generate_ical( $events, $type );

		// Store in cache; object-cache users get it persistent, the rest fall back to options.
		set_transient( $key, $ics, Decker_Calendar::CACHE_TTL );

		return $ics;
	}

	/**
	 * Flush ONLY the mixed .ics when tasks change.
	 *
	 * @param int $post_id the post id.
	 */
	public function flush_cache_for_task( $post_id = 0 ) {
		delete_transient( Decker_Calendar::TRANSIENT_PREFIX . 'all' );
	}

	/**
	 * Flush ONLY the cache that matches the event's current category,
	 * plus the global «all» variant.
	 *
	 * @param int|WP_Post $post_id Post ID or object.
	 */
	public function flush_cache_for_event( $post_id ) {
		// Bail if not a decker_event.
		$post = get_post( $post_id );
		if ( ! $post || 'decker_event' !== $post->post_type ) {
			return;
		}

		// Always clear the mixed .ics.
		delete_transient( Decker_Calendar::TRANSIENT_PREFIX . 'all' );

		// Detect the event category and clear only that .ics.
		$category_css = get_post_meta( $post->ID, 'event_category', true );
		if ( $category_css && isset( $this->category_to_slug[ $category_css ] ) ) {
			$slug = $this->category_to_slug[ $category_css ];
			delete_transient( Decker_Calendar::TRANSIENT_PREFIX . $slug );
		}
	}

	/**
	 * Universal delete/trash handler for both CPTs.
	 *
	 * @param int $post_id Post ID being deleted/trashed.
	 */
	public function flush_cache_on_delete( $post_id ) {
		$type = get_post_type( $post_id );

		if ( 'decker_event' === $type ) {
			$this->flush_cache_for_event( $post_id );
		} elseif ( 'decker_task' === $type ) {
			$this->flush_cache_for_task();
		}
	}

	/**
	 * Purge caches when a relevant meta key of a decker_event changes.
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Key being changed.
	 * @param mixed  $_unused   Value (unused).
	 */
	public function flush_cache_on_event_meta( $meta_id, $post_id, $meta_key, $_unused ) {

		/* We care only about the decker_event CPT */
		if ( 'decker_event' == get_post_type( $post_id ) ) {

			/* Invalidate when the key matters for the iCal */
			if ( 0 === strpos( $meta_key, 'event_' ) ) {
				$this->flush_cache_for_event( $post_id );
			}
		} else if ( 'decker_task' == get_post_type( $post_id ) ) {

			$this->flush_cache_for_task();

		}
	}
}
