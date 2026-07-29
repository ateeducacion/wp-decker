<?php
/**
 * People resolution for tasks.
 *
 * Owns the "who is on this task" reads: the ordered people list used by the
 * avatar group (responsible first, then assignees without duplicates), the
 * display-name projection of that list, and the historical
 * `_user_date_relations` records resolved to user objects.
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_People_View
 */
class Decker_Task_People_View {

	/**
	 * The task being inspected.
	 *
	 * @var Task
	 */
	private $task;

	/**
	 * @param Task $task The task being inspected.
	 */
	public function __construct( Task $task ) {
		$this->task = $task;
	}

	/**
	 * Gets the users displayed in the people avatar group.
	 *
	 * The responsible user is returned first, followed by the remaining
	 * assigned users without duplicates.
	 *
	 * @return WP_User[] Ordered list of users to display.
	 */
	public function get_people_users(): array {
		$people            = array();
		$responsable_user  = $this->task->responsable instanceof WP_User
			? $this->task->responsable
			: null;

		if ( $responsable_user instanceof WP_User ) {
			$people[] = $responsable_user;
		}

		foreach ( $this->task->assigned_users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			if ( $responsable_user instanceof WP_User && $responsable_user->ID === $user->ID ) {
				continue;
			}

			$people[] = $user;
		}

		return $people;
	}

	/**
	 * Gets the display names for the people avatar group.
	 *
	 * @return string[] List of user display names.
	 */
	public function get_people_names(): array {
		$names = array();

		foreach ( $this->get_people_users() as $user ) {
			$names[] = $user->display_name;
		}

		return $names;
	}

	/**
	 * Retrieves a historical record of users and their assigned dates as user objects.
	 *
	 * @return array An array of historical records with user objects and dates.
	 */
	public function get_user_history_with_objects(): array {
		$history = array();

		if ( isset( $this->task->meta['_user_date_relations'][0] ) ) {
			$user_date_relations = maybe_unserialize( $this->task->meta['_user_date_relations'][0] );

			if ( $user_date_relations && is_array( $user_date_relations ) ) {
				foreach ( $user_date_relations as $relation ) {
					if ( isset( $relation['user_id'], $relation['date'] ) ) {
						// Retrieve WordPress user object.
						$user = get_userdata( $relation['user_id'] );
						if ( $user ) {
							$history[] = array(
								'user' => $user,
								'date' => $relation['date'],
							);
						}
					}
				}
			}
		}

		return $history;
	}
}
