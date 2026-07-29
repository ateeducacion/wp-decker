<?php
/**
 * Merges one task into another.
 *
 * Validates the pair, unions the assigned users and their date relations,
 * moves comments and attachments to the destination, appends the source
 * description, and archives the source. Everything here is a static engine;
 * the REST transport lives with the task routes.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Merge
 */
class Decker_Task_Merge {

	/**
	 * Merge a source task into a destination task.
	 *
	 * The destination task keeps its primary fields, while the source task
	 * contributes assigned users, user-date relations, comments, attachments,
	 * and description content. The source task is archived and renamed.
	 *
	 * @param int $source_task_id The source task ID.
	 * @param int $destination_task_id The destination task ID.
	 * @return true|WP_Error True on success or a WP_Error on failure.
	 */
	public static function merge_tasks( int $source_task_id, int $destination_task_id ) {
		$source_post      = get_post( $source_task_id );
		$destination_post = get_post( $destination_task_id );

		$validation = self::validate_merge_request(
			$source_post,
			$destination_post,
			$source_task_id,
			$destination_task_id
		);
		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		self::merge_assigned_users_meta( $source_task_id, $destination_task_id );
		self::merge_relations_meta( $source_task_id, $destination_task_id );

		$merged_description = self::build_merged_task_description(
			(string) $destination_post->post_content,
			(string) $source_post->post_content,
			(string) $source_post->post_title,
			$source_task_id
		);

		wp_update_post(
			array(
				'ID'           => $destination_task_id,
				'post_content' => wp_kses(
					$merged_description,
					Decker::get_allowed_tags()
				),
			)
		);

		self::move_task_comments( $source_task_id, $destination_task_id );
		self::merge_task_attachments( $source_task_id, $destination_task_id );
		self::archive_merged_source( $source_task_id, $destination_task_id, $source_post->post_title );

		return true;
	}

