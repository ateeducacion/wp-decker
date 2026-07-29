<?php
/**
 * WordPress hook adapters for the stack/order engine.
 *
 * Reacts to the WordPress events that require a stack/order recalculation:
 * the post-data filter on insert, board term changes, and stack meta
 * changes (including the first time the meta is added). Drives the engine
 * it is constructed with; owns no state of its own.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Order_Hooks
 */
class Decker_Task_Order_Hooks {

	/**
	 * The stack/order engine.
	 *
	 * @var Decker_Task_Order
	 */
	private $order;

	/**
	 * Constructor.
	 *
	 * @param Decker_Task_Order $order The stack/order engine.
	 */
	public function __construct( Decker_Task_Order $order ) {
		$this->order = $order;

		add_filter( 'wp_insert_post_data', array( $this, 'modify_task_order_before_save' ), 10, 4 );
		add_action( 'set_object_terms', array( $this, 'handle_board_change_reorder' ), 10, 6 );

		// Reorder when only the 'stack' meta is changed.
		add_action( 'updated_post_meta', array( $this, 'handle_stack_change_reorder' ), 10, 4 );

		// Also capture when the meta is added for the first time.
		add_action(
			'added_post_meta',
			array( $this, 'handle_stack_change_reorder' ),
			10,
			4
		);
	}

	/**
	 * Modifies the `menu_order` of a task before it is saved.
	 *
	 * Prevents direct user modification of the `menu_order` field and calculates
	 * the appropriate value based on the `decker_board` and `stack` fields. This is
	 * applied only when a new task is being created.
	 *
	 * @param array $data                The sanitized data to be saved for the post.
	 * @param array $postarr             The original post array containing input data.
	 * @param array $unsanitized_postarr The unsanitized post array.
	 * @param bool  $update              Whether the post is being updated (true) or created (false).
	 * @return array The modified data array with the updated `menu_order`.
	 *
	 * @throws WP_Error Logs warnings or errors in the error log if required fields are missing or invalid.
	 */
	public function modify_task_order_before_save( array $data, array $postarr, array $unsanitized_postarr, bool $update ) {

		// Prevent the user from directly modifying the menu_order.
		if ( isset( $postarr['menu_order'] ) ) {
			// Remove the menu_order field so it won't be saved.
			unset( $postarr['menu_order'] );
		}

		// Ensure we're working with the correct post type and only on Insert post.
		if ( ! $update && 'decker_task' === $postarr['post_type'] ) {

			$board = $this->resolve_new_task_board( $postarr );
			$stack = $this->resolve_new_task_stack( $postarr );

			$data = $this->apply_calculated_menu_order( $data, $board, $stack, $postarr );
		}

		return $data;
	}

	/**
	 * Resolve the board ID for a task being inserted from the post array.
	 *
	 * @param array $postarr The original post array containing input data.
	 * @return int The board term ID, or 0 when absent.
	 */
	private function resolve_new_task_board( array $postarr ): int {
		$board = '';

		if ( isset( $postarr['decker_board'] ) ) {
			$board = intval( $postarr['decker_board'] );
		}

		if ( empty( $board ) && isset( $postarr['tax_input']['decker_board'][0] ) ) {
			$board = intval( $postarr['tax_input']['decker_board'][0] );
		}

		return (int) $board;
	}

	/**
	 * Resolve the stack value for a task being inserted from the post array.
	 *
	 * @param array $postarr The original post array containing input data.
	 * @return string The stack value, or '' when absent.
	 */
	private function resolve_new_task_stack( array $postarr ): string {
		$stack = '';

		if ( isset( $postarr['stack'] ) ) {
			$stack = sanitize_text_field( $postarr['stack'] );
		}

		if ( empty( $stack ) && isset( $postarr['meta_input']['stack'] ) ) {
			$stack = sanitize_text_field( $postarr['meta_input']['stack'] );
		}

		return (string) $stack;
	}

