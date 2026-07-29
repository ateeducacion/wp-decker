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

			// Load all metadata into the cache at once.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		if ( ! empty( $post_ids ) ) {
				   update_meta_cache( 'post', $post_ids ); // One query for all metadata.
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
		);

		// Only apply additional filters for published tasks.
		if ( 'publish' === $status ) {
			$args['meta_key'] = 'max_priority'; // Define field to use in order.
			$args['meta_type'] = 'BOOL';
			$args['orderby'] = array(
				'max_priority' => 'DESC',
			);
			$args['meta_query'] = array(
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
			);
		}

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
	 * Retrieves task counts grouped by board slug and stack.
	 *
	 * Hidden tasks are excluded to match the existing active task counter.
	 *
	 * @param string[] $stacks The stacks to count.
	 * @return array<string, array<string, int>> Task counts keyed by board slug and stack.
	 */
	public function get_board_task_counts_by_stack( array $stacks ): array {
		global $wpdb;

		$sanitized_stacks = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $stacks )
				)
			)
		);

		if ( empty( $sanitized_stacks ) ) {
			return array();
		}

		// Build the placeholder list for prepare(); the count only determines
		// how many prepared statement `%s` placeholders are used below.
		$placeholders = implode( ', ', array_fill( 0, count( $sanitized_stacks ), '%s' ) );

		// Join the stack meta so counts can be grouped by column, then join the
		// board taxonomy tables to group results by board slug. A left join on
		// the hidden flag lets us exclude hidden tasks while still counting
		// tasks that do not have any hidden meta stored.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder string only contains generated %s tokens; stack values are bound by $wpdb->prepare() below.
		$query        = $wpdb->prepare(
			"
			SELECT terms.slug AS board_slug, stack_meta.meta_value AS stack, COUNT(posts.ID) AS task_count
			FROM {$wpdb->posts} AS posts
			INNER JOIN {$wpdb->postmeta} AS stack_meta
				ON posts.ID = stack_meta.post_id
				AND stack_meta.meta_key = 'stack'
			INNER JOIN {$wpdb->term_relationships} AS relationships
				ON posts.ID = relationships.object_id
			INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
				ON relationships.term_taxonomy_id = taxonomy.term_taxonomy_id
				AND taxonomy.taxonomy = 'decker_board'
			INNER JOIN {$wpdb->terms} AS terms
				ON taxonomy.term_id = terms.term_id
			LEFT JOIN {$wpdb->postmeta} AS hidden_meta
				ON posts.ID = hidden_meta.post_id
				AND hidden_meta.meta_key = 'hidden'
			WHERE posts.post_type = 'decker_task'
				AND posts.post_status = 'publish'
				AND stack_meta.meta_value IN ($placeholders)
				AND ( hidden_meta.meta_id IS NULL OR hidden_meta.meta_value != '1' )
			GROUP BY terms.slug, stack_meta.meta_value
			",
			$sanitized_stacks
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic IN placeholders are safely constructed and passed through $wpdb->prepare() above.
		$counts  = array();

		foreach ( $results as $result ) {
			$board_slug = isset( $result->board_slug ) ? (string) $result->board_slug : '';
			$stack      = isset( $result->stack ) ? (string) $result->stack : '';
			$task_count = isset( $result->task_count ) ? (int) $result->task_count : 0;

			if ( '' === $board_slug || '' === $stack ) {
				continue;
			}

			if ( ! isset( $counts[ $board_slug ] ) ) {
				$counts[ $board_slug ] = array();
			}

			$counts[ $board_slug ][ $stack ] = $task_count;
		}

		return $counts;
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
	 * The "for today" query collaborator.
	 *
	 * @return Decker_Task_Today_Query
	 */
	private function today_query(): Decker_Task_Today_Query {
		return new Decker_Task_Today_Query( new Decker_Task_Date_Relations() );
	}

	/**
	 * Whether the current user marked any task for today.
	 *
	 * @return bool
	 */
	public function has_user_today_tasks(): bool {
		return $this->today_query()->has_user_today_tasks();
	}

	/**
	 * Tasks a user marked between today and a number of previous days.
	 *
	 * @param int       $user_id          The ID of the user.
	 * @param int       $days             Number of days to look back from today. Pass 0 for today only.
	 * @param bool      $show_hidden_task Switch to show/not show hidden tasks. Default true.
	 * @param ?DateTime $specific_date    Optional specific date to load tasks from; $days is then ignored.
	 * @return Task[] List of Task objects within the range.
	 */
	public function get_user_tasks_marked_for_today_for_previous_days( int $user_id, int $days, bool $show_hidden_task = true, ?DateTime $specific_date = null ): array {
		return $this->today_query()->get_user_tasks_marked_for_today_for_previous_days( $user_id, $days, $show_hidden_task, $specific_date );
	}

	/**
	 * Latest date on which a user marked tasks.
	 *
	 * @param int $user_id       The ID of the user.
	 * @param int $max_days_back Maximum number of days to look back (default 7).
	 * @return ?DateTime The latest date found, or null.
	 */
	public function get_latest_user_task_date( int $user_id, int $max_days_back = 7 ): ?DateTime {
		return $this->today_query()->get_latest_user_task_date( $user_id, $max_days_back );
	}

	/**
	 * Dates on which a user marked tasks, most recent first.
	 *
	 * @param int $user_id       The ID of the user.
	 * @param int $max_days_back Maximum number of days to look back (default 7).
	 * @return array The dates found.
	 */
	public function get_user_task_dates( int $user_id, int $max_days_back = 7 ): array {
		return $this->today_query()->get_user_task_dates( $user_id, $max_days_back );
	}
}
