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
	 * On by default: a missing key is a site that has never saved the settings
	 * screen, and a feature nobody can see is a feature nobody adopts. Clearing
	 * the checkbox stores '0', so an explicit opt-out is never overridden.
	 *
	 * @return bool True when the setting is on.
	 */
	public static function is_enabled(): bool {
		$options = get_option( 'decker_settings', array() );

		if ( ! is_array( $options ) || ! isset( $options['subtasks_enabled'] ) ) {
			return true;
		}

		return '1' === $options['subtasks_enabled'];
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

		return Decker_Subtask_Sanitizer::sanitize_list( $stored, $task_id );
	}

	/**
	 * Replace the whole subtask list of a task.
	 *
	 * @param int   $task_id The parent task ID.
	 * @param array $items   The raw items to store.
	 * @return array<int, array> The sanitized list as stored.
	 */
	public static function set( int $task_id, array $items ): array {
		$clean = Decker_Subtask_Sanitizer::sanitize_list( $items, $task_id );

		if ( empty( $clean ) ) {
			delete_post_meta( $task_id, self::META_KEY );
		} else {
			update_post_meta( $task_id, self::META_KEY, $clean );
		}

		return $clean;
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

		// Resolved the way Task does it: a task with no `responsable` meta is
		// its author's, so the author has to be offered here too. Without the
		// fallback a task created outside the card form (over the generic REST
		// endpoint, by the mail parser) offers nobody at all.
		$responsable = (int) get_post_meta( $task_id, 'responsable', true );
		if ( $responsable <= 0 ) {
			$responsable = (int) get_post_field( 'post_author', $task_id );
		}

		$ids = array( $responsable );

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
	 * The rest keep the order they are stored in. The list is hand-ordered by
	 * dragging, so the card showing a different order than the card's own
	 * Subtasks tab made the two impossible to read against each other; whose
	 * item it is shows as the chip's tint instead of as a place in the row.
	 *
	 * @param array $items The subtask items.
	 * @param int   $limit How many chips the card has room for.
	 * @return array{chips:array<int, array>,remaining:int} The chips and the overflow count.
	 */
	public static function select_card_chips( array $items, int $limit = 3 ): array {
		$open = array();

		foreach ( $items as $item ) {
			if ( 'done' !== $item['status'] ) {
				$open[] = $item;
			}
		}

		$chips = array_slice( $open, 0, max( 0, $limit ) );

		return array(
			'chips'     => $chips,
			'remaining' => count( $open ) - count( $chips ),
		);
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
