<?php
/**
 * File class-decker-task-today-manager
 *
 * User-specific "For today" relation service for Decker tasks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Today_Manager
 *
 * Central owner of the per-user, per-date "For today" relations stored in the
 * `_user_date_relations` post meta. It preserves relations belonging to other
 * users and other dates, is idempotent, and writes with a bounded optimistic
 * compare-and-swap so concurrent updates do not silently lose data.
 *
 * The relation is personal, so it is intentionally independent from the shared
 * task edit lock: callers must not gate it on lock ownership.
 */
class Decker_Task_Today_Manager {

	/**
	 * Meta key that stores the user/date relations.
	 *
	 * @var string
	 */
	const META_KEY = '_user_date_relations';

	/**
	 * Maximum number of compare-and-swap attempts before reporting a conflict.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Determine whether a task is marked for today for a user.
	 *
	 * @param int $task_id The task post ID.
	 * @param int $user_id The user ID.
	 * @return bool True when the current-day relation exists.
	 */
	public function is_marked_for_today( int $task_id, int $user_id ): bool {
		$today = $this->today();

		foreach ( $this->read_relations( $task_id ) as $relation ) {
			if ( $this->matches( $relation, $user_id, $today ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set the current-day relation to the requested state.
	 *
	 * @param int  $task_id The task post ID.
	 * @param int  $user_id The user ID.
	 * @param bool $marked  Whether the task should be marked for today.
	 * @return array|WP_Error Normalized result, or WP_Error on unresolved contention.
	 */
	public function set_today_state( int $task_id, int $user_id, bool $marked ) {
		$today = $this->today();

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$raw     = get_post_meta( $task_id, self::META_KEY, true );
			$current = is_array( $raw ) ? $raw : array();
			$has     = $this->contains( $current, $user_id, $today );

			// Idempotent: nothing to do when the state already matches.
			if ( $marked === $has ) {
				return $this->result( $task_id, $user_id, $marked, false, $today );
			}

			$new = $this->apply( $current, $user_id, $today, $marked );

			// Compare-and-swap: only write when the stored value still matches
			// what we read, so a concurrent update is not overwritten.
			$written = update_post_meta( $task_id, self::META_KEY, $new, $raw );
			if ( $written ) {
				do_action( 'decker_task_today_state_changed', $task_id, $user_id, $marked, $today );
				return $this->result( $task_id, $user_id, $marked, true, $today );
			}
		}

		return new WP_Error(
			'decker_today_conflict',
			__( 'The task could not be updated due to a concurrent change. Please try again.', 'decker' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Mark a task for today for a user, idempotently.
	 *
	 * @param int $task_id The task post ID.
	 * @param int $user_id The user ID.
	 * @return array|WP_Error Normalized result, or WP_Error on failure.
	 */
	public function mark_for_today( int $task_id, int $user_id ) {
		return $this->set_today_state( $task_id, $user_id, true );
	}

	/**
	 * Remove today's relation for a user, idempotently.
	 *
	 * @param int $task_id The task post ID.
	 * @param int $user_id The user ID.
	 * @return array|WP_Error Normalized result, or WP_Error on failure.
	 */
	public function unmark_for_today( int $task_id, int $user_id ) {
		return $this->set_today_state( $task_id, $user_id, false );
	}

	/**
	 * Compute the relation list for the requested state.
	 *
	 * Removes any existing entry for this user and date (also de-duplicating and
	 * re-indexing) and appends a fresh one when marking.
	 *
	 * @param array  $relations The current relations.
	 * @param int    $user_id   The user ID.
	 * @param string $date      The Y-m-d date.
	 * @param bool   $marked    Whether to add the relation.
	 * @return array The new relations list.
	 */
	private function apply( array $relations, int $user_id, string $date, bool $marked ): array {
		$filtered = array();

		foreach ( $relations as $relation ) {
			if ( ! $this->matches( $relation, $user_id, $date ) ) {
				$filtered[] = $relation;
			}
		}

		if ( $marked ) {
			$filtered[] = array(
				'user_id' => $user_id,
				'date'    => $date,
			);
		}

		return array_values( $filtered );
	}

	/**
	 * Whether the relations contain an entry for this user and date.
	 *
	 * @param array  $relations The relations.
	 * @param int    $user_id   The user ID.
	 * @param string $date      The Y-m-d date.
	 * @return bool True when a matching relation exists.
	 */
	private function contains( array $relations, int $user_id, string $date ): bool {
		foreach ( $relations as $relation ) {
			if ( $this->matches( $relation, $user_id, $date ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a single relation matches a user and date.
	 *
	 * @param mixed  $relation The relation entry.
	 * @param int    $user_id  The user ID.
	 * @param string $date     The Y-m-d date.
	 * @return bool True when the relation matches.
	 */
	private function matches( $relation, int $user_id, string $date ): bool {
		return is_array( $relation )
			&& isset( $relation['user_id'], $relation['date'] )
			&& (int) $relation['user_id'] === $user_id
			&& $relation['date'] === $date;
	}

	/**
	 * Read the normalized relations array for a task.
	 *
	 * @param int $task_id The task post ID.
	 * @return array The relations.
	 */
	private function read_relations( int $task_id ): array {
		$raw = get_post_meta( $task_id, self::META_KEY, true );

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Build the normalized result array.
	 *
	 * @param int    $task_id The task post ID.
	 * @param int    $user_id The user ID.
	 * @param bool   $marked  The resulting marked state.
	 * @param bool   $changed Whether the state actually changed.
	 * @param string $date    The Y-m-d date.
	 * @return array The normalized result.
	 */
	private function result( int $task_id, int $user_id, bool $marked, bool $changed, string $date ): array {
		return array(
			'task_id' => $task_id,
			'user_id' => $user_id,
			'date'    => $date,
			'marked'  => $marked,
			'changed' => $changed,
		);
	}

	/**
	 * Today's date in the storage format.
	 *
	 * @return string The Y-m-d date.
	 */
	private function today(): string {
		return ( new DateTime() )->format( 'Y-m-d' );
	}
}
