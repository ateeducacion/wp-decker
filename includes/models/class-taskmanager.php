<?php
/**
 * File class-taskmanager
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class TaskManager
 *
 * Provides functionalities to manage tasks.
 */
class TaskManager {

	/**
	 * Retrieves a task by its ID.
	 *
	 * @param int $id The ID of the task.
	 * @return Task|null The Task object or null if not found.
	 */
	public function get_task( int $id ): ?Task {
		try {
			return new Task( $id );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Retrieves a list of tasks based on the given arguments.
	 *
	 * @param array $args Query arguments for WP_Query.
	 * @return Task[] List of Task objects.
	 */
	public function get_tasks( array $args = array() ): array {
		$default_args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
		);
		$query_args = array_merge( $default_args, $args );
		$posts      = get_posts( $query_args );

		// Carga todos los metadatos en caché de una sola vez.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids ); // 1 consulta para todos los metadatos.
		}

		$tasks      = array();

		foreach ( $posts as $post ) {
			try {
				$tasks[] = new Task( $post );
			} catch ( Exception $e ) {
				// Log or handle the error if needed.
				error_log( "Can't initialize Task from post: " . $post->ID );
			}
		}

		return $tasks;
	}

	/**
	 * Retrieves tasks by their status.
	 *
	 * @param string $status The status to filter by (e.g., 'publish', 'draft').
	 * @return Task[] List of Task objects.
	 */
	public function get_tasks_by_status( string $status ): array {
		$args = array(
			'post_status' => $status,
			'meta_key'    => 'max_priority', // Define field to use in order.
			'meta_type'   => 'BOOL',
			'orderby'     => array(
				'max_priority' => 'DESC',
			),
			'meta_query'  => array(
				'relation' => 'OR', // Relationship between the meta query conditions.
				array(
					'key'     => 'hidden', // Meta field 'hidden'.
					'compare' => 'NOT EXISTS', // Exclude tasks that do not have the 'hidden' meta field.
				),
				array(
					'key'     => 'hidden', // Meta field 'hidden'.
					'value'   => '1', // Value indicating that the task is hidden.
					'compare' => '!=', // Exclude tasks where 'hidden' is equal to '1'.
				),
			),
		);

		$tasks = $this->get_tasks( $args );
		return $tasks;
	}

