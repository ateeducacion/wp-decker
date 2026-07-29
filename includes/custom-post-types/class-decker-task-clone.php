<?php
/**
 * Clones a task into a fresh copy.
 *
 * Reads the source task's fields, meta, board and labels, builds the copy's
 * title, and creates the duplicate through the canonical write path
 * (Decker_Task_Writer::create_or_update_task), so cloning fires the same
 * hooks as any other creation.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Clone
 */
class Decker_Task_Clone {

	/**
	 * Clone a task by creating a new task with the same data.
	 *
	 * Copies post title (with " (copy)" suffix), content, status, meta
	 * fields, and taxonomy terms. Does not copy unique fields like
	 * id_nextcloud_card or _user_date_relations.
	 *
	 * @param int $task_id The ID of the task to clone.
	 * @return int|WP_Error The ID of the new task, or WP_Error on failure.
	 */
	public static function clone_task( $task_id ) {
		$post = get_post( $task_id );
		if ( ! $post || 'decker_task' !== $post->post_type ) {
			return new WP_Error(
				'invalid_task',
				__( 'Invalid task ID.', 'decker' )
			);
		}

		// Build the new title with " (copy)" suffix.
		$new_title = self::build_clone_title( $post );

		// Gather meta values.
		$stack        = get_post_meta( $task_id, 'stack', true );
		$max_priority = (bool) get_post_meta( $task_id, 'max_priority', true );
		$hidden       = (bool) get_post_meta( $task_id, 'hidden', true );
		$responsable  = (int) get_post_meta( $task_id, 'responsable', true );

		$duedate = self::parse_duedate_meta( $task_id );

		$assigned_users = self::get_task_assigned_users( $task_id );

		// Get taxonomy terms.
		$board  = self::get_task_board_id( $task_id );
		$labels = self::get_task_label_ids( $task_id );

		// Determine archived status from original post status.
		$archived = ( 'archived' === $post->post_status );

		// Create the new task using the existing method.
		$new_task_id = Decker_Task_Writer::create_or_update_task(
			array(
				'id'                => 0,
				'title'             => $new_title,
				'description'       => $post->post_content,
				'stack'             => ! empty( $stack ) ? $stack : 'to-do',
				'board'             => $board,
				'max_priority'      => $max_priority,
				'duedate'           => $duedate,
				'author'            => get_current_user_id(),
				'responsable'       => $responsable,
				'hidden'            => $hidden,
				'assigned_users'    => $assigned_users,
				'labels'            => $labels,
				'creation_date'     => null,
				'archived'          => $archived,
				'id_nextcloud_card' => 0,
			)
		);

		return $new_task_id;
	}

	/**
	 * Build the cloned task title with the " (copy)" suffix.
	 *
	 * @param WP_Post $post The original task post.
	 * @return string The new title.
	 */
	private static function build_clone_title( WP_Post $post ): string {
		$original_title = $post->post_title;
		if ( empty( trim( $original_title ) ) ) {
			$original_title = __( 'Untitled', 'decker' );
		}

		/* translators: %s: original task title */
		return sprintf( __( '%s (copy)', 'decker' ), $original_title );
	}

	/**
	 * Parse the duedate meta value of a task into a DateTime object.
	 *
	 * @param int $task_id The task ID.
	 * @return DateTime|null The parsed due date, or null when empty or invalid.
	 */
	private static function parse_duedate_meta( int $task_id ): ?DateTime {
		$duedate_raw = get_post_meta( $task_id, 'duedate', true );

		if ( empty( $duedate_raw ) ) {
			return null;
		}

		try {
			return new DateTime( $duedate_raw );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Get the assigned users meta of a task as an array.
	 *
	 * @param int $task_id The task ID.
	 * @return array The assigned user IDs, or an empty array when not set.
	 */
	private static function get_task_assigned_users( int $task_id ): array {
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( ! is_array( $assigned_users ) ) {
			$assigned_users = array();
		}

		return $assigned_users;
	}

	/**
	 * Get the first board term ID assigned to a task.
	 *
	 * @param int $task_id The task ID.
	 * @return int The board term ID, or 0 when none.
	 */
	private static function get_task_board_id( int $task_id ): int {
		$board_terms = wp_get_post_terms(
			$task_id,
			'decker_board',
			array( 'fields' => 'ids' )
		);

		return ! empty( $board_terms ) && ! is_wp_error( $board_terms )
			? (int) $board_terms[0]
			: 0;
	}

	/**
	 * Get the label term IDs assigned to a task.
	 *
	 * @param int $task_id The task ID.
	 * @return array The label term IDs, or an empty array on error.
	 */
	private static function get_task_label_ids( int $task_id ): array {
		$label_terms = wp_get_post_terms(
			$task_id,
			'decker_label',
			array( 'fields' => 'ids' )
		);

		return ! is_wp_error( $label_terms ) ? $label_terms : array();
	}
}
