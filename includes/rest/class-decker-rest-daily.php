<?php
/**
 * REST API for the Daily View feature.
 *
 * @package    Decker
 * @subpackage Decker/includes/rest
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Decker_REST_Daily {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

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

	public function get_permissions_check( $request ) {
		return current_user_can( 'read' );
	}

	public function edit_permissions_check( $request ) {
		return current_user_can( 'edit_posts' );
	}

	public function is_valid_board( $param, $request, $key ) {
		return is_numeric( $param ) || is_string( $param );
	}

	private function get_board_id_from_param( $board_param ) {
		if ( is_numeric( $board_param ) ) {
			return (int) $board_param;
		}
		$term = get_term_by( 'slug', $board_param, 'decker_board' );
		return $term ? $term->term_id : 0;
	}

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
