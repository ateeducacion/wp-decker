<?php
/**
 * The file that defines the events custom post type
 *
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes/custom-post-types
 */

/**
 * Class to handle the events custom post type
 */
class Decker_Events {

	/**
	 * Persists event meta.
	 *
	 * @var Decker_Event_Meta_Saver
	 */
	private $meta_saver;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}


	/**
	 * Define the hooks for the events custom post type
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 10, 3 );

		// The edit-screen meta boxes own their own hook.
		new Decker_Event_Meta_Box();

		// Meta persistence owns its own hook.
		$this->meta_saver = new Decker_Event_Meta_Saver();

		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 10, 2 );

		// Hide visibility options.

		// Admin list-table presentation owns its own hooks.
		new Decker_Event_Admin_Screen();

		// Add columns to admin.

		add_action( 'rest_after_insert_decker_event', array( $this, 'rest_fix_datetime_meta' ), 10, 3 );
	}

	/**
	 * Register the custom post type
	 */
	public function register_post_type() {

		$labels = array(
			'name'               => _x( 'Events', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Event', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Events', 'admin menu', 'decker' ),
			'name_admin_bar'     => _x( 'Event', 'add new on admin bar', 'decker' ),
			'add_new'            => _x( 'Add New', 'event', 'decker' ),
			'add_new_item'       => __( 'Add New Event', 'decker' ),
			'new_item'           => __( 'New Event', 'decker' ),
			'edit_item'          => __( 'Edit Event', 'decker' ),
			'view_item'          => __( 'View Event', 'decker' ),
			'all_items'          => __( 'Events', 'decker' ),
			'search_items'       => __( 'Search Events', 'decker' ),
			'not_found'          => __( 'No events found.', 'decker' ),
			'not_found_in_trash' => __( 'No events found in Trash.', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'           => true,
			'show_in_menu'      => 'edit.php?post_type=decker_task',
			'query_var'         => true,
			'rewrite'           => false,
			'capability_type'   => 'post',
			'map_meta_cap'      => true,
			'has_archive'       => true,
			'hierarchical'      => false,
			'menu_position'     => null,
			'supports'          => array( 'title', 'editor', 'author', 'custom-fields' ),
			'show_in_rest'      => true,
		);

		register_post_type( 'decker_event', $args );

		$this->register_post_meta();
	}

	/**
	 * Register the custom post type meta fields
	 */
	public function register_post_meta() {
		$meta_fields = array(
			'event_allday' => array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'schema' => array(
					'type' => 'boolean',
				),
			),
			'event_start' => array(
				'type' => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_event_datetime' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_end' => array(
				'type' => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_event_datetime' ),
				'schema' => array(
					'type' => 'string',
					'format' => 'date-time',
				),
			),
			'event_location' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event_url' => array(
				'type' => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
			'event_category' => array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'event_assigned_users' => array(
				'type' => 'array',
				'single' => true,
				'show_in_rest' => array(
					'schema' => array(
						'type' => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => function ( $users ) {
					if ( is_string( $users ) ) {
						$users = explode( ',', $users );
					}
					return array_map( 'absint', (array) $users );
				},
			),
		);

		foreach ( $meta_fields as $key => $args ) {
			$default_args = array(
				'single' => true,
				'show_in_rest' => true,
			);

			register_post_meta(
				'decker_event',
				$key,
				array_merge( $default_args, $args )
			);
		}
	}

	/**
	 * Forces the classic editor for decker_event post type.
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type The post type being checked.
	 * @return bool Whether to use the classic editor.
	 */
	public function force_classic_editor( $use_block_editor, $post_type ) {
		if ( 'decker_event' === $post_type ) {
			return false; // Deactivate gutenberg editor.
		}
		return $use_block_editor;
	}

	/**
	 * Restricts REST API access for decker_event post type.
	 *
	 * @param mixed           $result The pre-calculated result to return.
	 * @param WP_REST_Server  $rest_server The REST server instance.
	 * @param WP_REST_Request $request The current REST request.
	 * @return mixed WP_Error if unauthorized, otherwise the original result.
	 */
	public function restrict_rest_access( $result, $rest_server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wp/v2/decker_event' ) === 0 ) {
				   // Use the specific capability of the CPT.
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to access this resource.', 'decker' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	/**
	 * Sanitize a UTC date‑time.
	 *
	 * - Raw date (YYYY‑MM‑DD) → treat as all‑day, keep it as is.
	 * - ISO‑8601 UTC with “Z” → keep as is.
	 * - Anything else → normalize to 'Y-m-d H:i:s' (UTC).
	 *
	 * @param string $value      Raw input.
	 * @param string $meta_key   Meta key (unused, needed for callback signature).
	 * @param string $object_type Object type (unused).
	 * @return string Sanitized value or empty string on failure.
	 */
	public static function sanitize_event_datetime( $value, $meta_key = '', $object_type = '' ) {
		$value = sanitize_text_field( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		// YYYY‑MM‑DD → all‑day.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		// ISO 8601 UTC already.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return $value;
		}

		// Accept "YYYY‑MM‑DD HH:MM:SS" or "YYYY‑MM‑DDTHH:MM:SSZ".
		$normalized = str_replace( 'T', ' ', rtrim( $value, 'Z' ) );

		try {
			$dt = new DateTime( $normalized, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Normalize date metadata after a REST operation.
	 *
	 * – For all-day: trim the time.
	 * – For timed events:
	 *     · Convert ISO ('T…Z') to 'Y-m-d H:i:s'.
	 *     · If only the date arrives, add '00:00:00'.
	 *     · If end ≤ start, adjust end = start + 1h.
	 *
	 * @param WP_Post         $post the post.
	 * @param WP_REST_Request $request the request.
	 * @param bool            $update is updating.
	 */
	public function rest_fix_datetime_meta( $post, $request, $update ) {

		$allday = get_post_meta( $post->ID, 'event_allday', true );

		// 1. All‑day  →   YYYY‑MM‑DD.
		if ( '1' === $allday ) {
			foreach ( array( 'event_start', 'event_end' ) as $key ) {
				$val = get_post_meta( $post->ID, $key, true );
				update_post_meta( $post->ID, $key, substr( $val, 0, 10 ) );
			}
			return;
		}

		// 2. Timed events.
		$start = get_post_meta( $post->ID, 'event_start', true );
		$end   = get_post_meta( $post->ID, 'event_end', true );

		// a) ISO → space.
		foreach ( array(
			'start' => 'event_start',
			'end' => 'event_end',
		) as $var => $meta ) {
			$$var = preg_match( '/Z$/', $$var )
			? str_replace( array( 'T', 'Z' ), array( ' ', '' ), $$var )
			: $$var;

						// b) date only → midnight.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $$var ) ) {
				$$var .= ' 00:00:00';
			}

			update_post_meta( $post->ID, $meta, $$var );
		}

		   // c) end empty or ≤ start  →  +1h.
		if ( ! $end || strtotime( $end ) <= strtotime( $start ) ) {
			$end = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS );
			update_post_meta( $post->ID, 'event_end', $end );
		}
	}

	/**
	 * Process and save meta data from a data array.
	 *
	 * Kept as the public entry point; the pipeline lives in
	 * Decker_Event_Meta_Saver.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data to save (e.g., from $_POST).
	 */
	public function process_and_save_meta( $post_id, $data ) {
		$this->meta_saver->process_and_save_meta( $post_id, $data );
	}

	/**
	 * Save event meta data from admin form submission.
	 *
	 * Kept for back-compat with callers that invoked the hook callback
	 * directly; the registered hook now points at the saver itself.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	public function save_event_meta( $post_id, $post, $update ) {
		$this->meta_saver->save_event_meta( $post_id, $post, $update );
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
			'cache_results'  => true, // Enable post caching.
		);

		$args = wp_parse_args( $args, $default_args );
		$posts = get_posts( $args );

		// After getting posts, load all metadata into cache at once.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		   update_meta_cache( 'post', $post_ids ); // One extra query for all metadata.

		// Avoid modifying native WP_Post objects.
		$events = array();
		foreach ( $posts as $post ) {
			$events[] = array(
				'post' => $post,
				'meta' => get_post_meta( $post->ID ), // get_post_meta() will use cache and avoid additional queries.

			);
		}

		return $events;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Events' ) ) {
	new Decker_Events();
}
