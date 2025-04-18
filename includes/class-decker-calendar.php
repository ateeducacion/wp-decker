<?php
/**
 * Calendar functionality for Decker
 *
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Calendar class to handle iCal and JSON endpoints
 */
class Decker_Calendar {

	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( $this, 'add_ical_endpoint' ) );
	}

	/**
	 * Register REST API routes
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

		// Verificar nonce de REST API primero.
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
	 * Handle JSON calendar request
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_calendar_json( $request ) {
		$events = $this->get_events();
		return rest_ensure_response( $events );
	}

	/**
	 * Handle iCal calendar request
	 */
	public function handle_ical_request() {
		global $wp_query;

		if ( ! isset( $wp_query->query_vars['decker-calendar'] ) ) {
			return;
		}

		$events = $this->get_events();
		$ical = $this->generate_ical( $events );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="decker-calendar.ics"' );
		echo wp_kses_post( $ical );
		exit;
	}

	/**
	 * Get events from the decker_event post type
	 *
	 * @return array
	 */
	private function get_events() {
		$events = array();

		// Get regular events.
		$event_posts = Decker_Events::get_events();
		foreach ( $event_posts as $event_data ) {
			$post = $event_data['post'];
			$meta = $event_data['meta'];

			// Asegurarse de que las fechas sean válidas antes de agregarlas.
			if ( ! empty( $meta['event_start'] ) && ! empty( $meta['event_end'] ) ) {
				$events[] = array(
					'id'             => 'event_' . $post->ID, // Prefijo para distinguir de tareas.
					'title'          => $post->post_title,
					'description'    => $post->post_content,

					'allDay'        => isset( $meta['event_allday'] ) ? $meta['event_allday'][0] : false,
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

		// Get published tasks.
		$task_manager = new TaskManager();
		$tasks = $task_manager->get_tasks_by_status( 'publish' );

		foreach ( $tasks as $task ) {
			$board = $task->get_board();
			$board_color = $board ? $board->color : '';

			// Only add tasks that have a due date .
			if ( $task->duedate ) {
				$events[] = array(
					'id'             => 'task_' . $task->ID, // Prefix to distinguish from events.
					'title'          => $task->title,
					'description'    => $task->description,
					'allDay'        => true,
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
	 * Generate iCal format from events
	 *
	 * @param array $events Array of events.
	 * @return string
	 */
	private function generate_ical( $events ) {
		$ical = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "PRODID:-//Decker//WordPress//EN\r\n";
		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";

		foreach ( $events as $event ) {
			$ical .= "BEGIN:VEVENT\r\n";
			$ical .= 'UID:' . $event['id'] . "@decker\r\n";
			$ical .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\r\n";

			// Convertir fechas a UTC.
			$dtstart = gmdate( 'Ymd\THis\Z', strtotime( $event['start'] ) );
			$dtend = gmdate( 'Ymd\THis\Z', strtotime( $event['end'] ) );

			$ical .= 'DTSTART:' . $dtstart . "\r\n";
			$ical .= 'DTEND:' . $dtend . "\r\n";

			$ical .= 'SUMMARY:' . $this->ical_escape( $event['title'] ) . "\r\n";
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
				$location = $this->ical_escape( $event['location'] );
				$loc_chunks = str_split( $location, 74 );
				$ical .= 'LOCATION:' . array_shift( $loc_chunks ) . "\r\n";
				foreach ( $loc_chunks as $chunk ) {
					$ical .= ' ' . $chunk . "\r\n";
				}
			}

			if ( ! empty( $event['url'] ) ) {
				$url = esc_url_raw( $event['url'] );
				$url_chunks = str_split( $url, 74 );
				$ical .= 'URL:' . array_shift( $url_chunks ) . "\r\n";
				foreach ( $url_chunks as $chunk ) {
					$ical .= ' ' . $chunk . "\r\n";
				}
			}

			// Add assigned users as attendees with proper line folding.
			if ( ! empty( $event['assigned_users'] ) ) {
				foreach ( $event['assigned_users'] as $user_id ) {
					$user = get_userdata( $user_id );
					if ( $user && $user->user_email ) {
						$attendee = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;'
							. 'RSVP=TRUE:mailto:' . $user->user_email;
						$att_chunks = str_split( $attendee, 74 );
						$ical .= array_shift( $att_chunks ) . "\r\n";
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
	 * Escape special characters for iCal format
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
