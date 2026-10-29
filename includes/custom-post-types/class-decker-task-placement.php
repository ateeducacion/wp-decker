<?php
/**
 * Placing a task next to a named card.
 *
 * The index form of a reorder cannot be exact for a caller who is not allowed
 * to see every card: positions count hidden tasks, a client cannot enumerate
 * the ones belonging to other people, so an index derived from a filtered view
 * is not the index the order engine works with. A neighbour is an id, and the
 * id means the same thing on both sides, so the move is resolved here, on the
 * server, where the whole stack is visible.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Placement
 */
class Decker_Task_Placement {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * The stack and order engine.
	 *
	 * @var Decker_Task_Order
	 */
	private $order;

	/**
	 * Constructor.
	 *
	 * @param Decker_Tasks      $tasks The task post type coordinator.
	 * @param Decker_Task_Order $order The stack and order engine.
	 */
	public function __construct( Decker_Tasks $tasks, Decker_Task_Order $order ) {
		$this->tasks = $tasks;
		$this->order = $order;
	}

	/**
	 * Place a task immediately before or after another card.
	 *
	 * The index form of this operation cannot be exact for a caller who is not
	 * allowed to see every card: positions count hidden tasks, a client cannot
	 * enumerate the ones belonging to other people, so an index derived from a
	 * filtered view is not the index this engine works with. A neighbour is an
	 * id, and the id means the same thing on both sides, so the move is
	 * resolved here, where the whole stack is visible.
	 *
	 * The destination is the neighbour's board and stack, which is what makes
	 * this also a move: placing a card next to one in another stack takes it
	 * there.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error The result, or a validation error.
	 */
	public function place( $request ) {
		$task_id = (int) $request['id'];

		$placement = $this->resolve_placement(
			$task_id,
			(int) $request->get_param( 'after_task_id' ),
			(int) $request->get_param( 'before_task_id' )
		);

		if ( is_wp_error( $placement ) ) {
			return $placement;
		}

		list( 'side' => $side, 'anchor_id' => $anchor_id, 'board_id' => $board_id ) = $placement;
		$source_stack = $placement['source_stack'];
		$target_stack = $placement['target_stack'];

		$answer = array(
			'success'     => true,
			'status'      => 'success',
			'message'     => 'Task placed.',
			'relative_to' => $anchor_id,
			'side'        => $side,
			'stack'       => $target_stack,
		);

		// Already there: answer without writing. A write would rotate the edit
		// generation and invalidate somebody's open form for a move that does
		// not move anything. The caller cannot make this check itself, because
		// adjacency depends on cards it may not be allowed to see.
		if ( $source_stack === $target_stack && $this->is_adjacent( $task_id, $anchor_id, $board_id, $target_stack, $side ) ) {
			$answer['message']  = 'Task already placed.';
			$answer['adjacent'] = true;
			$answer['changed']  = false;

			return new WP_REST_Response( $answer, 200 );
		}

		// Checked after the adjacency read-back, not with the other
		// validation: two cards of different priority can already be adjacent
		// at the boundary between the two groups, and a request for a
		// placement that is already true is answered rather than refused.
		$mismatch = $this->priority_error( $task_id, $anchor_id );
		if ( is_wp_error( $mismatch ) ) {
			return $mismatch;
		}

		$this->order->apply_stack_transition( $task_id, $source_stack, $target_stack );
		$this->shift_task_beside( $task_id, $anchor_id, $board_id, $target_stack, $side );

		if ( $source_stack !== $target_stack ) {
			$this->order->reorder_tasks_in_stack( $board_id, $source_stack );
		}

		$result = $this->order->reorder_tasks_in_stack( $board_id, $target_stack );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The card moved: any open editor form now holds a stale stack.
		$this->tasks->get_task_locks()->invalidate_sessions( $task_id );

		// Read back from the stored order rather than assumed, and stated as a
		// fact about the two cards only: the sequence itself is not returned,
		// because it contains tasks the caller may not be allowed to see.
		$answer['adjacent'] = $this->is_adjacent( $task_id, $anchor_id, $board_id, $target_stack, $side );
		$answer['changed']  = true;

		return new WP_REST_Response( $answer, 200 );
	}

