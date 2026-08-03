<?php
/**
 * Calendar functionality for Decker.
 *
 * @link    https://github.com/ateeducacion/wp-decker
 * @since   1.0.0
 *
 * @package Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Calendar class to handle iCal and JSON endpoints.
 */
class Decker_Calendar {

	/**
	 * Prefix for transient keys
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'decker_calendar_ics_';

	/**
	 * Fallback TTL (one day).
	 *
	 * @var int
	 */
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Mapping between slug event types and stored category values.
	 *
	 * @var array
	 */
	private $type_map = array(
		'event'  => 'bg-success',
		'absence'  => 'bg-info',
		'warning'  => 'bg-warning',
		'alert'    => 'bg-danger',
	);

	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// The HTTP feed and its cache own their own hooks.
		new Decker_Calendar_Ical_Feed( new Decker_Calendar_Cache( $this ) );
	}

	/**
	 * Get the type mapping for event categories.
	 *
	 * @return array
	 */
	public function get_type_map() {
		return $this->type_map;
	}

	/**
	 * Get human-readable names for event types with translations.
	 *
	 * @return array
	 */
	private function get_type_names() {
		return array(
			'event'  => __( 'Events', 'decker' ),
			'absence'  => __( 'Absences', 'decker' ),
			'warning'  => __( 'Warnings', 'decker' ),
			'alert'    => __( 'Alerts', 'decker' ),
		);
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'decker/v1',
			'/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_calendar_json' ),
				'permission_callback' => array( $this, 'get_calendar_permissions_check' ),
			)
		);

		// Register dedicated endpoints for each event type.
		foreach ( array_keys( $this->type_map ) as $type_slug ) {
			register_rest_route(
				'decker/v1',
				'/calendar/' . $type_slug,
				array(
					'methods'             => 'GET',
					'callback'            => function ( WP_REST_Request $request ) use ( $type_slug ) {
						$request->set_param( 'type', $type_slug );
						return $this->get_calendar_json( $request );
					},
					'permission_callback' => array( $this, 'get_calendar_permissions_check' ),
				)
			);
		}
	}

	/**
	 * Check if user has permission to access calendar data
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function get_calendar_permissions_check( $request ) {

		// Check REST API nonce first.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// First check if user is logged in.
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}

		// If not logged in, check for token.
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			// Look for a user with this calendar token.
			$users = get_users(
				array(
					'meta_key' => 'decker_calendar_token',
					'meta_value' => $token,
					'number' => 1,
				)
			);

			if ( ! empty( $users ) ) {
				return true;
			}
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permissions to access this data.', 'decker' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle JSON calendar request.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_calendar_json( $request ) {
		$type   = $request->get_param( 'type' );
		$events = $this->get_events( $type );
		return rest_ensure_response( $events );
	}

	/**
	 * Get events from the decker_event post type.
	 *
	 * @param string $type Event type.
	 * @return array
	 */
	public function get_events( $type = '' ) {
		$events = array();

		// Get regular events.
		$event_posts = Decker_Events::get_events( $this->build_event_query_args( $type ) );
		foreach ( $event_posts as $event_data ) {
			$row = $this->map_event_post_to_array( $event_data );
			if ( null !== $row ) {
				$events[] = $row;
			}
		}

		// Add tasks only when not filtering by a specific type.
		if ( empty( $type ) ) {
			$events = array_merge( $events, $this->get_task_calendar_events() );
		}

		return $events;
	}

	/**
	 * Build the WP_Query args used to fetch the events for a feed type.
	 *
	 * @param string $type Event type slug.
	 * @return array Query args with a meta_query when the type is known, empty otherwise.
	 */
	private function build_event_query_args( $type ) {
		if ( $type && isset( $this->type_map[ $type ] ) ) {
			return array(
				'meta_query' => array(
					array(
						'key'   => 'event_category',
						'value' => $this->type_map[ $type ],
					),
				),
			);
		}

		return array();
	}

	/**
	 * Map a single Decker_Events::get_events() row to the calendar event array.
	 *
	 * @param array $event_data Row with 'post' and 'meta' keys.
	 * @return array|null Calendar event array, or null when start/end dates are missing.
	 */
	private function map_event_post_to_array( $event_data ) {
		$post = $event_data['post'];
		$meta = $event_data['meta'];

		// Ensure that the dates are valid before adding them.
		if ( empty( $meta['event_start'] ) || empty( $meta['event_end'] ) ) {
			return null;
		}

		$all_day             = isset( $meta['event_allday'] ) ? $meta['event_allday'][0] : false;
		list( $start_iso, $end_iso ) = $this->format_event_dates( $meta, $all_day );

		return array(
			'post_id'        => $post->ID,
			'id'             => 'event_' . $post->ID, // Prefix to distinguish from tasks.
			'title'          => $post->post_title,
			'description'    => $post->post_content,
			'allDay'         => $all_day,
			'start'          => $start_iso,
			'end'            => $end_iso,
			'location'       => isset( $meta['event_location'] ) ? $meta['event_location'][0] : '',
			'url'            => isset( $meta['event_url'] ) ? $meta['event_url'][0] : '',
			'className'      => isset( $meta['event_category'] ) ? $meta['event_category'][0] : '',
			'assigned_users' => isset( $meta['event_assigned_users'][0] ) ? maybe_unserialize( $meta['event_assigned_users'][0] ) : array(),
			// 'assigned_users' => $this->normalize_assigned_users( $meta ),
			'type'           => 'event',
		);
	}

	/**
	 * Resolve the start/end ISO strings for an event from its meta.
	 *
	 * @param array $meta    Event meta as returned by get_post_meta().
	 * @param mixed $all_day All-day flag (loose truthiness preserved).
	 * @return array{0:string,1:string} The start and end values.
	 */
	private function format_event_dates( $meta, $all_day ) {
		if ( ! $all_day ) {
			return array(
				gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $meta['event_start'][0] ) ),
				gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $meta['event_end'][0] ) ),
			);
		}

		// YYYY-MM-DD raw passthrough for all-day events.
		return array( $meta['event_start'][0], $meta['event_end'][0] );
	}

	/**
	 * Build the calendar event arrays for published tasks with a due date.
	 *
	 * @return array List of task event arrays.
	 */
	private function get_task_calendar_events() {
		$events = array();

		$task_manager = new TaskManager();
		$tasks        = $task_manager->get_tasks_by_status( 'publish' );

		foreach ( $tasks as $task ) {
			$board       = $task->get_board();
			$board_color = $board ? $board->color : '';

			// Only add tasks that have a due date.
			if ( $task->duedate ) {
				$events[] = array(
					'post_id' => $task->ID,
					'id'             => 'task_' . $task->ID, // Prefix to distinguish from events.
					'title'          => $task->title,
					'description'    => $task->description,
					'allDay'         => true,
					'start'          => $task->duedate->format( 'Y-m-d\TH:i:s' ),
					'end'            => $task->duedate->format( 'Y-m-d\TH:i:s' ),
					'color'          => $board_color,
					'className'      => $board_color,
					'max_priority'   => $task->max_priority,
					'assigned_users' => array_map(
						function ( $user ) {
							return intval( $user->ID );
						},
						$task->assigned_users
					),
					'type'           => 'task',
				);
			}
		}

		return $events;
	}

	/**
	 * Generate iCal format from events.
	 *
	 * @param array  $events Array of events.
	 * @param string $type   Event type.
	 * @return string
	 */
	public function generate_ical( $events, $type = '' ) {
		return ( new Decker_Ical_Builder( $this->get_type_names() ) )->build( $events, $type );
	}

	/**
	 * Generate iCal string without headers (for unit tests).
	 *
	 * @param string $type Optional type filter.
	 * @return string
	 */
	public function generate_ical_string( $type = '' ) {
		$events = $this->get_events( $type );
		return $this->generate_ical( $events, $type );
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Calendar' ) ) {
	new Decker_Calendar();
}
