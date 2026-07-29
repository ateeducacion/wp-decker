<?php
/**
 * Queries the tasks a user marked "for today".
 *
 * Owns the date-relation side of the task queries: which tasks carry a
 * relation for a user today or in a recent window, and which dates a user
 * has marked at all. Record-level judgments are delegated to
 * Decker_Task_Date_Relations; TaskManager keeps thin public delegators so
 * its callers are unaffected.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Today_Query
 */
class Decker_Task_Today_Query {

	/**
	 * Record-level relation helpers.
	 *
	 * @var Decker_Task_Date_Relations
	 */
	private $relations;

	/**
	 * Take the relation helpers.
	 *
	 * @param Decker_Task_Date_Relations $relations Record-level relation helpers.
	 */
	public function __construct( Decker_Task_Date_Relations $relations ) {
		$this->relations = $relations;
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

		   // Optimization: Load metadata into cache.
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
		// Get task post IDs that match the criteria.
		$post_ids = $this->get_task_post_ids_for_user( $user_id, $show_hidden_task );

		if ( empty( $post_ids ) ) {
			return array();
		}

		// Calculate date range.
		$date_range = $this->calculate_date_range( $days, $specific_date );

		// Filter tasks by date relations.
		return $this->filter_tasks_by_date_relations( $post_ids, $user_id, $date_range['start'], $date_range['end'] );
	}

	/**
	 * Finds the latest date when a user marked tasks.
	 *
	 * @param int $user_id The ID of the user.
	 * @param int $max_days_back Maximum number of days to look back (default: 7).
	 * @return ?DateTime The latest date found or null if no dates found.
	 */
	public function get_latest_user_task_date( int $user_id, int $max_days_back = 7 ): ?DateTime {
		// Get task post IDs that match the criteria.
		$post_ids = $this->get_task_post_ids_for_user( $user_id, true );

		if ( empty( $post_ids ) ) {
			return null;
		}

		// Get date constraints.
		$date_constraints = $this->get_date_constraints( $max_days_back );

		// Find the latest date.
		return $this->find_latest_date_in_relations( $post_ids, $user_id, $date_constraints['today'], $date_constraints['min_date'] );
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

		   // Optimization: Load metadata into cache.
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

			if ( $this->relations->has_relation_in_date_range( $user_date_relations, $user_id, $start_date, $end_date ) ) {
				$tasks[] = new Task( $post_id );
			}
		}

		return $tasks;
	}

	/**
	 * Gets date constraints for filtering.
	 *
	 * @param int $max_days_back Maximum number of days to look back.
	 * @return array Array with today and min_date.
	 */
	private function get_date_constraints( int $max_days_back ): array {
		$today = new DateTime();
		$min_date = ( clone $today )->modify( "-$max_days_back days" );

		return array(
			'today' => $today,
			'min_date' => $min_date,
		);
	}

	/**
	 * Finds the latest date in user-date relations.
	 *
	 * @param array    $post_ids Array of post IDs.
	 * @param int      $user_id The ID of the user.
	 * @param DateTime $today Today's date.
	 * @param DateTime $min_date Minimum date to consider.
	 * @return ?DateTime The latest date found or null if no dates found.
	 */
	private function find_latest_date_in_relations( array $post_ids, int $user_id, DateTime $today, DateTime $min_date ): ?DateTime {
		$latest_date = null;

		foreach ( $post_ids as $post_id ) {
			$user_date_relations = get_post_meta( $post_id, '_user_date_relations', true );

			if ( ! is_array( $user_date_relations ) ) {
				continue;
			}

			$latest_date = $this->relations->update_latest_date( $user_date_relations, $user_id, $today, $min_date, $latest_date );
		}

		return $latest_date;
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

			$this->relations->process_user_date_relations( $user_date_relations, $user_id, $today, $min_date, $today_str, $dates );
		}

		return $dates;
	}
}
