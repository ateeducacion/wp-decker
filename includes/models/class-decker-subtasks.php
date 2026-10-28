<?php
/**
 * Lightweight subtask list stored on the parent task.
 *
 * Subtasks are not `decker_task` posts: they live in a single serialized meta
 * on their parent so that none of the existing task queries (board columns,
 * task table, upcoming, analytics, today, board counters, iCal, global search)
 * has to learn to exclude children, and so the menu_order engine never sees
 * them. See docs/adr/003-lightweight-subtasks.md for the trade-off and for the
 * promotion path, Decker_Tasks_Rest_Subtasks::convert_subtask(), that turns an
 * item that outgrew the list into a real task.
 *
 * The three item states reuse the task `stack` vocabulary verbatim, so
 * Decker_Tasks::get_stack_label() and ::get_stack_icon_classes() already
 * provide their labels, icons and colors.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Subtasks
 */
class Decker_Subtasks {

	/**
	 * Post meta key holding the whole subtask list of a task.
	 *
	 * @var string
	 */
	const META_KEY = '_decker_subtasks';

	/**
	 * Maximum number of items a task may hold.
	 *
	 * Bounds the serialized meta size and the card/popover render. Items beyond
	 * the cap are dropped on write rather than silently truncating the stored
	 * value later.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 200;

	/**
	 * Maximum stored length of an item title, in characters.
	 *
	 * @var int
	 */
	const MAX_TITLE_LENGTH = 255;

	/**
	 * Whether the subtasks feature is enabled for this site.
	 *
	 * @return bool True when the setting is on.
	 */
	public static function is_enabled(): bool {
		$options = get_option( 'decker_settings', array() );

		return ! empty( $options['subtasks_enabled'] ) && '1' === $options['subtasks_enabled'];
	}

	/**
	 * The allowed item states, in board order.
	 *
	 * @return array<int, string> The status slugs.
	 */
	public static function get_statuses(): array {
		return array( 'to-do', 'in-progress', 'done' );
	}

