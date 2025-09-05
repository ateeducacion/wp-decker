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
	 * Reverse map: CSS class → slug
	 *
	 * @var array
	 * */
	private $category_to_slug = array();

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
		add_action( 'init', array( $this, 'add_ical_endpoint' ) );

		// Cache invalidation.
		$this->category_to_slug = array_flip( $this->type_map );

		add_action( 'save_post_decker_event', array( $this, 'flush_cache_for_event' ), 10, 1 );
		add_action( 'save_post_decker_task', array( $this, 'flush_cache_for_task' ), 10, 1 );

		add_action( 'deleted_post', array( $this, 'flush_cache_on_delete' ), 10 );
		add_action( 'trashed_post', array( $this, 'flush_cache_on_delete' ), 10 );

		add_action( 'updated_post_meta', array( $this, 'flush_cache_on_event_meta' ), 10, 4 );
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

	/**
	 * Return a cached iCal string or regenerate it and cache it.
	 *
	 * @param string $type Event type ( '', 'event', 'absence', ... ).
	 * @return string iCal file contents.
	 */
	public function get_cached_ical( $type = '' ) {
		$key     = self::TRANSIENT_PREFIX . ( $type ? $type : 'all' );
		$cached  = get_transient( $key );

		// During tests we always bypass the cache for determinism.
		if ( false !== $cached && ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			return $cached;
		}

		$events  = $this->get_events( $type );
		$ics     = $this->generate_ical( $events, $type );

		// Store in cache; object-cache users get it persistent, otros usan options.
		set_transient( $key, $ics, self::CACHE_TTL );

		return $ics;
	}

	/**
	 * Flush ONLY the mixed .ics when tasks change.
	 *
	 * @param int $post_id the post id.
	 */
	public function flush_cache_for_task( $post_id = 0 ) {
		delete_transient( self::TRANSIENT_PREFIX . 'all' );
	}

	/**
	 * Flush ONLY the cache that matches the event's current category,
	 * plus the global «all» variante.
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
		delete_transient( self::TRANSIENT_PREFIX . 'all' );

		// Detect the event category and clear only that .ics.
		$category_css = get_post_meta( $post->ID, 'event_category', true );
		if ( $category_css && isset( $this->category_to_slug[ $category_css ] ) ) {
			$slug = $this->category_to_slug[ $category_css ];
			delete_transient( self::TRANSIENT_PREFIX . $slug );
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
	 * Add rewrite rule for iCal endpoint
	 */
	public function add_ical_endpoint() {
		add_rewrite_endpoint( 'decker-calendar', EP_ROOT );
		add_action( 'template_redirect', array( $this, 'handle_ical_request' ) );
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
	 * Handle iCal calendar request.
	 */
	public function handle_ical_request() {
		global $wp_query;

		   // Accept both an internal query var and a GET parameter (?decker-calendar).
		if ( ! isset( $wp_query->query_vars['decker-calendar'] ) && ! isset( $_GET['decker-calendar'] ) ) {
			return;
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		/*
		Direct generation.
		$events = $this->get_events( $type );
		$ical = $this->generate_ical( $events, $type );
		*/

		// Cached generation.
		$ical   = $this->get_cached_ical( $type );

		   // Avoid “Cannot modify header information” warnings when output has already started
		// (e.g., in PHPUnit) by checking headers_sent() before sending headers.
		if ( ! headers_sent() && ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="decker-calendar.ics"' );
			// Ignore cache, because we are going to cache using traseints.
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' ); // Date in past.
			header( 'Pragma: no-cache' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safe iCal content
		echo $ical;

		   // During tests (CLI/PHPUnit or WP-CLI) we do not stop execution.
		// Only exit on normal web requests to avoid extra content.
		if ( php_sapi_name() !== 'cli' && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
			exit;
		}

		return;
	}

	/**
	 * Get events from the decker_event post type.
	 *
	 * @param string $type Event type.
	 * @return array
	 */
	public function get_events( $type = '' ) {
		$events     = array();
		$event_args = array();

		if ( $type && isset( $this->type_map[ $type ] ) ) {
			$event_args['meta_query'] = array(
				array(
					'key'   => 'event_category',
					'value' => $this->type_map[ $type ],
				),
			);
		}

		// Get regular events.
		$event_posts = Decker_Events::get_events( $event_args );
		foreach ( $event_posts as $event_data ) {
			$post = $event_data['post'];
			$meta = $event_data['meta'];

				   // Ensure that the dates are valid before adding them.
			if ( ! empty( $meta['event_start'] ) && ! empty( $meta['event_end'] ) ) {

				$all_day = isset( $meta['event_allday'] ) ? $meta['event_allday'][0] : false;

				if ( ! $all_day ) {
					$start_iso = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $meta['event_start'][0] ) );
					$end_iso   = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $meta['event_end'][0] ) );
				} else {
					$start_iso = $meta['event_start'][0]; // YYYY-MM-DD.
					$end_iso   = $meta['event_end'][0];
				}

				$events[] = array(
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
		}

		   // Add tasks only when not filtering by a specific type.
		if ( empty( $type ) ) {
			// Get published tasks.
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
                } // End tasks conditional

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

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";

		// Add calendar name property.
		$calendar_name = 'Decker';
		$type_names = $this->get_type_names();
		if ( $type && isset( $type_names[ $type ] ) ) {
			$calendar_name = 'Decker - ' . $type_names[ $type ];
		}

		$ical .= 'PRODID:-//' . $this->ical_escape( $calendar_name ) . "//NONSGML Decker//EN\r\n"; // ¡Clave!

		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";
		$ical .= "X-WR-TIMEZONE:UTC\r\n";

		// Set the refresch interval.
		$ttl = 'PT1H'; // 1h.
		$ical .= "REFRESH-INTERVAL;VALUE=DURATION:$ttl\r\n";
		$ical .= "X-PUBLISHED-TTL:$ttl\r\n";

		   // Add a period at the end of the comment.
		$ical .= 'X-WR-CALNAME:' . $this->ical_escape( $calendar_name ) . "\r\n";
		$ical .= 'X-NAME:' . $this->ical_escape( $calendar_name ) . "\r\n";

		// Sort events by ascending start date to ensure deterministic results
		// and align with test expectations.
		usort(
			$events,
			function ( $a, $b ) {
				return strtotime( $a['start'] ) <=> strtotime( $b['start'] );
			}
		);

		foreach ( $events as $event ) {
			$ical .= "BEGIN:VEVENT\r\n";
			$ical .= 'UID:' . $event['id'] . "@decker\r\n";
			// Important to let the clients to update an event if modified.
			$ical .= 'SEQUENCE:' . get_post_modified_time( 'U', true, $event['post_id'] ) . "\r\n";
			$ical .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";

				   // Format dates for all-day events or with time.
			if ( ! empty( $event['allDay'] ) && ( true === $event['allDay'] || '1' === $event['allDay'] || 1 === $event['allDay'] ) ) {
						   // For all-day events, use format VALUE=DATE and DTEND on the next day.
				$start_date = gmdate( 'Ymd', strtotime( $event['start'] ) );
				$end_date = gmdate( 'Ymd', strtotime( $event['end'] ) );

				$ical .= 'DTSTART;VALUE=DATE:' . $start_date . "\r\n";
				$ical .= 'DTEND;VALUE=DATE:' . $end_date . "\r\n";
			} else {

				$start = gmdate( 'Ymd\THis\Z', strtotime( $event['start'] ) );
				$end   = gmdate( 'Ymd\THis\Z', strtotime( $event['end'] ) );
				$ical .= 'DTSTART:' . $start . "\r\n";
				$ical .= 'DTEND:' . $end . "\r\n";
			}

			// Add assigned users as prefix to the event title (but not on tasks).
			$users_prefix = '';
			if ( ! empty( $type ) && ! empty( $event['assigned_users'] ) ) {
				$display_names = array();
				foreach ( $event['assigned_users'] as $user_id ) {
					$user = get_userdata( $user_id );
					if ( $user && $user->user_email ) {

						// Collect display names.
						$display_names[] = $user->display_name;

					}
				}
				// We use » because : had codification problemas on ical.
				$users_prefix = implode( ', ', $display_names ) . ' » ';
			}

			$ical .= 'SUMMARY:' . $this->ical_escape( $users_prefix . $event['title'] ) . "\r\n";
			// Split description into 75 character chunks.
			$description = $this->ical_escape( $event['description'] );
			$desc_chunks = str_split( $description, 74 ); // 74 to account for the space after continuation.
			if ( ! empty( $desc_chunks ) ) {
				$ical .= 'DESCRIPTION:' . array_shift( $desc_chunks ) . "\r\n";
				foreach ( $desc_chunks as $chunk ) {
					$ical .= ' ' . $chunk . "\r\n";
				}
			}

			if ( ! empty( $event['location'] ) ) {
				$location   = $this->ical_escape( $event['location'] );
				$loc_chunks = str_split( $location, 74 );
				$ical      .= 'LOCATION:' . array_shift( $loc_chunks ) . "\r\n";
				foreach ( $loc_chunks as $chunk ) {
					$ical .= ' ' . $chunk . "\r\n";
				}
			}

			if ( ! empty( $event['url'] ) ) {
				$url        = esc_url_raw( $event['url'] );
				$url_chunks = str_split( $url, 74 );
				$ical      .= 'URL:' . array_shift( $url_chunks ) . "\r\n";
				foreach ( $url_chunks as $chunk ) {
					$ical .= ' ' . $chunk . "\r\n";
				}
			}

			// Add assigned users as attendees with proper line folding.
			if ( ! empty( $event['assigned_users'] ) ) {
				foreach ( $event['assigned_users'] as $user_id ) {
					$user = get_userdata( $user_id );
					if ( $user && $user->user_email ) {
						$attendee   = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;'
							. 'RSVP=TRUE:mailto:' . $user->user_email;
						$att_chunks = str_split( $attendee, 74 );
						$ical      .= array_shift( $att_chunks ) . "\r\n";
						foreach ( $att_chunks as $chunk ) {
							$ical .= ' ' . $chunk . "\r\n";
						}
					}
				}
			}

			$ical .= "END:VEVENT\r\n";
		}

		$ical .= "END:VCALENDAR\r\n";
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
