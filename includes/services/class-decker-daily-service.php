<?php
/**
 * Daily View Service for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes/services
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Service layer for Daily View operations.
 *
 * Provides aggregation of users/tasks per day, manages journal notes for a
 * board and date, and handles transient caching.
 *
 * @since 1.0.0
 */
class Decker_Daily_Service {

	const CACHE_EXPIRATION = 10 * MINUTE_IN_SECONDS;

	/**
	 * Wires cache invalidation on task updates.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'decker_task_updated', array( $this, 'invalidate_daily_summary_cache_for_task' ) );
	}

	/**
	 * Gets a per-day summary for a board.
	 *
	 * The summary includes arrays of user IDs and task IDs with activity on the
	 * given date, plus any saved journal notes. Results are cached in a transient
	 * for a short period to reduce queries.
	 *
	 * @since 1.0.0
	 * @param int    $board_term_id Board term ID.
	 * @param string $ymd           Date in Y-m-d format.
	 * @return array {
	 *     @type int[]  $users User IDs with activity that day.
	 *     @type int[]  $tasks Task IDs with activity that day.
	 *     @type string $notes Journal notes HTML.
	 * }
	 */
	public static function get_daily_summary( int $board_term_id, string $ymd ): array {
		$cache_key = "decker_daily_{$board_term_id}_{$ymd}";
		$cached_data = get_transient( $cache_key );

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$tasks_in_board = get_posts(
			array(
				'post_type'      => 'decker_task',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_term_id,
					),
				),
			)
		);

		$users_of_the_day = array();
		$tasks_of_the_day = array();

		foreach ( $tasks_in_board as $task_id ) {
			$relations = get_post_meta( $task_id, '_user_date_relations', true );
			if ( is_array( $relations ) ) {
				foreach ( $relations as $relation ) {
					if ( isset( $relation['date'] ) && $relation['date'] === $ymd ) {
						if ( ! in_array( $relation['user_id'], $users_of_the_day, true ) ) {
							$users_of_the_day[] = (int) $relation['user_id'];
						}
						if ( ! in_array( $task_id, $tasks_of_the_day, true ) ) {
							$tasks_of_the_day[] = (int) $task_id;
						}
					}
				}
			}
		}

		$journal_post = self::get_journal_post_for_date( $board_term_id, $ymd );
		$notes = $journal_post ? $journal_post->post_content : '';

		$summary = array(
			'users' => $users_of_the_day,
			'tasks' => $tasks_of_the_day,
			'notes' => $notes,
		);

		set_transient( $cache_key, $summary, self::CACHE_EXPIRATION );

		return $summary;
	}

	/**
	 * Creates or updates journal notes for a date.
	 *
	 * Requires that there is at least one task with activity on the given date
	 * for the board. Returns the journal post ID on success.
	 *
	 * @since 1.0.0
	 * @param int    $board_term_id Board term ID.
	 * @param string $ymd           Date in Y-m-d format.
	 * @param string $html_content  Notes HTML content.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function upsert_notes( int $board_term_id, string $ymd, string $html_content ): int|WP_Error {
		$summary = self::get_daily_summary( $board_term_id, $ymd );
		if ( empty( $summary['tasks'] ) ) {
			return new WP_Error( 'no_tasks_for_day', __( 'Cannot save notes for a day with no tasks.', 'decker' ) );
		}

		$journal_post = self::get_journal_post( $board_term_id, $ymd );

		$post_data = array(
			'post_content' => wp_kses_post( $html_content ),
			'post_title'   => sprintf( 'Journal - %s - %s', get_term( $board_term_id )->name, $ymd ),
			'post_status'  => 'publish',
			'post_type'    => 'decker_journal',
		);

		if ( $journal_post ) {
			$post_data['ID'] = $journal_post->ID;
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		wp_set_post_terms( $post_id, $board_term_id, 'decker_board' );
		update_post_meta( $post_id, 'journal_date', $ymd );

		// Invalidate cache.
		delete_transient( "decker_daily_{$board_term_id}_{$ymd}" );

		return $post_id;
	}

	/**
	 * Invalidates cached daily summaries related to a task.
	 *
	 * When a task changes, all dates in its user/date relations for the first
	 * associated board have their daily summary cache cleared.
	 *
	 * @since 1.0.0
	 * @param int $task_id Task post ID.
	 * @return void
	 */
	public function invalidate_daily_summary_cache_for_task( $task_id ) {
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$boards = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );

		if ( is_array( $relations ) && ! empty( $boards ) ) {
			$board_id = $boards[0];
			$dates_to_invalidate = array_unique( wp_list_pluck( $relations, 'date' ) );
			foreach ( $dates_to_invalidate as $date ) {
				delete_transient( "decker_daily_{$board_id}_{$date}" );
			}
		}
	}

	/**
	 * Retrieves the journal post for a board on a given date.
	 *
	 * @since 1.0.0
	 * @param int    $board_term_id Board term ID.
	 * @param string $ymd           Date in Y-m-d format.
	 * @return WP_Post|null Journal post if found, otherwise null.
	 */
	public static function get_journal_post_for_date( int $board_term_id, string $ymd ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'decker_journal',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => 'journal_date',
				'meta_value'     => $ymd,
				'tax_query'      => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_term_id,
					),
				),
			)
		);
		return $query->have_posts() ? $query->posts[0] : null;
	}
}
