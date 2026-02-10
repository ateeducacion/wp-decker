<?php
/**
 * REST API for the Daily View feature.
 *
 * @package    Decker
 * @subpackage Decker/includes/rest
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * REST controller for Decker Daily endpoints.
 *
 * Registers REST API routes for fetching the daily summary as well as
 * creating and deleting daily notes for a given board and date.
 *
 * @since 1.0.0
 */
class Decker_REST_Daily {

	/**
	 * Hooks REST route registration on rest_api_init.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the REST API routes for the Daily feature.
	 *
	 * Routes:
	 * - GET decker/v1/daily: Fetch daily data (summary and metadata).
	 * - POST decker/v1/daily: Create or update daily notes.
	 * - DELETE decker/v1/daily: Delete existing daily notes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'decker/v1',
			'/daily',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_daily_data' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(
						'board' => array(
							'required' => true,
							'validate_callback' => array( $this, 'is_valid_board' ),
						),
						'date' => array(
							'required' => true,
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_daily_notes' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'board' => array(
							'required' => true,
							'validate_callback' => array( $this, 'is_valid_board' ),
						),
						'date' => array(
							'required' => true,
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						),
						'notes' => array(
							'required' => true,
							'type' => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_daily_notes' ),
					'permission_callback' => array( $this, 'edit_permissions_check' ),
					'args'                => array(
						'board' => array(
							'required' => true,
							'validate_callback' => array( $this, 'is_valid_board' ),
						),
						'date' => array(
							'required' => true,
							'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for read-only Daily endpoints.
	 *
	 * Grants access to users capable of reading.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the current user can read, false otherwise.
	 */
	public function get_permissions_check( $request ) {
		return current_user_can( 'read' );
	}

	/**
	 * Permission check for mutating Daily endpoints.
	 *
	 * Grants access to users capable of editing posts.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the current user can edit posts, false otherwise.
	 */
	public function edit_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Validates the board parameter for REST requests.
	 *
	 * Accepts either a numeric term ID or a string slug.
	 *
	 * @since 1.0.0
	 * @param mixed           $param   Raw parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $key     Parameter name.
	 * @return bool True when the value looks like a valid board reference.
	 */
	public function is_valid_board( $param, $request, $key ) {
		return is_numeric( $param ) || is_string( $param );
	}

	/**
	 * Resolves a board parameter to a term ID.
	 *
	 * Accepts a numeric ID directly or resolves a slug to its corresponding
	 * `decker_board` term ID. Returns 0 when no term is found.
	 *
	 * @since 1.0.0
	 * @param int|string $board_param Board ID or slug.
	 * @return int Term ID or 0 when not found.
	 */
	private function get_board_id_from_param( $board_param ) {
		if ( is_numeric( $board_param ) ) {
			return (int) $board_param;
		}
		$term = get_term_by( 'slug', $board_param, 'decker_board' );
		return $term ? $term->term_id : 0;
	}

	/**
	 * Handles GET requests for the daily data.
	 *
	 * Returns summary information for a board and date. Responds with 404 if the
	 * board cannot be resolved.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error REST response on success, error otherwise.
	 */
	public function get_daily_data( $request ) {
		$board_id = $this->get_board_id_from_param( $request['board'] );
		$date = sanitize_text_field( $request['date'] );

		if ( ! $board_id ) {
			return new WP_Error( 'rest_board_not_found', __( 'Board not found.', 'decker' ), array( 'status' => 404 ) );
		}

		$summary = Decker_Daily_Service::get_daily_summary( $board_id, $date );

		$response = array_merge(
			array(
				'board' => $board_id,
				'date' => $date,
			),
			$summary
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Handles POST requests to create or update daily notes.
	 *
	 * Expects a board reference, a date (Y-m-d) and the notes content.
	 * Returns the journal post ID on success.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error REST response on success, error otherwise.
	 */
	public function save_daily_notes( $request ) {
		$board_id = $this->get_board_id_from_param( $request['board'] );
		$date = sanitize_text_field( $request['date'] );
		$notes = wp_kses_post( $request['notes'] );

		if ( ! $board_id ) {
			return new WP_Error( 'rest_board_not_found', __( 'Board not found.', 'decker' ), array( 'status' => 404 ) );
		}

		$result = Decker_Daily_Service::upsert_notes( $board_id, $date, $notes );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'ok' => true,
				'post_id' => $result,
			),
			200
		);
	}

	/**
	 * Handles DELETE requests to remove daily notes.
	 *
	 * Deletes the journal post for the given board and date. Returns 404 when
	 * no journal entry exists and 500 when deletion fails.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error REST response on success, error otherwise.
	 */
	public function delete_daily_notes( $request ) {
		$board_id = $this->get_board_id_from_param( $request['board'] );
		$date = sanitize_text_field( $request['date'] );

		if ( ! $board_id ) {
			return new WP_Error( 'rest_board_not_found', __( 'Board not found.', 'decker' ), array( 'status' => 404 ) );
		}

		$journal_post = Decker_Daily_Service::get_journal_post_for_date( $board_id, $date );

		if ( ! $journal_post ) {
			return new WP_Error( 'journal_not_found', __( 'No journal entry to delete.', 'decker' ), array( 'status' => 404 ) );
		}

		$result = wp_delete_post( $journal_post->ID, true );

		if ( ! $result ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete journal entry.', 'decker' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