	/**
	 * Apply the calculated menu_order to the post data when board and stack are present.
	 *
	 * @param array  $data    The sanitized data to be saved for the post.
	 * @param int    $board   The resolved board term ID.
	 * @param string $stack   The resolved stack value.
	 * @param array  $postarr The original post array containing input data.
	 * @return array The data array, possibly with an updated menu_order.
	 */
	private function apply_calculated_menu_order( array $data, int $board, string $stack, array $postarr ): array {
		// Validate that both 'board' and 'stack' have been retrieved.
		if ( ! empty( $board ) && ! empty( $stack ) ) {

			// Calculate the new order value based on 'board' and 'stack'.
			$new_order = $this->order->get_new_task_order( $board, $stack );

			// Ensure that the new order is a valid number.
			if ( is_numeric( $new_order ) ) {
				// Assign the calculated menu_order to the post data.
				$data['menu_order'] = intval( $new_order );
			} else {
				// Log an error if the new_order is not numeric.
				error_log( "Invalid 'new_order' value: $new_order for post ID: " . $postarr['ID'] );
			}
		} else {
			// Log a warning if either 'board' or 'stack' is missing.
			error_log( "Missing 'decker_board' or 'stack' for post ID: " . $postarr['ID'] );
		}

		return $data;
	}

	/**
	 * Reorders tasks when the board of a task is changed.
	 *
	 * This hook is triggered when a task is moved from one board to another.
	 * It updates the task's `menu_order` if needed and reorders both the
	 * old and new board stacks accordingly.
	 *
	 * @param int    $object_id   Post ID of the task.
	 * @param array  $terms       New term IDs.
	 * @param array  $tt_ids      New term taxonomy IDs.
	 * @param string $taxonomy    Taxonomy slug.
	 * @param bool   $append      Whether to append new terms.
	 * @param array  $old_tt_ids  Old term taxonomy IDs.
	 */
	public function handle_board_change_reorder( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		// Only act for 'decker_board' taxonomy and 'decker_task' CPT, and only if terms are replaced.
		if ( 'decker_board' !== $taxonomy || 'decker_task' !== get_post_type( $object_id ) || $append ) {
			return;
		}

		// Get the new and old board term IDs.
		// Assume that only one board is assigned at a time.
		$new_board_term_id = ! empty( $tt_ids ) ? (int) $tt_ids[0] : 0;
		$old_board_term_id = ! empty( $old_tt_ids ) ? (int) $old_tt_ids[0] : 0;

		// Proceed only if the board has actually changed.
		if ( $new_board_term_id === $old_board_term_id ) {
			return;
		}

		// Get the current stack for the task.
		$current_stack = get_post_meta( $object_id, 'stack', true );
		$valid_stacks = array( 'to-do', 'in-progress', 'done' );

		// If the moved task is NOT of max priority, push it to the end of the destination board.
		$this->move_task_to_board_end( $object_id, $new_board_term_id, $current_stack );

		// Reorder tasks in the new board (including the moved task).
		// 1. Reorder new board.
		if ( $new_board_term_id > 0 ) {
			// error_log("Decker Reorder Hook: Reordering NEW board {$new_board_term_id} / stack {$current_stack}");
			// Call the static function to reorder.
			$this->order->reorder_tasks_in_stack( $new_board_term_id, $current_stack );
		}

		// Reorder tasks in the old board (excluding the moved task).
		// 2. Reorder old board.
		if ( $old_board_term_id > 0 ) {
			// error_log("Decker Reorder Hook: Reordering OLD board {$old_board_term_id} / stack {$current_stack} (excluding {$object_id})");
			// Call the static function to reorder.
			$this->order->reorder_tasks_in_stack( $old_board_term_id, $current_stack, $object_id );
		}

		// At the end of handle_board_change_reorder().
		set_transient( "decker_board_changed_{$object_id}", 1, 5 );
	}

