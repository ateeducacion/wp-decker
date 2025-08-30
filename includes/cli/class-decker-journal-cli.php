<?php
/**
 * WP-CLI commands for the Decker Journal feature.
 *
 * @package Decker
 * @subpackage Decker/includes/cli
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Custom WP-CLI commands for Decker Journal.
	 */
	class Decker_Journal_CLI extends WP_CLI_Command {

		/**
		 * Create a new journal entry.
		 *
		 * ## OPTIONS
		 *
		 * --board=<slug|id>
		 * : The board for the journal entry. (Required)
		 *
		 * [--date=<YYYY-MM-DD>]
		 * : The date for the journal entry. Defaults to today.
		 *
		 * --title=<title>
		 * : The title for the journal entry. (Required)
		 *
		 * [--attendees=<attendees>]
		 * : Comma-separated list of attendees.
		 *
		 * [--topic=<topic>]
		 * : The topic of the journal entry.
		 *
		 * [--agreements=<agreements>]
		 * : Pipe-separated list of agreements.
		 *
		 * [--derived_tasks=<json>]
		 * : JSON string of derived tasks.
		 *
		 * [--notes=<json>]
		 * : JSON string of notes.
		 *
		 * [--force]
		 * : Force creation even if an entry for the same board and date already exists.
		 *
		 * ## EXAMPLES
		 *
		 *     wp decker journal create --board=my-board --title="Daily Standup" --attendees="Fran,Humberto" --topic="Sync"
		 */
		public function create( $args, $assoc_args ) {
			$board_arg = WP_CLI\Utils\get_flag_value( $assoc_args, 'board' );
			$date = WP_CLI\Utils\get_flag_value( $assoc_args, 'date', date( 'Y-m-d' ) );
			$title = WP_CLI\Utils\get_flag_value( $assoc_args, 'title' );
			$force = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

			if ( ! $board_arg || ! $title ) {
				WP_CLI::error( __( "'board' and 'title' are required arguments.", 'decker' ) );
			}

			// Validate and get board term ID
			$board_term = is_numeric( $board_arg ) ? get_term( $board_arg, 'decker_board' ) : get_term_by( 'slug', $board_arg, 'decker_board' );
			if ( ! $board_term || is_wp_error( $board_term ) ) {
				WP_CLI::error( __( "Board not found.", 'decker' ) );
			}
			$board_id = $board_term->term_id;

			// Check for duplicates if --force is not used
			if ( ! $force ) {
				$query = new WP_Query( array(
					'post_type'      => 'decker_journal',
					'post_status'    => 'any',
					'meta_key'       => 'journal_date',
					'meta_value'     => $date,
					'tax_query'      => array(
						array(
							'taxonomy' => 'decker_board',
							'field'    => 'term_id',
							'terms'    => $board_id,
						),
					),
					'posts_per_page' => 1,
				) );

				if ( $query->have_posts() ) {
					WP_CLI::warning( __( "A journal entry for this board and date already exists. Use --force to override.", 'decker' ) );
					return;
				}
			}

			// Create post
			$post_data = array(
				'post_type'    => 'decker_journal',
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			);
			$post_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::error( $post_id->get_error_message() );
			}

			// Set board taxonomy
			wp_set_post_terms( $post_id, $board_id, 'decker_board' );

			// Update meta fields
			update_post_meta( $post_id, 'journal_date', $date );

			if ( isset( $assoc_args['topic'] ) ) {
				update_post_meta( $post_id, 'topic', sanitize_text_field( $assoc_args['topic'] ) );
			}
			if ( isset( $assoc_args['attendees'] ) ) {
				$attendees = array_map( 'sanitize_text_field', explode( ',', $assoc_args['attendees'] ) );
				update_post_meta( $post_id, 'attendees', $attendees );
			}
			if ( isset( $assoc_args['agreements'] ) ) {
				$agreements = array_map( 'sanitize_text_field', explode( '|', $assoc_args['agreements'] ) );
				update_post_meta( $post_id, 'agreements', $agreements );
			}
			if ( isset( $assoc_args['derived_tasks'] ) ) {
				$derived_tasks = json_decode( $assoc_args['derived_tasks'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					update_post_meta( $post_id, 'derived_tasks', Decker_Journal_CPT::sanitize_derived_tasks( $derived_tasks ) );
				} else {
					WP_CLI::warning( "Invalid JSON for derived_tasks." );
				}
			}
			if ( isset( $assoc_args['notes'] ) ) {
				$notes = json_decode( $assoc_args['notes'], true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					update_post_meta( $post_id, 'notes', Decker_Journal_CPT::sanitize_notes( $notes ) );
				} else {
					WP_CLI::warning( "Invalid JSON for notes." );
				}
			}

			WP_CLI::success( "Journal entry created successfully! ID: $post_id" );
		}
	}
}