	/**
	 * Retrieves tasks assigned to a specific user.
	 *
	 * @param int $user_id The user ID to filter tasks by.
	 * @return Task[] List of Task objects.
	 */
	public function get_tasks_by_user( int $user_id ): array {
		$args = array(
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key'     => 'assigned_users',
					'value'   => $user_id,
					'compare' => 'LIKE',
				),
				array(
					'key'     => 'responsable',
					'value'   => $user_id,
					'compare' => '=',
				),
			),
			'meta_key'  => 'max_priority', // Define field to use in order.
			'meta_type' => 'BOOL',
			'orderby'   => array(
				'max_priority' => 'DESC',
				'menu_order'   => 'ASC',
			),
		);

		$tasks = $this->get_tasks( $args );

		/**
		 * Additional filtering ensures the user truly appears in the assigned_users array
		 * or is the responsable. Serializing data with a LIKE can sometimes cause false positives.
		 */
		$filtered_tasks = array_filter(
			$tasks,
			function ( $task ) use ( $user_id ) {
				$is_assigned = false;
				if ( is_array( $task->assigned_users ) ) {
					foreach ( $task->assigned_users as $assigned_user ) {
						if ( (int) $assigned_user->ID === $user_id ) {
							$is_assigned = true;
							break;
						}
					}
				}
				$is_responsable = (
					isset( $task->responsable->ID ) &&
					( (int) $task->responsable->ID === $user_id )
				);
				return $is_assigned || $is_responsable;
			}
		);

		return $filtered_tasks;
	}

	/**
	 * Retrieves tasks by stack (custom meta field).
	 *
	 * @param string $stack The stack to filter tasks by.
	 * @return Task[] List of Task objects.
	 */
	public function get_tasks_by_stack( string $stack ): array {
		$args = array(
			'meta_query' => array(
				array(
					'key'     => 'stack',
					'value'   => $stack,
					'compare' => '=',
				),
			),
		);
		return $this->get_tasks( $args );
	}



	/**
	 * Retrieves tasks by Board (term relation).
	 *
	 * @param Board $board The board to filter tasks by.
	 * @return Task[] List of Task objects.
	 */
	public function get_tasks_by_board( Board $board ): array {
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'tax_query'   => array(
				array(
					'taxonomy' => 'decker_board',
					'field'    => 'slug',
					'terms'    => $board->slug,
				),
			),
			'meta_key'  => 'max_priority', // Define field to use in order.
			'meta_type' => 'BOOL',
			'orderby'   => array(
				'max_priority' => 'DESC',
				'menu_order'   => 'ASC',
			),
			'numberposts' => -1,
		);
		return $this->get_tasks( $args );
	}


	/**
	 * Checks if the current user has tasks assigned for today.
	 *
	 * @return bool True if the user has tasks for today, false otherwise.
	 */
	public function has_user_today_tasks(): bool {
		$user_id = get_current_user_id();
		$args    = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids', // Only retrieve IDs for performance optimization.
			'meta_query'  => array(
				'relation' => 'AND',
				array(
					'key'     => 'assigned_users',
					'value'   => $user_id,
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_user_date_relations',
					'compare' => 'EXISTS', // Only include tasks where the meta key exists.
				),
			),
		);

		// Important! Here we are using direct post_id retrieval for optimization.
		$post_ids = get_posts( $args );

		// Optimización: Cargar metadatos en caché.
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		$today    = ( new DateTime() )->format( 'Y-m-d' );

		// Additional filtering: Check tasks that are not truly assigned to the specified user.
		// Filtering serialized data can be risky and unreliable due to how data is stored.
		foreach ( $post_ids as $post_id ) {
			$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );

			if ( is_array( $user_date_relations ) ) {
				foreach ( $user_date_relations as $relation ) {
					if (
						isset( $relation['user_id'], $relation['date'] ) && $relation['user_id'] == $user_id && $relation['date'] == $today
					) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Retrieves tasks with an upcoming due date within a specified date range.
	 *
	 * This function fetches tasks of type 'decker_task' that are published, have a 'duedate' meta key,
	 * and whose 'duedate' falls between the specified $from and $until dates. Additionally, it filters
	 * tasks that have a 'stack' meta value within a defined set (e.g., 'to-do' or 'in-progress').
	 *
	 * @param DateTime $from The start date of the range to filter tasks by.
	 * @param DateTime $until The end date of the range to filter tasks by.
	 * @param bool     $show_hidden_task Switch to show/not show hidden task. Default is true.
	 * @return Task[] List of Task objects that meet the specified criteria.
	 */
	public function get_upcoming_tasks_by_date( DateTime $from, DateTime $until, bool $show_hidden_task = true ): array {
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_query'  => array(
				array(
					'key'     => 'duedate',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'duedate',
					'value'   => array( $from->format( 'Y-m-d' ), $until->format( 'Y-m-d' ) ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
				array(
					'key'     => 'stack',
					'value'   => array( 'to-do', 'in-progress' ),
					'compare' => 'IN',
				),
			),
		);
		if ( ! $show_hidden_task ) {
			$args['meta_query'][] = array(
				'key'       => 'hidden',
				'value'     => '1',
				'compare'   => '!=',
			);
		}
		return $this->get_tasks( $args );
	}

	/**
	 * Retrieves tasks assigned to a specific user that have been marked between today and a specified number of previous days.
	 *
	 * The function fetches tasks assigned to the given user and filters them based on user-date relations.
	 * It returns tasks where the user has a relation date between the start date (today minus $days) and today.
	 *
	 * @param int  $user_id The ID of the user.
	 * @param int  $days Number of days to look back from today. Pass 0 to get tasks for today only.
	 * @param bool $show_hidden_task Switch to show/not show hidden task. Default is true.
	 * @return Task[] List of Task objects within the specified time range.
	 */
	public function get_user_tasks_marked_for_today_for_previous_days( int $user_id, int $days, bool $show_hidden_task = true ): array {
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids', // Only retrieve IDs for performance optimization.
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key'     => 'assigned_users',
					'value'   => $user_id,
					'compare' => 'LIKE',
				),
				array(
					'key'     => '_user_date_relations',
					'compare' => 'EXISTS',
				),
			),
		);

		// Not showing hidden task if the parameter show_hidden_task is false.

		if ( ! $show_hidden_task ) {
			$args['meta_query'][] = array(
				'key'     => 'hidden',
				'value'   => '1',
				'compare' => '!=',
			);
		}

		// Important! Here we are using direct post_id retrieval for optimization.
		$post_ids   = get_posts( $args );

		// Optimización: Cargar metadatos en caché.
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}

		$tasks      = array();
		$today      = ( new DateTime() )->setTime( 23, 59 );
		$start_date = ( new DateTime() )->setTime( 0, 0 )->modify( "-$days days" );

		// Additional filtering: Remove tasks that are not truly assigned to the specified user.
		// Filtering serialized data can be risky and unreliable due to how data is stored.
		foreach ( $post_ids as $post_id ) {

			// Retrieve the assigned users for the task.
			$assigned_users = get_post_meta( $post_id, 'assigned_users', true );

			if ( is_array( $assigned_users ) && in_array( $user_id, $assigned_users ) ) {

				$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );

				if ( is_array( $user_date_relations ) ) {
					foreach ( $user_date_relations as $relation ) {
						if ( isset( $relation['user_id'], $relation['date'] ) && $relation['user_id'] == $user_id ) {
							$relation_date = DateTime::createFromFormat( 'Y-m-d', $relation['date'] );
							if (
								$relation_date && $relation_date >= $start_date && $relation_date <= $today
							) {
								$tasks[] = new Task( $post_id );
								break; // No need to check more dates for this task.
							}
						}
					}
				}
			}
		}

		return $tasks;
	}
}
