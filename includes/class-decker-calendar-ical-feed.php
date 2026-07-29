<?php
/**
 * Serves the iCal feed over HTTP.
 *
 * Owns the rewrite endpoint, the access rules (logged-in readers or a valid
 * per-user calendar token), the download headers and the request lifecycle.
 * Feed content comes from the cache, which regenerates on miss.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Calendar_Ical_Feed
 */
class Decker_Calendar_Ical_Feed {

	/**
	 * Cached feed source.
	 *
	 * @var Decker_Calendar_Cache
	 */
	private $cache;

	/**
	 * Hook the endpoint registration.
	 *
	 * @param Decker_Calendar_Cache $cache Cached feed source.
	 */
	public function __construct( Decker_Calendar_Cache $cache ) {
		$this->cache = $cache;

		add_action( 'init', array( $this, 'add_ical_endpoint' ) );
	}

	/**
	 * Add rewrite rule for iCal endpoint
	 */
	public function add_ical_endpoint() {
		add_rewrite_endpoint( 'decker-calendar', EP_ROOT );
		add_action( 'template_redirect', array( $this, 'handle_ical_request' ) );
	}

	/**
	 * Handle iCal calendar request.
	 */
	public function handle_ical_request() {

		// Accept both an internal query var and a GET parameter (?decker-calendar).
		if ( ! $this->is_ical_request() ) {
			return;
		}

		// Require access before producing any output.
		// Mirror get_calendar_permissions_check(): allow logged-in users with the
		// 'read' capability, otherwise require a valid per-user calendar token.
		if ( ! $this->can_access_ical_feed() ) {
			$this->send_ical_forbidden_header();

			// During tests (CLI/PHPUnit or WP-CLI) we do not stop execution.
			if ( $this->should_terminate_request() ) {
				exit;
			}

			return;
		}

		/*
		Direct generation.
		$events = $this->get_events( $type );
		$ical = $this->generate_ical( $events, $type );
		*/

		// Cached generation.
		$ical = $this->cache->get_cached_ical( $this->get_requested_type() );

		$this->send_ical_headers();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is safe iCal content
		echo $ical;

		// During tests (CLI/PHPUnit or WP-CLI) we do not stop execution.
		// Only exit on normal web requests to avoid extra content.
		if ( $this->should_terminate_request() ) {
			exit;
		}

		return;
	}

	/**
	 * Whether the current request targets the iCal feed.
	 *
	 * @return bool True when the internal query var or the ?decker-calendar GET flag is set.
	 */
	private function is_ical_request() {
		global $wp_query;

		return isset( $wp_query->query_vars['decker-calendar'] ) || isset( $_GET['decker-calendar'] );
	}

	/**
	 * Read and sanitize the requested feed type from the query string.
	 *
	 * @return string Sanitized type slug, or '' when not provided.
	 */
	private function get_requested_type() {
		return isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	}

	/**
	 * Emit a 403 status for forbidden iCal requests, suppressed during tests.
	 */
	private function send_ical_forbidden_header() {
		if ( ! headers_sent() && ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			status_header( 403 );
		}
	}

	/**
	 * Send the iCal download headers, suppressed during tests and when output started.
	 *
	 * Avoid “Cannot modify header information” warnings when output has already
	 * started (e.g., in PHPUnit) by checking headers_sent() before sending headers.
	 */
	private function send_ical_headers() {
		if ( ! headers_sent() && ! ( defined( 'WP_TESTS_RUNNING' ) && WP_TESTS_RUNNING ) ) {
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="decker-calendar.ics"' );
			// Ignore cache, because we are going to cache using traseints.
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'Expires: Sat, 26 Jul 1997 05:00:00 GMT' ); // Date in past.
			header( 'Pragma: no-cache' );
		}
	}

	/**
	 * Whether the request should terminate via exit after emitting output.
	 *
	 * During tests (CLI/PHPUnit or WP-CLI) we do not stop execution.
	 *
	 * @return bool True on normal web requests, false under CLI/WP-CLI.
	 */
	private function should_terminate_request() {
		return php_sapi_name() !== 'cli' && ( ! defined( 'WP_CLI' ) || ! WP_CLI );
	}

	/**
	 * Check if the current request may access the iCal feed.
	 *
	 * Mirrors get_calendar_permissions_check() so the iCal endpoint and the REST
	 * route agree: logged-in users with the 'read' capability are allowed,
	 * otherwise a valid per-user 'decker_calendar_token' is required.
	 *
	 * @return bool True if access is granted, false otherwise.
	 */
	private function can_access_ical_feed() {

		// Allow logged-in users with the read capability.
		if ( is_user_logged_in() && current_user_can( 'read' ) ) {
			return true;
		}

		// Otherwise require a valid per-user calendar token.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( empty( $token ) ) {
			return false;
		}

		$users = get_users(
			array(
				'meta_key'   => 'decker_calendar_token',
				'meta_value' => $token,
				'number'     => 1,
			)
		);

		if ( ! empty( $users ) ) {
			$stored = get_user_meta( $users[0]->ID, 'decker_calendar_token', true );
			if ( hash_equals( (string) $stored, $token ) ) {
				return true;
			}
		}

		return false;
	}
}
