<?php
/**
 * Stack and order engine for tasks.
 *
 * Owns the REST-facing stack/order operations and the primitives they
 * share: validating and applying a stack/order update, persisting the
 * menu_order with the destination shift, reordering a stack after a
 * change, and fixing a board's order end to end. Reaches the shared
 * edit-lock manager through the injected Decker_Tasks instance.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Order
 */
class Decker_Task_Order {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * Constructor.
	 *
	 * @param Decker_Tasks $tasks The task post type coordinator.
	 */
	public function __construct( Decker_Tasks $tasks ) {
		$this->tasks = $tasks;
	}

	/**
	 * Get the new order for a task in a specific stack.
	 *
	 * This function retrieves the maximum menu_order value for tasks in the specified board and stack and returns the next incremented value.
	 *
	 * @param int    $board_term_id The board to calculate the order for.
	 * @param string $stack The stack to calculate the order for.
	 * @return int The new order value.
	 */
	public function get_new_task_order( int $board_term_id, string $stack ) {
		// Query arguments to find posts in the specified stack.
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'tax_query'   => array(
				array(
					'taxonomy' => 'decker_board',
					'field'    => 'term_id',
					'terms'    => $board_term_id,
				),
			),
			'meta_query' => array(
				array(
					'key'     => 'stack',
					'value'   => $stack,
					'compare' => '=',
				),
			),
			'orderby'        => 'menu_order',
			'order'          => 'DESC',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);

		// Get the posts.
		$posts = get_posts( $args );

		// If a post exists, get its menu_order and increment it.
		if ( ! empty( $posts ) ) {
			$max_order = intval( get_post_field( 'menu_order', $posts[0] ) );
			return $max_order + 1;
		}