	/**
	 * Validate a merge request and return a WP_Error when it cannot proceed.
	 *
	 * @param WP_Post|null $source_post         The source task post object.
	 * @param WP_Post|null $destination_post    The destination task post object.
	 * @param int          $source_task_id      The source task ID.
	 * @param int          $destination_task_id The destination task ID.
	 * @return WP_Error|null A WP_Error on failure, or null when valid.
	 */
	private static function validate_merge_request( $source_post, $destination_post, int $source_task_id, int $destination_task_id ) {
		if ( ! $source_post || 'decker_task' !== $source_post->post_type ) {
			return new WP_Error(
				'invalid_source_task',
				__( 'The source task was not found.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $destination_post || 'decker_task' !== $destination_post->post_type ) {
			return new WP_Error(
				'invalid_destination_task',
				__( 'The destination task was not found.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		if ( $source_task_id === $destination_task_id ) {
			return new WP_Error(
				'invalid_merge',
				__( 'A task cannot be merged into itself.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		if ( 'publish' !== $source_post->post_status ||
			'publish' !== $destination_post->post_status ) {
			return new WP_Error(
				'invalid_task_status',
				__( 'Only published tasks can be merged.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		if ( get_post_meta( $source_task_id, 'merged_into', true ) ) {
			return new WP_Error(
				'already_merged',
				__( 'This task has already been merged.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * Merge the assigned-users meta of two tasks into the destination.
	 *
	 * @param int $source_task_id      The source task ID.
	 * @param int $destination_task_id The destination task ID.
	 */
	private static function merge_assigned_users_meta( int $source_task_id, int $destination_task_id ) {
		$destination_assigned_users = self::normalize_task_user_ids(
			get_post_meta( $destination_task_id, 'assigned_users', true )
		);
		$source_assigned_users      = self::normalize_task_user_ids(
			get_post_meta( $source_task_id, 'assigned_users', true )
		);
		$merged_assigned_users      = array_values(
			array_unique(
				array_merge(
					$destination_assigned_users,
					$source_assigned_users
				)
			)
		);

		update_post_meta(
			$destination_task_id,
			'assigned_users',
			$merged_assigned_users
		);
	}

	/**
	 * Merge the user-date relations of two tasks into the destination.
	 *
	 * @param int $source_task_id      The source task ID.
	 * @param int $destination_task_id The destination task ID.
	 */
	private static function merge_relations_meta( int $source_task_id, int $destination_task_id ) {
		$merged_relations = self::merge_user_date_relations(
			get_post_meta( $destination_task_id, '_user_date_relations', true ),
			get_post_meta( $source_task_id, '_user_date_relations', true )
		);

		if ( ! empty( $merged_relations ) ) {
			update_post_meta(
				$destination_task_id,
				'_user_date_relations',
				$merged_relations
			);
		}
	}

	/**
	 * Reparent all comments of the source task to the destination task.
	 *
	 * @param int $source_task_id      The source task ID.
	 * @param int $destination_task_id The destination task ID.
	 */
	private static function move_task_comments( int $source_task_id, int $destination_task_id ) {
		$source_comments = get_comments(
			array(
				'post_id' => $source_task_id,
				'status'  => 'all',
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		);

		foreach ( $source_comments as $comment ) {
			wp_update_comment(
				array(
					'comment_ID'      => $comment->comment_ID,
					'comment_post_ID' => $destination_task_id,
				)
			);
		}
	}

	/**
	 * Reparent and merge the attachments of the source task into the destination.
	 *
	 * @param int $source_task_id      The source task ID.
	 * @param int $destination_task_id The destination task ID.
	 */
	private static function merge_task_attachments( int $source_task_id, int $destination_task_id ) {
		$source_attachments      = get_attached_media( '', $source_task_id );
		$destination_attachments = get_post_meta(
			$destination_task_id,
			'attachments',
			true
		);
		$source_attachment_meta  = get_post_meta( $source_task_id, 'attachments', true );

		$destination_attachments = is_array( $destination_attachments )
			? array_map( 'intval', $destination_attachments )
			: array();
		$source_attachment_meta  = is_array( $source_attachment_meta )
			? array_map( 'intval', $source_attachment_meta )
			: array();

		foreach ( $source_attachments as $attachment ) {
			wp_update_post(
				array(
					'ID'          => $attachment->ID,
					'post_parent' => $destination_task_id,
				)
			);
			$destination_attachments[] = (int) $attachment->ID;
		}

		if ( ! empty( $source_attachment_meta ) ) {
			$destination_attachments = array_merge(
				$destination_attachments,
				$source_attachment_meta
			);
		}

		if ( ! empty( $destination_attachments ) ) {
			update_post_meta(
				$destination_task_id,
				'attachments',
				array_values( array_unique( $destination_attachments ) )
			);
		}

		delete_post_meta( $source_task_id, 'attachments' );
	}

	/**
	 * Mark the source task as merged, rename it and archive it.
	 *
	 * @param int    $source_task_id      The source task ID.
	 * @param int    $destination_task_id The destination task ID.
	 * @param string $source_title        The original source task title.
	 */
	private static function archive_merged_source( int $source_task_id, int $destination_task_id, string $source_title ) {
		update_post_meta( $source_task_id, 'merged_into', $destination_task_id );

		$renamed_source_title = sprintf(
			'[MERGED #%1$d] %2$s',
			$destination_task_id,
			$source_title
		);

		wp_update_post(
			array(
				'ID'          => $source_task_id,
				'post_status' => 'archived',
				'post_title'  => sanitize_text_field( $renamed_source_title ),
			)
		);
	}

	/**
	 * Normalize the assigned users meta into a list of unique user IDs.
	 *
	 * @param mixed $assigned_users The raw assigned users meta value.
	 * @return array<int> Normalized user IDs.
	 */
	private static function normalize_task_user_ids( $assigned_users ): array {
		if ( ! is_array( $assigned_users ) ) {
			if ( is_scalar( $assigned_users ) && '' !== (string) $assigned_users ) {
				$assigned_users = array( $assigned_users );
			} else {
				$assigned_users = array();
			}
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $assigned_users )
				)
			)
		);
	}

	/**
	 * Merge the user-date relations from two tasks without duplicates.
	 *
	 * @param mixed $destination_relations The destination relations meta.
	 * @param mixed $source_relations The source relations meta.
	 * @return array<int, array<string, int|string>> Merged relations.
	 */
	private static function merge_user_date_relations(
		$destination_relations,
		$source_relations
	): array {
		$destination_relations = is_array( $destination_relations )
			? $destination_relations
			: array();
		$source_relations      = is_array( $source_relations )
			? $source_relations
			: array();

		$merged_relations = array();
		$seen_relations   = array();

		foreach ( array_merge( $destination_relations, $source_relations ) as $relation ) {
			if ( ! is_array( $relation ) ||
				! isset( $relation['user_id'], $relation['date'] ) ) {
				continue;
			}

			$user_id = (int) $relation['user_id'];
			$date    = sanitize_text_field( (string) $relation['date'] );
			$key     = $user_id . '|' . $date;

			if ( isset( $seen_relations[ $key ] ) || ! $user_id || '' === $date ) {
				continue;
			}

			$seen_relations[ $key ] = true;
			$merged_relations[]     = array(
				'user_id' => $user_id,
				'date'    => $date,
			);
		}

		return $merged_relations;
	}

	/**
	 * Build the destination description for a merged task.
	 *
	 * @param string $destination_description The destination task description.
	 * @param string $source_description The source task description.
	 * @param string $source_title The source task title.
	 * @param int    $source_task_id The source task ID.
	 * @return string The merged description content.
	 */
	private static function build_merged_task_description(
		string $destination_description,
		string $source_description,
		string $source_title,
		int $source_task_id
	): string {
		$destination_description = trim( $destination_description );
		$source_description      = trim( $source_description );

		$merge_header = sprintf(
			/* translators: 1: source task title, 2: source task ID */
			__( 'Merged from task: %1$s (ID: %2$d)', 'decker' ),
			$source_title,
			$source_task_id
		);

		$merged_block = '<hr /><p><strong>' . esc_html( $merge_header ) . '</strong></p>';

		if ( '' !== $source_description ) {
			$merged_block .= "\n" . $source_description;
		}

		if ( '' === $destination_description ) {
			return $merged_block;
		}

		return $destination_description . "\n\n" . $merged_block;
	}
}
