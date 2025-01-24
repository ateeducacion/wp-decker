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
	 * @return bool
	 */
	public function get_calendar_permissions_check( $request ) {
		return true; // Modify according to your needs.
	}

	/**
	 * Handle JSON calendar request
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response
	 */
	public function get_calendar_json( $request ) {
		$events = $this->get_sample_events();
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

		$events = $this->get_sample_events();
		$ical = $this->generate_ical( $events );

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="decker-calendar.ics"' );
		echo $ical;
		exit;
	}

	/**
	 * Get sample events (to be replaced with actual data)
	 *
	 * @return array
	 */
	private function get_sample_events() {
		return array(
			array(
				'id'          => 1,
				'title'       => esc_html__( 'Meeting with Mr. Shreyu', 'decker' ),
				'start'       => date('Y-m-d\TH:i:s', strtotime('+2 days')),
				'end'         => date('Y-m-d\TH:i:s', strtotime('+2 days +2 hours')),
				'className'   => 'bg-warning',
				'description' => esc_html__( 'Project planning meeting', 'decker' ),
			),
			array(
				'id'          => 2,
				'title'       => esc_html__( 'Interview - Backend Engineer', 'decker' ),
				'start'       => date('Y-m-d\TH:i:s'),
				'end'         => date('Y-m-d\TH:i:s', strtotime('+1 hour')),
				'className'   => 'bg-success',
				'description' => esc_html__( 'Technical interview for backend position', 'decker' ),
			),
			array(
				'id'          => 3,
				'title'       => esc_html__( 'Phone Screen - Frontend Engineer', 'decker' ),
				'start'       => date('Y-m-d\TH:i:s', strtotime('+2 hours')),
				'end'         => date('Y-m-d\TH:i:s', strtotime('+3 hours')),
				'className'   => 'bg-info',
				'description' => esc_html__( 'Initial screening call for frontend position', 'decker' ),
			),
			array(
				'id'          => 4,
				'title'       => esc_html__( 'Buy Design Assets', 'decker' ),
				'start'       => date('Y-m-d\TH:i:s', strtotime('+4 hours')),
				'end'         => date('Y-m-d\TH:i:s', strtotime('+1 day')),
				'className'   => 'bg-primary',
				'description' => esc_html__( 'Purchase new design resources', 'decker' ),
			),
		);
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
			$ical .= "UID:" . $event['id'] . "@decker\r\n";
			$ical .= "DTSTART:" . date( 'Ymd\THis\Z', strtotime( $event['start'] ) ) . "\r\n";
			$ical .= "DTEND:" . date( 'Ymd\THis\Z', strtotime( $event['end'] ) ) . "\r\n";
			$ical .= "SUMMARY:" . $this->ical_escape( $event['title'] ) . "\r\n";
			$ical .= "DESCRIPTION:" . $this->ical_escape( $event['description'] ) . "\r\n";
			$ical .= "END:VEVENT\r\n";
		}

		$ical .= "END:VCALENDAR";
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
		$string = str_replace( array( ",", ";", ":" ), array( "\,", "\;", "\:" ), $string );
		return $string;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Calendar' ) ) {
	new Decker_Calendar();
}
