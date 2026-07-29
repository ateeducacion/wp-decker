<?php
/**
 * Record-level helpers for the per-user "for today" date relations.
 *
 * A task stores its _user_date_relations meta as a list of user/date pairs.
 * These helpers judge single records and fold over relation lists; they hold
 * no query logic and touch no database themselves beyond what a caller hands
 * them.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Date_Relations
 */
class Decker_Task_Date_Relations {

	/**
	 * Checks if a user has a relation within a date range.
	 *
	 * @param array    $relations  Array of user-date relations.
	 * @param int      $user_id    The ID of the user.
	 * @param DateTime $start_date Start date for filtering.
	 * @param DateTime $end_date   End date for filtering.
	 * @return bool Whether the user has a relation in the date range.
	 */
	public function has_relation_in_date_range( array $relations, int $user_id, DateTime $start_date, DateTime $end_date ): bool {
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
	 * Updates the latest date based on user-date relations.
	 *
	 * @param array     $relations Array of user-date relations.
	 * @param int       $user_id The ID of the user.
	 * @param DateTime  $today Today's date.
	 * @param DateTime  $min_date Minimum date to consider.
	 * @param ?DateTime $latest_date Current latest date.
	 * @return ?DateTime Updated latest date.
	 */
	public function update_latest_date( array $relations, int $user_id, DateTime $today, DateTime $min_date, ?DateTime $latest_date ): ?DateTime {
		foreach ( $relations as $relation ) {
			if ( ! isset( $relation['user_id'], $relation['date'] ) || $relation['user_id'] != $user_id ) {
				continue;
			}

			$relation_date = DateTime::createFromFormat( 'Y-m-d', $relation['date'] );

			// Skip dates that are today or in the future, and limit to max_days_back.
			if ( ! $relation_date || $relation_date >= $today || $relation_date < $min_date ) {
				continue;
			}

			if ( ! $latest_date || $relation_date > $latest_date ) {
				$latest_date = $relation_date;
			}
		}

		return $latest_date;
	}

	/**
	 * Process user date relations and collect valid dates.
	 *
	 * @param array    $relations Array of user-date relations.
	 * @param int      $user_id The ID of the user.
	 * @param DateTime $today Today's date.
	 * @param DateTime $min_date Minimum date to consider.
	 * @param string   $today_str Today's date as string.
	 * @param array    $dates Array to collect valid dates.
	 */
	public function process_user_date_relations( array $relations, int $user_id, DateTime $today, DateTime $min_date, string $today_str, array &$dates ): void {
		foreach ( $relations as $relation ) {
			if ( ! $this->is_valid_user_relation( $relation, $user_id ) ) {
				continue;
			}

			$date_str = $relation['date'];

			if ( $this->is_valid_date_for_collection( $date_str, $today, $min_date, $today_str ) && ! in_array( $date_str, $dates ) ) {
				$dates[] = $date_str;
			}
		}
	}

	/**
	 * Check if a relation is valid for the specified user.
	 *
	 * @param array $relation The relation to check.
	 * @param int   $user_id The user ID to check against.
	 * @return bool Whether the relation is valid.
	 */
	public function is_valid_user_relation( array $relation, int $user_id ): bool {
		return isset( $relation['user_id'], $relation['date'] ) && $relation['user_id'] == $user_id;
	}

	/**
	 * Check if a date is valid for collection.
	 *
	 * @param string   $date_str The date string to check.
	 * @param DateTime $today Today's date.
	 * @param DateTime $min_date Minimum date to consider.
	 * @param string   $today_str Today's date as string.
	 * @return bool Whether the date is valid for collection.
	 */
	public function is_valid_date_for_collection( string $date_str, DateTime $today, DateTime $min_date, string $today_str ): bool {
		$relation_date = DateTime::createFromFormat( 'Y-m-d', $date_str );

		// Skip dates that are today or in the future, and limit to max_days_back.
		if ( ! $relation_date || $date_str == $today_str || $relation_date >= $today || $relation_date < $min_date ) {
			return false;
		}

		return true;
	}
}