		// If no posts exist, start with order 1.
		return 1;
	}

	/**
	 * Update the stack and order of a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function update_task_stack_and_order( $request ) {
		$task_id      = intval( $request['id'] );
		$board_id     = intval( $request->get_param( 'board_id' ) );
		$source_stack = sanitize_text_field( $request->get_param( 'source_stack' ) );
		$target_stack = sanitize_text_field( $request->get_param( 'target_stack' ) );
		$source_order = intval( $request->get_param( 'source_order' ) );
		$target_order = intval( $request->get_param( 'target_order' ) );

		$invalid = $this->validate_stack_order_request(
			$task_id,
			$source_stack,
			$target_stack,
			$source_order,
			$target_order
		);
		if ( $invalid instanceof WP_REST_Response ) {
			return $invalid;
		}

		// Update the stack and the order.
		$this->apply_stack_transition( $task_id, $source_stack, $target_stack );

		$this->persist_task_menu_order( $task_id, $board_id, $source_stack, $target_stack, $source_order, $target_order );

		// Reorder tasks in the source stack.
		if ( $source_stack !== $target_stack ) {
			$result = $this->reorder_tasks_in_stack( $board_id, $source_stack );
		}
		// Reorder tasks in the target stack.
		$result = $this->reorder_tasks_in_stack( $board_id, $target_stack );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The card moved: any open editor form now holds a stale stack.
		$this->tasks->get_task_locks()->invalidate_sessions( $task_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'success',
				'message' => 'Task stack and order updated successfully.',
			),
			200
		);
	}

	/**
	 * Validate the parameters for a stack/order update request.
	 *
	 * @param int    $task_id      The task ID.
	 * @param string $source_stack The source stack value.
	 * @param string $target_stack The target stack value.
	 * @param int    $source_order The source order index.
	 * @param int    $target_order The target order index.
	 * @return WP_REST_Response|null The error response when invalid, or null when valid.
	 */
	private function validate_stack_order_request( int $task_id, string $source_stack, string $target_stack, int $source_order, int $target_order ): ?WP_REST_Response {
		$valid_stacks = array( 'to-do', 'in-progress', 'done' );

		if ( ! in_array( $source_stack, $valid_stacks ) || ! in_array( $target_stack, $valid_stacks ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid stack value.',
				),
				400
			);
		}

		if ( ! $task_id || ! $source_order || ! $target_order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid parameters.',
				),
				400
			);
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Task not found.',
				),
				404
			);
		}

		return null;
	}

	/**
	 * Apply a stack transition for a task and fire the related hooks.
	 *
	 * @param int    $task_id      The task ID.
	 * @param string $source_stack The source stack value.
	 * @param string $target_stack The target stack value.
	 */
	private function apply_stack_transition( int $task_id, string $source_stack, string $target_stack ) {
		if ( $source_stack != $target_stack ) {
			update_post_meta( $task_id, 'stack', $target_stack );

			// Trigger general stack transition hook.
			do_action( 'decker_stack_transition', $task_id, $source_stack, $target_stack );

			// If the target stack is "done", trigger a specific hook for task completion.
			if ( 'done' === $target_stack ) {
				do_action( 'decker_task_completed', $task_id, $target_stack, get_current_user_id() );
			}
		}
	}

	/**
	 * Persist the task menu_order using raw SQL and shift incumbents at the destination.
	 *
	 * @param int    $task_id      The task ID.
	 * @param int    $board_id     The board term ID.
	 * @param string $source_stack The source stack value.
	 * @param string $target_stack The target stack value.
	 * @param int    $source_order The source order index.
	 * @param int    $target_order The target order index.
	 */
	private function persist_task_menu_order( int $task_id, int $board_id, string $source_stack, string $target_stack, int $source_order, int $target_order ) {
		global $wpdb;

		$final_order = $target_order;
		// The +1 adjustment is only valid for moves within the same stack, where
		// source_order and target_order index the same column. For cross-stack moves
		// the two indexes reference different columns, so use target_order directly.
		if ( $source_stack === $target_stack && $target_order > $source_order ) {
			$final_order = $target_order + 1;
		}

		// Perform the update using raw SQL.
		$updated = $wpdb->update(
			$wpdb->posts,  // The WordPress posts table.
			array(
				'menu_order'        => $final_order,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => $task_id ), // The condition to match the correct row.
			array( '%d', '%s', '%s' ), // The data types of the values: integer and strings.
			array( '%d' )  // The data type of the condition (integer).
		);

		// Make room at the destination so the moved card deterministically occupies
		// $final_order. Without this shift the moved card and the incumbent at that
		// slot share a menu_order and the renumber tie-break would depend on
		// post_modified second-granularity (flaky), dropping the card one slot off.
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
				$target_stack,
				$board_id,
				$task_id,
				$final_order
			)
		);
	}

	/**
	 * Reorder tasks within a stack and board after a task is deleted.
	 *
	 * @param int    $board_term_id The board term ID.
	 * @param string $stack The stack to reorder.
	 * @param int    $exclude_post_id Task to exclude.
	 */
	public function reorder_tasks_in_stack( int $board_term_id, string $stack, int $exclude_post_id = 0 ) {
		global $wpdb;

		// This is the autoincrement value.
		$wpdb->query( 'SET @rownum := 0' );

		// Perform the UPDATE in a single statement.
		$result = $wpdb->query(
			$wpdb->prepare(
				"
				UPDATE {$wpdb->posts} p
			    INNER JOIN (
			        SELECT
			            t.ID,
			            (@rownum := @rownum + 1) AS new_menu_order
			        FROM (
			            SELECT 
			                p.ID, 
			                p.menu_order, 
			                COALESCE(CAST(pm_priority.meta_value AS UNSIGNED), 0) AS meta_value,
			                p.post_modified
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
			            WHERE 
			                p.post_type = 'decker_task'
			                AND p.post_status = 'publish'
			                AND pm_stack.meta_value = %s
			                AND tt.term_id = %d
			                AND p.ID != %d
			            GROUP BY 
			                p.ID
			            ORDER BY
			                meta_value DESC,
			                p.menu_order ASC,
			                p.post_modified DESC,
			                p.id ASC
			        ) AS t
			    ) AS ordered_tasks ON p.ID = ordered_tasks.ID
			    SET p.menu_order = ordered_tasks.new_menu_order;",
				$stack,
				$board_term_id,
				$exclude_post_id
			)
		);
	}

	/**
	 * Handle fixing the order for tasks in the specified board.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_fix_order( $request ) {
		$board_id = intval( $request['board_id'] );

		if ( $board_id <= 0 ) {
			return new WP_REST_Response(
				array(
					'message' => 'Invalid board ID.',
				),
				400
			);
		}

		$stacks = array( 'to-do', 'in-progress', 'done' );

		foreach ( $stacks as $stack ) {
			$this->reorder_tasks_in_stack( $board_id, $stack );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Tasks reordered successfully for board ' . $board_id . '.',
			),
			200
		);
	}
}