	/**
	 * Push a non-max-priority task to the end of its destination board stack.
	 *
	 * @param int    $object_id         Post ID of the task.
	 * @param int    $new_board_term_id The destination board term ID.
	 * @param string $current_stack     The current stack for the task.
	 */
	private function move_task_to_board_end( int $object_id, int $new_board_term_id, string $current_stack ) {
		// If the moved task is NOT of max priority, calculate its new order at the end of the destination board.
		$is_max_priority = get_post_meta( $object_id, 'max_priority', true );
		if ( empty( $is_max_priority ) || '0' === $is_max_priority ) {
			// Get the next available order in the new board/stack.
			$new_order = $this->order->get_new_task_order( $new_board_term_id, $current_stack );
			if ( is_numeric( $new_order ) ) {
				global $wpdb;

				// Temporarily assign that menu_order to the moved task.
				$wpdb->update(
					$wpdb->posts,
					array( 'menu_order' => intval( $new_order ) ),
					array( 'ID' => $object_id ),
					array( '%d' ),
					array( '%d' )
				);
				clean_post_cache( $object_id );  // Clear cache to ensure updated read.
			}
		}
	}

	/**
	 * When the meta key 'stack' changes, move the task to the end of the
	 * destination stack and reorder both stacks.
	 *
	 * @param int    $meta_id    Meta row ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New meta value (destination stack).
	 */
	public function handle_stack_change_reorder( $meta_id, $post_id, $meta_key, $meta_value ) {

		if ( 'stack' !== $meta_key || 'decker_task' !== get_post_type( $post_id ) ) {
			return;
		}

		// Board term comes from taxonomy (source of truth).
		$board_ids = wp_get_post_terms( $post_id, 'decker_board', array( 'fields' => 'ids' ) );
		$board_id  = ! empty( $board_ids ) ? (int) $board_ids[0] : 0;
		if ( ! $board_id ) {
			return; // Task without board -> nothing to do.
		}

		$new_stack = sanitize_key( $meta_value );
		$old_stack = sanitize_key( get_metadata( 'post', $post_id, '_decker_prev_stack', true ) );

		// -----------------------------------------------------------------
		// LOG
		// error_log( sprintf(
		// '[Decker] Stack change: post=%d board=%d old=%s new=%s',
		// $post_id,
		// $board_id,
		// $old_stack,
		// $new_stack
		// ) );
		// -----------------------------------------------------------------

		// 1. Re-position *at the end* of the destination stack (not on top).
		$is_max = get_post_meta( $post_id, 'max_priority', true );

		if ( empty( $is_max ) || '0' === $is_max ) {
			global $wpdb;

			$max_order = (int) $wpdb->get_var(
				$wpdb->prepare(
					"
                SELECT COALESCE( MAX(p.menu_order), 0 )
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr  ON p.ID = tr.object_id
                INNER JOIN {$wpdb->term_taxonomy}  tt  ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->postmeta} pm        ON p.ID = pm.post_id
                WHERE p.post_type   = 'decker_task'
                  AND p.post_status = 'publish'
                  AND tt.term_id    = %d           -- board
                  AND pm.meta_key   = 'stack'
                  AND pm.meta_value = %s           -- stack
                  AND p.ID <> %d                   -- exclude current
                ",
					$board_id,
					$new_stack,
					$post_id
				)
			);

			$wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => $max_order + 1 ),
				array( 'ID' => $post_id ),
				array( '%d' ),
				array( '%d' )
			);
			clean_post_cache( $post_id );
		}

		// 2. Reorder destination stack (include the moved task).
		$this->order->reorder_tasks_in_stack( $board_id, $new_stack );

		// 3. Reorder origin stack (exclude the moved task).
		if ( $old_stack && $old_stack !== $new_stack ) {
			$this->order->reorder_tasks_in_stack( $board_id, $old_stack, $post_id );
		}

			// Save current stack as “previous” for the next move.
			update_post_meta( $post_id, '_decker_prev_stack', $new_stack );
	}
}
