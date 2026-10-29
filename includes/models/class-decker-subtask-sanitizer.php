<?php
/**
 * Validation for the subtask list.
 *
 * Every write of the list goes through here, whatever submitted it, so the
 * stored meta is always a list of well-formed items. Kept apart from
 * Decker_Subtasks, which owns reading and writing the meta, so that neither
 * grows into the other.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Subtask_Sanitizer
 */
class Decker_Subtask_Sanitizer {

	/**
	 * Sanitize a raw list of items, assigning ids and dropping unusable rows.
	 *
	 * The stored array order is the display order, so no explicit position
	 * field is kept: reordering is a write of the list in the new order.
	 *
	 * @param array $items   The raw items.
	 * @param int   $task_id The parent task ID, used to validate assignees.
	 * @return array<int, array> The sanitized items.
	 */
	public static function sanitize_list( array $items, int $task_id ): array {
		$allowed_assignees = Decker_Subtasks::get_allowed_assignees( $task_id );
		$clean             = array();
		$used_ids          = array();

		foreach ( $items as $raw ) {
			if ( count( $clean ) >= Decker_Subtasks::MAX_ITEMS ) {
				break;
			}

			$item = self::sanitize_item( $raw, $allowed_assignees );
			if ( null === $item ) {
				continue;
			}

			// Reject a missing, non-positive or already used id and mint the next
			// free one, so every item in a stored list is addressable by id.
			if ( $item['id'] <= 0 || in_array( $item['id'], $used_ids, true ) ) {
				$item['id'] = self::next_id( $used_ids );
			}

			$used_ids[] = $item['id'];
			$clean[]    = $item;
		}

		return $clean;
	}

	/**
	 * Sanitize a single raw item.
	 *
	 * @param mixed $raw               The raw item.
	 * @param array $allowed_assignees User IDs that may be assigned to an item.
	 * @return array|null The sanitized item, or null when it cannot be stored.
	 */
	public static function sanitize_item( $raw, array $allowed_assignees ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$title = self::sanitize_title( isset( $raw['title'] ) ? $raw['title'] : '' );

		// A title is the only required field: an item without one carries no
		// information and would render as an unlabeled row.
		if ( '' === $title ) {
			return null;
		}

		return array(
			'id'       => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
			'title'    => $title,
			'status'   => self::sanitize_status( isset( $raw['status'] ) ? $raw['status'] : '' ),
			'assignee' => self::sanitize_assignee( isset( $raw['assignee'] ) ? $raw['assignee'] : 0, $allowed_assignees ),
			'task_id'  => self::sanitize_promoted_task( isset( $raw['task_id'] ) ? $raw['task_id'] : 0 ),
		);
	}

	/**
	 * Trim and cap an item title.
	 *
	 * @param mixed $value The raw title.
	 * @return string The stored title, empty when there is none.
	 */
	private static function sanitize_title( $value ): string {
		$title = trim( sanitize_text_field( (string) $value ) );

		if ( mb_strlen( $title ) > Decker_Subtasks::MAX_TITLE_LENGTH ) {
			$title = mb_substr( $title, 0, Decker_Subtasks::MAX_TITLE_LENGTH );
		}

		return $title;
	}

	/**
	 * Constrain a status to the three task states.
	 *
	 * @param mixed $value The raw status.
	 * @return string The status, defaulting to to-do.
	 */
	private static function sanitize_status( $value ): string {
		$status = sanitize_key( (string) $value );

		return in_array( $status, Decker_Subtasks::get_statuses(), true ) ? $status : 'to-do';
	}

	/**
	 * Keep an assignee only when they are on the parent task.
	 *
	 * @param mixed $value             The raw user ID.
	 * @param array $allowed_assignees User IDs that may be assigned to an item.
	 * @return int The user ID, or 0.
	 */
	private static function sanitize_assignee( $value, array $allowed_assignees ): int {
		$assignee = absint( $value );

		return in_array( $assignee, $allowed_assignees, true ) ? $assignee : 0;
	}

	/**
	 * Validate the task an item was promoted into.
	 *
	 * @param mixed $value The raw task ID.
	 * @return int The task ID, or 0 when it does not point at a task.
	 */
	private static function sanitize_promoted_task( $value ): int {
		$task_id = absint( $value );

		if ( $task_id <= 0 || 'decker_task' !== get_post_type( $task_id ) ) {
			return 0;
		}

		return $task_id;
	}

	/**
	 * The lowest free item id given the ids already in use.
	 *
	 * @param array<int, int> $used_ids The ids already taken.
	 * @return int The next id.
	 */
	private static function next_id( array $used_ids ): int {
		return empty( $used_ids ) ? 1 : ( max( $used_ids ) + 1 );
	}
}