	/**
	 * Validate a placement request and resolve its destination.
	 *
	 * @param int $task_id   The task to move.
	 * @param int $after_id  The neighbour to follow, or 0.
	 * @param int $before_id The neighbour to precede, or 0.
	 * @return array<string, mixed>|WP_Error The resolved placement, or an error.
	 */
	private function resolve_placement( int $task_id, int $after_id, int $before_id ) {
		if ( ( $after_id && $before_id ) || ( ! $after_id && ! $before_id ) ) {
			return new WP_Error(
				'decker_invalid_anchor',
				__( 'Give exactly one of after_task_id or before_task_id.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		$anchor_id = $after_id ? $after_id : $before_id;

		$invalid = $this->anchor_error( $task_id, $anchor_id );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		$board_id = $this->board_term_id( $task_id );

		$incompatible = $this->compatibility_error( $task_id, $anchor_id, $board_id );
		if ( is_wp_error( $incompatible ) ) {
			return $incompatible;
		}

		return array(
			'side'         => $after_id ? 'after' : 'before',
			'anchor_id'    => $anchor_id,
			'board_id'     => $board_id,
			'source_stack' => (string) get_post_meta( $task_id, 'stack', true ),
			'target_stack' => (string) get_post_meta( $anchor_id, 'stack', true ),
		);
	}

	/**
	 * Whether both tasks exist and the neighbour may be named by this caller.
	 *
	 * @param int $task_id   The task to move.
	 * @param int $anchor_id The neighbour.
	 * @return WP_Error|null An error, or null when both are usable.
	 */
	private function anchor_error( int $task_id, int $anchor_id ): ?WP_Error {
		$task = get_post( $task_id );

		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_Error( 'decker_invalid_task', __( 'Task not found.', 'decker' ), array( 'status' => 404 ) );
		}

		// An archived card is not in any stack: the renumber pass and the
		// adjacency read-back both look at published tasks only, so moving one
		// would shift its neighbours and then report that the placement did
		// not hold. Refused before anything is written, like the other routes
		// that decline to edit an archived card.
		if ( 'publish' !== $task->post_status ) {
			return new WP_Error(
				'decker_task_archived',
				__( 'You are not allowed to edit this card.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		if ( $anchor_id === $task_id ) {
			return new WP_Error(
				'decker_invalid_anchor',
				__( 'A task cannot be placed next to itself.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		$anchor = get_post( $anchor_id );
		$stack  = (string) get_post_meta( $anchor_id, 'stack', true );

		// A hidden card the caller may not see must answer as if it did not
		// exist: the id is usable as a neighbour only by someone entitled to
		// see it, or this route would confirm the existence of hidden tasks.
		// Not being on a board or in a stack answers the same way.
		$unusable = ! $anchor
			|| 'decker_task' !== $anchor->post_type
			|| 'publish' !== $anchor->post_status
			|| ! in_array( $stack, array( 'to-do', 'in-progress', 'done' ), true )
			|| Decker_Tasks::is_hidden_from_current_user( $anchor_id );

		if ( $unusable ) {
			return new WP_Error(
				'decker_anchor_not_found',
				__( 'The task to place this one next to was not found.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		return null;
	}

	/**
	 * Whether the two cards are on the same board.
	 *
	 * @param int $task_id   The task to move.
	 * @param int $anchor_id The neighbour.
	 * @param int $board_id  The board term the task belongs to.
	 * @return WP_Error|null An error, or null when they are compatible.
	 */
	private function compatibility_error( int $task_id, int $anchor_id, int $board_id ): ?WP_Error {
		if ( ! $board_id || $board_id !== $this->board_term_id( $anchor_id ) ) {
			return new WP_Error(
				'decker_anchor_other_board',
				__( 'Both tasks have to be on the same board.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * Whether a move across the priority boundary could hold.
	 *
	 * The renumber pass sorts max_priority first, so a card pinned to the
	 * front cannot be moved to sit next to one that is not: whatever this
	 * writes to menu_order, the sort puts the two groups back. Refusing up
	 * front beats writing a move and then reporting that it did not hold.
	 *
	 * The one placement across the boundary that does hold is the one that is
	 * already true, and place() answers that before asking this.
	 *
	 * @param int $task_id   The task to move.
	 * @param int $anchor_id The neighbour.
	 * @return WP_Error|null An error, or null when both carry the same priority.
	 */
	private function priority_error( int $task_id, int $anchor_id ): ?WP_Error {
		if ( (bool) get_post_meta( $task_id, 'max_priority', true ) === (bool) get_post_meta( $anchor_id, 'max_priority', true ) ) {
			return null;
		}

		return new WP_Error(
			'decker_priority_mismatch',
			__( 'Maximum priority pulls a card to the front of its stack, so these two cannot be adjacent. Match their priority first.', 'decker' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The board term a task belongs to.
	 *
	 * @param int $task_id The task ID.
	 * @return int Board term ID, or 0 when the task has no board.
	 */
	private function board_term_id( int $task_id ): int {
		$board_ids = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );

		return is_wp_error( $board_ids ) || empty( $board_ids ) ? 0 : (int) $board_ids[0];
	}

	/**
	 * Give the task the neighbour's slot, shifting the cards at or past it.
	 *
	 * Both sides reduce to the same statement: the destination slot is the
	 * neighbour's own menu_order when placing before it, and the one after that
	 * when placing after it. Everything from that slot onwards moves up to make
	 * room, which keeps the neighbour on the correct side of the moved card
	 * before the renumber pass closes the gaps.
	 *
	 * @param int    $task_id   The task being moved.
	 * @param int    $anchor_id The neighbour.
	 * @param int    $board_id  The board term ID.
	 * @param string $stack     The destination stack.
	 * @param string $side      Either "before" or "after".
	 */
	private function shift_task_beside( int $task_id, int $anchor_id, int $board_id, string $stack, string $side ) {
		global $wpdb;

		$anchor_order = (int) get_post_field( 'menu_order', $anchor_id );
		$slot         = 'after' === $side ? $anchor_order + 1 : $anchor_order;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr
					ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt
					ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->postmeta} pm_stack
					ON p.ID = pm_stack.post_id
					AND pm_stack.meta_key = 'stack'
				SET p.menu_order = p.menu_order + 1
				WHERE p.post_type = 'decker_task'
					AND p.post_status = 'publish'
					AND pm_stack.meta_value = %s
					AND tt.term_id = %d
					AND p.ID != %d
					AND p.menu_order >= %d",
				$stack,
				$board_id,
				$task_id,
				$slot
			)
		);

		$wpdb->update(
			$wpdb->posts,
			array(
				'menu_order'        => $slot,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => $task_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		// Raw SQL again, so WordPress still holds the old menu_order.
		clean_post_cache( $task_id );
		$this->order->flush_stack_cache( $board_id, $stack );
	}

	/**
	 * Whether the task ended up on the requested side of its neighbour.
	 *
	 * @param int    $task_id   The task that was moved.
	 * @param int    $anchor_id The neighbour.
	 * @param int    $board_id  The board term ID.
	 * @param string $stack     The stack both are in.
	 * @param string $side      Either "before" or "after".
	 * @return bool True when the two are adjacent in the stored order.
	 */
	private function is_adjacent( int $task_id, int $anchor_id, int $board_id, string $stack, string $side ): bool {
		$sequence = $this->stack_sequence( $board_id, $stack );
		$task     = array_search( $task_id, $sequence, true );
		$anchor   = array_search( $anchor_id, $sequence, true );

		if ( false === $task || false === $anchor ) {
			return false;
		}

		return 'after' === $side ? $task === $anchor + 1 : $task === $anchor - 1;
	}

	/**
	 * The ids of a stack in stored order.
	 *
	 * @param int    $board_id The board term ID.
	 * @param string $stack    The stack slug.
	 * @return int[] Task IDs, in order.
	 */
	private function stack_sequence( int $board_id, string $stack ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr
					ON p.ID = tr.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt
					ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->postmeta} pm_stack
					ON p.ID = pm_stack.post_id
					AND pm_stack.meta_key = 'stack'
				LEFT JOIN {$wpdb->postmeta} pm_priority
					ON p.ID = pm_priority.post_id
					AND pm_priority.meta_key = 'max_priority'
				WHERE p.post_type = 'decker_task'
					AND p.post_status = 'publish'
					AND pm_stack.meta_value = %s
					AND tt.term_id = %d
				GROUP BY p.ID
				ORDER BY
					COALESCE(CAST(MAX(pm_priority.meta_value) AS UNSIGNED), 0) DESC,
					p.menu_order ASC,
					p.post_modified DESC,
					p.ID ASC",
				$stack,
				$board_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
