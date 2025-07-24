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
	 * Mapping between slug event types and stored category values.
	 *
	 * @var array
	 */
	private $type_map = array(
		'meeting'  => 'bg-success',
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
			'meeting'  => __( 'Meetings', 'decker' ),
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
	 * Add rewrite rule for iCal endpoint.
	 */
	public function add_ical_endpoint() {
		add_rewrite_endpoint( 'decker-calendar', EP_ROOT );
		add_action( 'template_redirect', array( $this, 'handle_ical_request' ) );
	}

	/**
	 * Check if user has permission to access calendar data.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error
	 */
	public function get_calendar_permissions_check( $request ) {
		// Las peticiones GET al calendario son públicas (no requieren login).
		if ( 'GET' === $request->get_method() ) {
			return true;
		}

		// Prioridad 1 – si el usuario está autenticado y puede leer, permitir.
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}

		// Prioridad 2 – verificar nonce REST (esto requiere usuario autenticado,
		// pero se mantiene por compatibilidad).
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		// If not logged in, check for token.
		$token = $request->get_param( 'token' );
		if ( ! empty( $token ) ) {
			// Look for a user with this calendar token.
			$users = get_users(
				array(
					'meta_key'   => 'decker_calendar_token',
					'meta_value' => $token,
					'number'     => 1,
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

		if ( ! isset( $wp_query->query_vars['decker-calendar'] ) ) {
			return;
		}

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		$events = $this->get_events( $type );
		$ical = $this->generate_ical( $events, $type );

		if ( ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="decker-calendar.ics"' );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safe iCal content
		echo $ical;
		exit;
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

			// Asegurarse de que las fechas sean válidas antes de agregarlas.
			if ( ! empty( $meta['event_start'] ) && ! empty( $meta['event_end'] ) ) {
				$events[] = array(
					'id'             => 'event_' . $post->ID, // Prefijo para distinguir de tareas.
					'title'          => $post->post_title,
					'description'    => $post->post_content,
					'allDay'         => isset( $meta['event_allday'] ) ? $meta['event_allday'][0] : false,
					'start'          => isset( $meta['event_start'] ) ? $meta['event_start'][0] : '',
					'end'            => isset( $meta['event_end'] ) ? $meta['event_end'][0] : '',
					'location'       => isset( $meta['event_location'] ) ? $meta['event_location'][0] : '',
					'url'            => isset( $meta['event_url'] ) ? $meta['event_url'][0] : '',
					'className'      => isset( $meta['event_category'] ) ? $meta['event_category'][0] : '',
					'assigned_users' => isset( $meta['event_assigned_users'][0] ) ? maybe_unserialize( $meta['event_assigned_users'][0] ) : array(),
					'type'           => 'event',
				);
			}
		}

		// Añadir tareas solo cuando no se está filtrando por un tipo concreto.
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
		} // Fin condicional tareas

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

		$timezone_string = get_option( 'timezone_string' );
		if ( ! $timezone_string ) {
			$timezone_string = 'UTC';
		}

		$ical  = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "PRODID:-//Decker//WordPress//EN\r\n";
		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";

		$ical .= $this->generate_vtimezone( $timezone_string );

		// Add calendar name property.
		$calendar_name = 'Decker';
		$type_names = $this->get_type_names();
		if ( $type && isset( $type_names[ $type ] ) ) {
			$calendar_name = 'Decker - ' . $type_names[ $type ];
		}
		// Añadir punto final a comentario.
		$ical .= 'X-WR-CALNAME:' . $this->ical_escape( $calendar_name ) . "\r\n";

		foreach ( $events as $event ) {
			$ical .= "BEGIN:VEVENT\r\n";
			$ical .= 'UID:' . $event['id'] . "@decker\r\n";
			$ical .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";

			// Formatear fechas para eventos de día completo o con hora.
			if ( ! empty( $event['allDay'] ) && ( true === $event['allDay'] || '1' === $event['allDay'] || 1 === $event['allDay'] ) ) {
				// Para eventos de día completo, usar formato VALUE=DATE y DTEND al día siguiente.
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

		$ical .= 'END:VCALENDAR';
		return $ical;
	}

	/**
	 * Generate the VTIMEZONE component for the iCal feed.
	 *
	 * This method builds a VTIMEZONE section based on the given timezone string,
	 * including both STANDARD and DAYLIGHT subcomponents with transitions.
	 * It calculates offsets and properly formats the timezone information
	 * required for compatibility with iCal consumers (like Google Calendar or Outlook).
	 *
	 * @param string $timezone_string The timezone identifier (e.g. 'Europe/Madrid').
	 * @return string The generated VTIMEZONE component as a string.
	 */
	public function generate_vtimezone( $timezone_string ) {

		$timezone = new DateTimeZone( $timezone_string );
		$transitions = $timezone->getTransitions( time() - 31536000, time() + 31536000 );

		$vtimezone = "BEGIN:VTIMEZONE\r\n";
		$vtimezone .= "TZID:$timezone_string\r\n";

		$prev_offset = null;

		foreach ( $transitions as $trans ) {
			// Usar timestamp para DTSTART (en UTC).
			$dtstart = gmdate( 'Ymd\THis\Z', $trans['ts'] );

			// Offset en segundos.
			$offset = $trans['offset'];
			$offset_hours = floor( abs( $offset ) / 3600 );
			$offset_minutes = ( abs( $offset ) % 3600 ) / 60;
			$sign = ( $offset >= 0 ) ? '+' : '-';
			$offset_formatted = sprintf( '%s%02d%02d', $sign, $offset_hours, $offset_minutes );

			// Para TZOFFSETFROM se usa el offset previo si existe, para TZOFFSETTO el actual.
			$offset_from = null !== $prev_offset ? $prev_offset : $offset_formatted;
			$offset_to = $offset_formatted;

			$tzname = isset( $trans['abbr'] ) ? $trans['abbr'] : ( $trans['isdst'] ? 'DST' : 'STD' );

			if ( $trans['isdst'] ) {
				$vtimezone .= "BEGIN:DAYLIGHT\r\n";
				$vtimezone .= "DTSTART:$dtstart\r\n";
				$vtimezone .= "TZOFFSETFROM:$offset_from\r\n";
				$vtimezone .= "TZOFFSETTO:$offset_to\r\n";
				$vtimezone .= "TZNAME:$tzname\r\n";
				$vtimezone .= "END:DAYLIGHT\r\n";
			} else {
				$vtimezone .= "BEGIN:STANDARD\r\n";
				$vtimezone .= "DTSTART:$dtstart\r\n";
				$vtimezone .= "TZOFFSETFROM:$offset_from\r\n";
				$vtimezone .= "TZOFFSETTO:$offset_to\r\n";
				$vtimezone .= "TZNAME:$tzname\r\n";
				$vtimezone .= "END:STANDARD\r\n";
			}

			$prev_offset = $offset_formatted;
		}

		$vtimezone .= "END:VTIMEZONE\r\n";

		return $vtimezone;
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

// Instantiate the class.
if ( class_exists( 'Decker_Calendar' ) ) {
	new Decker_Calendar();
}
