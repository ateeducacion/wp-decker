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
	 * @param int       $user_id The ID of the user.
	 * @param int       $days Number of days to look back from today. Pass 0 to get tasks for today only.
	 * @param bool      $show_hidden_task Switch to show/not show hidden task. Default is true.
	 * @param ?DateTime $specific_date Optional specific date to load tasks from. If provided, $days is ignored.
	 * @return Task[] List of Task objects within the specified time range.
	 */
	public function get_user_tasks_marked_for_today_for_previous_days( int $user_id, int $days, bool $show_hidden_task = true, ?DateTime $specific_date = null ): array {
		// Get task post IDs that match the criteria
		$post_ids = $this->get_task_post_ids_for_user( $user_id, $show_hidden_task );
		
		if ( empty( $post_ids ) ) {
			return array();
		}
		
		// Calculate date range
		$date_range = $this->calculate_date_range( $days, $specific_date );
		
		// Filter tasks by date relations
		return $this->filter_tasks_by_date_relations( $post_ids, $user_id, $date_range['start'], $date_range['end'] );
	}
	
	/**
	 * Gets task post IDs for a specific user.
	 *
	 * @param int  $user_id The ID of the user.
	 * @param bool $show_hidden_task Whether to show hidden tasks.
	 * @return array Array of post IDs.
	 */
	private function get_task_post_ids_for_user( int $user_id, bool $show_hidden_task ): array {
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
		$post_ids = get_posts( $args );

		// Optimización: Cargar metadatos en caché.
		if ( ! empty( $post_ids ) ) {
			update_meta_cache( 'post', $post_ids );
		}
		
		return $post_ids;
	}
	
	/**
	 * Calculates the date range for task filtering.
	 *
	 * @param int       $days Number of days to look back.
	 * @param ?DateTime $specific_date Optional specific date.
	 * @return array Array with start and end dates.
	 */
	private function calculate_date_range( int $days, ?DateTime $specific_date = null ): array {
		$today = ( new DateTime() )->setTime( 23, 59 );
		
		if ( $specific_date ) {
			// If a specific date is provided, use it as both start and end date.
			$start_date = clone $specific_date;
			$start_date->setTime( 0, 0 );
			$end_date = clone $specific_date;
			$end_date->setTime( 23, 59 );
		} else {
			// Otherwise use the days parameter.
			$start_date = ( new DateTime() )->setTime( 0, 0 )->modify( "-$days days" );
			$end_date = $today;
		}
		
		return array(
			'start' => $start_date,
			'end'   => $end_date,
		);
	}
	
	/**
	 * Filters tasks by date relations.
	 *
	 * @param array    $post_ids   Array of post IDs.
	 * @param int      $user_id    The ID of the user.
	 * @param DateTime $start_date Start date for filtering.
	 * @param DateTime $end_date   End date for filtering.
	 * @return array Array of Task objects.
	 */
	private function filter_tasks_by_date_relations( array $post_ids, int $user_id, DateTime $start_date, DateTime $end_date ): array {
		$tasks = array();
		
		foreach ( $post_ids as $post_id ) {
			// Retrieve the assigned users for the task.
			$assigned_users = get_post_meta( $post_id, 'assigned_users', true );
			
			if ( ! is_array( $assigned_users ) || ! in_array( $user_id, $assigned_users ) ) {
				continue;
			}
			
			$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );
			
			if ( ! is_array( $user_date_relations ) ) {
				continue;
			}
			
			if ( $this->has_relation_in_date_range( $user_date_relations, $user_id, $start_date, $end_date ) ) {
				$tasks[] = new Task( $post_id );
			}
		}
		
		return $tasks;
	}
	
	/**
	 * Checks if a user has a relation within a date range.
	 *
	 * @param array    $relations  Array of user-date relations.
	 * @param int      $user_id    The ID of the user.
	 * @param DateTime $start_date Start date for filtering.
	 * @param DateTime $end_date   End date for filtering.
	 * @return bool Whether the user has a relation in the date range.
	 */
	private function has_relation_in_date_range( array $relations, int $user_id, DateTime $start_date, DateTime $end_date ): bool {
		foreach ( $relations as $relation ) {
			if ( ! isset( $relation['user_id'], $relation['date'] ) || $relation['user_id'] != $user_id ) {
				continue;
			}
			
			$relation_date = DateTime::createFromFormat( 'Y-m-d', $relation['date'] );
			
			if ( $relation_date && $relation_date >= $start_date && $relation_date <= $end_date ) {
				return true;
			}
		}
		
		return false;
	}

	/**
	 * Finds the latest date when a user marked tasks.
	 *
	 * @param int $user_id The ID of the user.
	 * @param int $max_days_back Maximum number of days to look back (default: 7).
	 * @return ?DateTime The latest date found or null if no dates found.
	 */
	public function get_latest_user_task_date( int $user_id, int $max_days_back = 7 ): ?DateTime {
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
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

		$post_ids = get_posts( $args );

		if ( empty( $post_ids ) ) {
			return null;
		}

		update_meta_cache( 'post', $post_ids );

		$latest_date = null;
		$today = new DateTime();
		$min_date = ( clone $today )->modify( "-$max_days_back days" );

		foreach ( $post_ids as $post_id ) {
			$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );

			if ( is_array( $user_date_relations ) ) {
				foreach ( $user_date_relations as $relation ) {
					if ( isset( $relation['user_id'], $relation['date'] ) && $relation['user_id'] == $user_id ) {
						$relation_date = DateTime::createFromFormat( 'Y-m-d', $relation['date'] );

						// Skip dates that are today or in the future, and limit to max_days_back.
						if ( $relation_date && $relation_date < $today && $relation_date >= $min_date ) {
							if ( ! $latest_date || $relation_date > $latest_date ) {
								$latest_date = $relation_date;
							}
						}
					}
				}
			}
		}

		return $latest_date;
	}

	/**
	 * Gets available dates when a user marked tasks in the past.
	 *
	 * @param int $user_id The ID of the user.
	 * @param int $max_days_back Maximum number of days to look back (default: 7).
	 * @return array Array of dates in Y-m-d format.
	 */
	public function get_user_task_dates( int $user_id, int $max_days_back = 7 ): array {
		$args = array(
			'post_type'   => 'decker_task',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array(
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

		$post_ids = get_posts( $args );

		if ( empty( $post_ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $post_ids );

		$today = new DateTime();
		$min_date = ( clone $today )->modify( "-$max_days_back days" );
		$today_str = $today->format( 'Y-m-d' );
		
		$dates = $this->extract_valid_dates_from_posts( $post_ids, $user_id, $today, $min_date, $today_str );

		// Sort dates in descending order (newest first).
		usort(
			$dates,
			function ( $a, $b ) {
				return strcmp( $b, $a );
			}
		);

		return $dates;
	}
	
	/**
	 * Extract valid dates from post metadata.
	 *
	 * @param array    $post_ids Array of post IDs.
	 * @param int      $user_id The ID of the user.
	 * @param DateTime $today Today's date.
	 * @param DateTime $min_date Minimum date to consider.
	 * @param string   $today_str Today's date as string.
	 * @return array Array of valid dates in Y-m-d format.
	 */
	private function extract_valid_dates_from_posts( array $post_ids, int $user_id, DateTime $today, DateTime $min_date, string $today_str ): array {
		$dates = array();
		
		foreach ( $post_ids as $post_id ) {
			$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );
			
			if ( ! is_array( $user_date_relations ) ) {
				continue;
			}
			
			foreach ( $user_date_relations as $relation ) {
				if ( ! isset( $relation['user_id'], $relation['date'] ) || $relation['user_id'] != $user_id ) {
					continue;
				}
				
				$date_str = $relation['date'];
				$relation_date = DateTime::createFromFormat( 'Y-m-d', $date_str );
				
				// Skip dates that are today or in the future, and limit to max_days_back.
				if ( ! $relation_date || $date_str == $today_str || $relation_date >= $today || $relation_date < $min_date ) {
					continue;
				}
				
				if ( ! in_array( $date_str, $dates ) ) {
					$dates[] = $date_str;
				}
			}
		}
		
		return $dates;
	}
}
