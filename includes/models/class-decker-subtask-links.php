<?php
/**
 * The link between a promoted subtask and the task it became.
 *
 * One item in a list can be promoted into a real `decker_task`
 * (Decker_Tasks_Rest_Subtasks::convert_subtask()) and from then on the item is
 * only a pointer to it. Everything that follows from that pointer lives here,
 * apart from the list itself: reading the row as the task it points at, the
 * reverse lookup that lets a task say which list it came out of, and taking
 * the row off that list when the task is archived.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Subtask_Links
 */
class Decker_Subtask_Links {

	/**
	 * Child task ID to the task whose list holds it, for every stored list.
	 *
	 * Null until first needed, and reset on every write.
	 *
	 * @var array<int, int>|null
	 */
	private static $parents = null;

	/**
	 * Drop the request's promotion map after a list was written.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$parents = null;
	}

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
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", Decker_Subtasks::META_KEY )
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
		foreach ( Decker_Subtasks::read_stored( $parent_id ) as $item ) {
			if ( (int) $item['task_id'] !== $task_id ) {
				$kept[] = $item;
			}
		}

		Decker_Subtasks::set( $parent_id, $kept );
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
	public static function adopt( array $item ): array {
		$linked = (int) $item['task_id'];
		if ( $linked <= 0 ) {
			return $item;
		}

		$post = get_post( $linked );
		if ( ! $post instanceof WP_Post || 'decker_task' !== $post->post_type || 'trash' === $post->post_status ) {
			return $item;
		}

		$stack   = (string) get_post_meta( $linked, 'stack', true );
		$duedate = (string) get_post_meta( $linked, 'duedate', true );

		$item['title']   = mb_substr( (string) $post->post_title, 0, Decker_Subtasks::MAX_TITLE_LENGTH );
		$item['duedate'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $duedate ) ? $duedate : '';
		$item['status']  = in_array( $stack, Decker_Subtasks::get_statuses(), true ) ? $stack : $item['status'];

		return array_merge( $item, self::owner( $post ) );
	}

	/**
	 * Who a promoted item's task belongs to, as the row shows them.
	 *
	 * The task's responsable, resolved the way Task does it, with the name and
	 * the face alongside: that person need not be on the parent card, so the
	 * row cannot look them up in its people.
	 *
	 * @param WP_Post $post The task the item became.
	 * @return array{assignee:int,assignee_name:string,assignee_avatar:string} The owner.
	 */
	private static function owner( WP_Post $post ): array {
		$responsable = (int) get_post_meta( $post->ID, 'responsable', true );
		if ( $responsable <= 0 ) {
			$responsable = (int) $post->post_author;
		}

		$user = $responsable > 0 ? get_userdata( $responsable ) : false;
		if ( ! $user instanceof WP_User ) {
			return array(
				'assignee'        => $responsable,
				'assignee_name'   => '',
				'assignee_avatar' => '',
			);
		}

		return array(
			'assignee'        => $responsable,
			'assignee_name'   => $user->display_name,
			'assignee_avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
		);
	}
}