	/**
	 * Read the stored subtask list of a task.
	 *
	 * Always returns a list of well-formed items: a legacy or hand-edited meta
	 * value that is not an array of items yields an empty list rather than
	 * leaking malformed rows into the renderers.
	 *
	 * @param int $task_id The parent task ID.
	 * @return array<int, array> The subtask items.
	 */
	public static function get( int $task_id ): array {
		if ( $task_id <= 0 ) {
			return array();
		}

		$stored = get_post_meta( $task_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return self::sanitize_list( $stored, $task_id );
	}

	/**
	 * Replace the whole subtask list of a task.
	 *
	 * @param int   $task_id The parent task ID.
	 * @param array $items   The raw items to store.
	 * @return array<int, array> The sanitized list as stored.
	 */
	public static function set( int $task_id, array $items ): array {
		$clean = self::sanitize_list( $items, $task_id );

		if ( empty( $clean ) ) {
			delete_post_meta( $task_id, self::META_KEY );
		} else {
			update_post_meta( $task_id, self::META_KEY, $clean );
		}

		return $clean;
	}

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
		$allowed_assignees = self::get_allowed_assignees( $task_id );
		$clean             = array();
		$used_ids          = array();

		foreach ( $items as $raw ) {
			if ( count( $clean ) >= self::MAX_ITEMS ) {
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

		$title = isset( $raw['title'] ) ? sanitize_text_field( (string) $raw['title'] ) : '';
		$title = trim( $title );

		// A title is the only required field: an item without one carries no
		// information and would render as an unlabeled row.
		if ( '' === $title ) {
			return null;
		}

		if ( mb_strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			$title = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		$status = isset( $raw['status'] ) ? sanitize_key( (string) $raw['status'] ) : '';
		if ( ! in_array( $status, self::get_statuses(), true ) ) {
			$status = 'to-do';
		}

		$assignee = isset( $raw['assignee'] ) ? absint( $raw['assignee'] ) : 0;
		if ( ! in_array( $assignee, $allowed_assignees, true ) ) {
			$assignee = 0;
		}

		return array(
			'id'       => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
			'title'    => $title,
			'status'   => $status,
			'assignee' => $assignee,
			'duedate'  => self::sanitize_date( isset( $raw['duedate'] ) ? $raw['duedate'] : '' ),
			'task_id'  => self::sanitize_promoted_task( isset( $raw['task_id'] ) ? $raw['task_id'] : 0 ),
		);
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param mixed $value The raw date value.
	 * @return string The date in Y-m-d form, or an empty string.
	 */
	private static function sanitize_date( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$date = DateTime::createFromFormat( 'Y-m-d', $value );

		// createFromFormat accepts out-of-range parts (2026-13-45) and rolls them
		// over, so the round-trip comparison is what actually validates the date.
		if ( ! $date instanceof DateTime || $date->format( 'Y-m-d' ) !== $value ) {
			return '';
		}

		return $value;
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

	/**
	 * The users that may be assigned to a subtask of the given task.
	 *
	 * Restricted to the people already on the card (the responsable plus the
	 * assignees), so splitting a task never silently pulls in someone who is
	 * not working on it.
	 *
	 * @param int $task_id The parent task ID.
	 * @return array<int, int> The allowed user IDs.
	 */
	public static function get_allowed_assignees( int $task_id ): array {
		if ( $task_id <= 0 ) {
			return array();
		}

		$ids = array( (int) get_post_meta( $task_id, 'responsable', true ) );

		$assigned = get_post_meta( $task_id, 'assigned_users', true );
		if ( is_array( $assigned ) ) {
			foreach ( $assigned as $user_id ) {
				$ids[] = (int) $user_id;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Count the items of a list per state.
	 *
	 * @param array $items The subtask items.
	 * @return array{total:int,done:int,counts:array<string,int>,percent:int} The progress summary.
	 */
	public static function progress( array $items ): array {
		$counts = array_fill_keys( self::get_statuses(), 0 );

		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? (string) $item['status'] : '';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		$total = array_sum( $counts );
		$done  = $counts['done'];

		return array(
			'total'   => $total,
			'done'    => $done,
			'counts'  => $counts,
			'percent' => $total > 0 ? (int) round( $done * 100 / $total ) : 0,
		);
	}

	/**
	 * Pick the few items a board card shows as chips, and what is left over.
	 *
	 * Done items never get a chip: the `done/total` counter and the bar already
	 * carry them, and what the board is scanned for is what is still open — a
	 * finished task showing no chips is the right signal.
	 *
	 * The remaining items are ordered so that the viewer's own work surfaces
	 * first, which is the whole reason the chips exist: with a cap of three,
	 * whoever opens the board sees their own steps without opening the card.
	 * Two people therefore see different chips on the same card.
	 *
	 * @param array $items   The subtask items.
	 * @param int   $user_id The viewer, whose items come first. 0 for none.
	 * @param int   $limit   How many chips the card has room for.
	 * @return array{chips:array<int, array>,remaining:int} The chips and the overflow count.
	 */
	public static function select_card_chips( array $items, int $user_id, int $limit = 3 ): array {
		$open = array();

		foreach ( $items as $item ) {
			if ( 'done' !== $item['status'] ) {
				$open[] = $item;
			}
		}

		// usort is stable as of PHP 8.0, so items of equal rank keep the order
		// the list was saved in.
		usort(
			$open,
			function ( array $left, array $right ) use ( $user_id ): int {
				return self::chip_rank( $left, $user_id ) <=> self::chip_rank( $right, $user_id );
			}
		);

		$chips = array_slice( $open, 0, max( 0, $limit ) );

		return array(
			'chips'     => $chips,
			'remaining' => count( $open ) - count( $chips ),
		);
	}

	/**
	 * The sort rank of an open item: mine before everyone's, started before not.
	 *
	 * @param array $item    The subtask item.
	 * @param int   $user_id The viewer.
	 * @return int The rank, lowest first.
	 */
	private static function chip_rank( array $item, int $user_id ): int {
		$mine    = $user_id > 0 && (int) $item['assignee'] === $user_id;
		$started = 'in-progress' === $item['status'];

		return ( $mine ? 0 : 2 ) + ( $started ? 0 : 1 );
	}

	/**
	 * Find an item by id inside a list.
	 *
	 * @param array $items   The subtask items.
	 * @param int   $item_id The item id to find.
	 * @return int|null The list index, or null when absent.
	 */
	public static function find_index( array $items, int $item_id ): ?int {
		foreach ( $items as $index => $item ) {
			if ( isset( $item['id'] ) && (int) $item['id'] === $item_id ) {
				return (int) $index;
			}
		}

		return null;
	}
}
