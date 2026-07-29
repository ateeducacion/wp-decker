<?php
/**
 * AJAX request field reader for tasks.
 *
 * Owns the $_POST parsing for the AJAX save path: the core and optional
 * task fields, the due-date parsing, and the comma-separated/array ID list
 * fields (assignees, labels). No hooks of its own; instantiated by
 * Decker_Task_Ajax_Save.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Request_Reader
 */
class Decker_Task_Request_Reader {

	/**
	 * Read and sanitize the core task fields from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @return array{id:int,title:string,description:string,stack:string,board:int}
	 */
	public function read_task_core_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'id'          => isset( $_POST['task_id'] ) ? intval( wp_unslash( $_POST['task_id'] ) ) : 0,
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? wp_kses( wp_unslash( $_POST['description'] ), Decker::get_allowed_tags() ) : '',
			'stack'       => isset( $_POST['stack'] ) ? sanitize_text_field( wp_unslash( $_POST['stack'] ) ) : '',
			'board'       => isset( $_POST['board'] ) ? intval( wp_unslash( $_POST['board'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read and sanitize the optional task fields from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @return array{max_priority:bool,mark_for_today:bool,author:int,responsable:int,hidden:bool,duedate_raw:string,lock_generation:string|null}
	 */
	public function read_task_option_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$max_priority = isset( $_POST['max_priority'] ) ? boolval( wp_unslash( $_POST['max_priority'] ) ) : false;

		$duedate_raw = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';

		$mark_for_today = isset( $_POST['mark_for_today'] ) ? boolval( wp_unslash( $_POST['mark_for_today'] ) ) : false;

		$author = isset( $_POST['author'] ) ? intval( wp_unslash( $_POST['author'] ) ) : get_current_user_id();
		$responsable = isset( $_POST['responsable'] ) ? intval( wp_unslash( $_POST['responsable'] ) ) : $author;

		$hidden = isset( $_POST['hidden'] ) ? boolval( wp_unslash( $_POST['hidden'] ) ) : false;

		// Session generation token from the editor form; null when the client did not send it.
		$lock_generation = null;
		if ( isset( $_POST['lock_generation'] ) ) {
			$lock_generation_raw = sanitize_text_field( wp_unslash( $_POST['lock_generation'] ) );
			if ( '' !== $lock_generation_raw ) {
				$lock_generation = $lock_generation_raw;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'max_priority'    => $max_priority,
			'mark_for_today'  => $mark_for_today,
			'author'          => $author,
			'responsable'     => $responsable,
			'hidden'          => $hidden,
			'duedate_raw'     => $duedate_raw,
			'lock_generation' => $lock_generation,
		);
	}

	/**
	 * Parse a raw due-date string into a DateTime, falling back to today on failure.
	 *
	 * @param string $duedate_raw The raw due-date string.
	 * @return DateTime The parsed date, or today when the value is invalid.
	 */
	public function parse_task_due_date( string $duedate_raw ): DateTime {
		try {
			return new DateTime( $duedate_raw );
		} catch ( Exception $e ) {
			return new DateTime(); // Default value if conversion fails.
		}
	}

	/**
	 * Read a comma-separated or array ID list field from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @param string $field The $_POST field name.
	 * @return array<int> The list of absint IDs, or an empty array when absent.
	 */
	public function read_id_list_field( string $field ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $field ] ) ) {
			return array();
		}

		// Remove backslashes added by WordPress.
		$raw = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_string( $raw ) ) {
			return array_map( 'absint', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			return array_map( 'absint', $raw );
		}

		return array();
	}
}
