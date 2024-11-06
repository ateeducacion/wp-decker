<?php
/**
 * ICS Generator class
 *
 * @package    Decker
 * @subpackage Decker/includes/calendar
 */

/**
 * Class ICS_Generator
 *
 * Genera archivos ICS para tareas de usuarios y tareas generales.
 */
class ICS_Generator {

	/**
	 * User ID.
	 *
	 * @var int|null
	 */
	private $user_id;

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Constructor.
	 *
	 * @param int|null $user_id User ID.
	 * @param string   $secret  Secret key.
	 */
	public function __construct( $user_id = null, $secret ) {
		$this->user_id = $user_id;
		$this->secret = $secret;
	}

	/**
	 * Generate ICS for a specific user.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_ics() {
		if ( ! $this->validate_user_secret() ) {
			return new WP_Error( 'invalid_secret', 'Secret key is invalid', array( 'status' => 403 ) );
		}

		$tasks = $this->get_user_tasks();
		$ics_content = $this->create_ics( $tasks );

		return new WP_REST_Response( $ics_content, 200, array( 'Content-Type' => 'text/calendar' ) );
	}

	/**
	 * Generate ICS for all tasks.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_ics_general() {
		if ( ! $this->validate_general_secret() ) {
			return new WP_Error( 'invalid_secret', 'Secret key is invalid', array( 'status' => 403 ) );
		}

		$tasks = $this->get_all_tasks();
		$ics_content = $this->create_ics( $tasks );

		return new WP_REST_Response( $ics_content, 200, array( 'Content-Type' => 'text/calendar' ) );
	}

	/**
	 * Validate user secret.
	 *
	 * @return bool
	 */
	private function validate_user_secret() {
		$user_secret = get_user_meta( $this->user_id, 'user_ics_secret', true );
		return $user_secret === $this->secret;
	}

	/**
	 * Validate general secret.
	 *
	 * @return bool
	 */
	private function validate_general_secret() {
		$general_secret = get_option( 'general_ics_secret' );
		return $general_secret === $this->secret;
	}

	/**
	 * Get tasks for a specific user.
	 *
	 * @return array
	 */
	private function get_user_tasks() {
		$args = array(
			'post_type' => 'task',
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => 'task_user',
					'value' => $this->user_id,
					'compare' => '=',
				),
			),
		);
		return get_posts( $args );
	}

	/**
	 * Get all tasks.
	 *
	 * @return array
	 */
	private function get_all_tasks() {
		$args = array(
			'post_type' => 'task',
			'post_status' => 'publish',
			'numberposts' => -1,
		);
		return get_posts( $args );
	}

	/**
	 * Create ICS content from tasks.
	 *
	 * @param array $tasks Array of tasks.
	 * @return string
	 */
	private function create_ics( $tasks ) {
		$ics_content = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//Your Company//NONSGML v1.0//EN\n";

		foreach ( $tasks as $task ) {
			$start_date = get_post_meta( $task->ID, 'start_date', true );
			$end_date = get_post_meta( $task->ID, 'end_date', true );

			$ics_content .= "BEGIN:VEVENT\n";
			$ics_content .= 'UID:' . uniqid() . "\n";
			$ics_content .= 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ) . "\n";
			$ics_content .= 'DTSTART:' . gmdate( 'Ymd\THis\Z', strtotime( $start_date ) ) . "\n";
			$ics_content .= 'DTEND:' . gmdate( 'Ymd\THis\Z', strtotime( $end_date ) ) . "\n";
			$ics_content .= 'SUMMARY:' . $task->post_title . "\n";
			$ics_content .= "END:VEVENT\n";
		}

		$ics_content .= 'END:VCALENDAR';

		return $ics_content;
	}
}
