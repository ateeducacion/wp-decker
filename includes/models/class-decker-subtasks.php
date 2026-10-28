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
	 * An item that was promoted is shown as the task it became — see
	 * adopt_linked_task() — so the two never say different things.
	 *
	 * @param int $task_id The parent task ID.
	 * @return array<int, array> The subtask items.
	 */
	public static function get( int $task_id ): array {
		if ( $task_id <= 0 ) {
			return array();
		}

		return array_map( array( __CLASS__, 'adopt_linked_task' ), self::read_stored( $task_id ) );
	}

	/**
	 * Read the stored list without showing promoted items as their task.
	 *
	 * What a write has to start from: feeding get() back into set() would
	 * store the borrowed title, owner and date of every promoted row over the
	 * snapshot those rows fall back to.
	 *
	 * @param int $task_id The parent task ID.
	 * @return array<int, array> The stored subtask items.
	 */
	private static function read_stored( int $task_id ): array {
		$stored = get_post_meta( $task_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return Decker_Subtask_Sanitizer::sanitize_list( $stored, $task_id );
	}

	/**
	 * Take the row pointing at a task off the list that holds it.
	 *
	 * Archiving a promoted task is how someone says that step is over and out
	 * of the way, so the row goes with it instead of sitting in the parent's
	 * list naming a card nobody can reach. Unarchiving does not bring it back:
	 * the list is a list, not a history.
	 *
	 * @param int $task_id The task that was archived.
	 * @return void
	 */
	public static function detach_from_parent( int $task_id ): void {
		$parent_id = self::get_parent_task_id( $task_id );
		if ( null === $parent_id ) {
			return;
		}

		$kept = array();
		foreach ( self::read_stored( $parent_id ) as $item ) {
			if ( (int) $item['task_id'] !== $task_id ) {
				$kept[] = $item;
			}
		}

		self::set( $parent_id, $kept );
	}

	/**
	 * Show a promoted item as the task it became.
	 *
	 * Once an item is promoted it stops being the work and becomes a pointer
	 * to it, so the stored copy of its title, owner, date and state is a
	 * snapshot that starts drifting the moment anyone touches that task. The
	 * row reads them from the task instead, which is also why it is no longer
	 * editable here. The stored copy survives untouched and is what is left if
	 * the task is ever deleted.
	 *
	 * The owner is carried along as a name and a face: it is the task's
	 * responsable, who need not be anyone on this card, so the row cannot look
	 * them up in the parent's people.
	 *
	 * @param array $item The subtask item.
	 * @return array<string, mixed> The item as it is shown.
	 */
	private static function adopt_linked_task( array $item ): array {
		$linked = (int) $item['task_id'];
		if ( $linked <= 0 ) {
			return $item;
		}

		$post = get_post( $linked );
		if ( ! $post instanceof WP_Post || 'decker_task' !== $post->post_type || 'trash' === $post->post_status ) {
			return $item;
		}

		$item['title'] = mb_substr( (string) $post->post_title, 0, self::MAX_TITLE_LENGTH );

		$responsable = (int) get_post_meta( $linked, 'responsable', true );
		if ( $responsable <= 0 ) {
			$responsable = (int) $post->post_author;
		}
		$item['assignee'] = $responsable;

		$duedate         = (string) get_post_meta( $linked, 'duedate', true );
		$item['duedate'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $duedate ) ? $duedate : '';

		$stack = (string) get_post_meta( $linked, 'stack', true );
		if ( in_array( $stack, self::get_statuses(), true ) ) {
			$item['status'] = $stack;
		}

		$user                    = $responsable > 0 ? get_userdata( $responsable ) : false;
		$item['assignee_name']   = $user instanceof WP_User ? $user->display_name : '';
		$item['assignee_avatar'] = $user instanceof WP_User ? get_avatar_url( $user->ID, array( 'size' => 48 ) ) : '';

		return $item;
	}

	/**
	 * Replace the whole subtask list of a task.
	 *
	 * Returns the list the way get() reads it back, not the raw stored one, so
	 * the answer to a write and the next page load agree about the promoted
	 * rows: those are shown as the task they became, not as what was sent.
	 *
	 * @param int   $task_id The parent task ID.
	 * @param array $items   The raw items to store.
	 * @return array<int, array> The list as it is now read.
	 */
	public static function set( int $task_id, array $items ): array {
		$clean = Decker_Subtask_Sanitizer::sanitize_list( $items, $task_id );

		if ( empty( $clean ) ) {
			delete_post_meta( $task_id, self::META_KEY );
		} else {
			update_post_meta( $task_id, self::META_KEY, $clean );
		}

		// The promotion links just changed, so the map built for this request
		// no longer describes them.
		self::$parents = null;

		return self::get( $task_id );
	}

	/**
	 * Child task ID to the task whose list holds it, for every stored list.
	 *
	 * Null until first needed, and reset on every write.
	 *
	 * @var array<int, int>|null
	 */
	private static $parents = null;

	/**
	 * The task this one was promoted out of, if any.
	 *
	 * Looked up rather than stored: the item holds the only link, and a second
	 * copy of it on the child would need writing on promotion, clearing on
	 * unlink, and a backfill for every task promoted before it existed — three
	 * ways to disagree with the list, which stays the one source.
	 *
	 * @param int $task_id The task that may be a subtask.
	 * @return int|null The parent task ID, or null when it is not one.
	 */
	public static function get_parent_task_id( int $task_id ): ?int {
		if ( $task_id <= 0 ) {
			return null;
		}

		$parents = self::parent_map();

		return isset( $parents[ $task_id ] ) ? $parents[ $task_id ] : null;
	}

	/**
	 * Every promotion link on the site, read once per request.
	 *
	 * A board renders dozens of cards and each one asks whether it came out of
	 * a list, so asking per card would be a query per card. Every stored list
	 * is read in one go instead and the links are indexed by child.
	 *
	 * @return array<int, int> Child task ID to parent task ID.
	 */
	private static function parent_map(): array {
		if ( null !== self::$parents ) {
			return self::$parents;
		}

		global $wpdb;

		self::$parents = array();

		// Direct query: there is no meta API call that reads one meta key
		// across every post, and the alternative is one query per card. The
		// result is held for the rest of the request and dropped on any write.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY )
		);

		foreach ( (array) $rows as $row ) {
			$items = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$child = isset( $item['task_id'] ) ? (int) $item['task_id'] : 0;
				if ( $child > 0 ) {
					self::$parents[ $child ] = (int) $row->post_id;
				}
			}
		}

		return self::$parents;
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
