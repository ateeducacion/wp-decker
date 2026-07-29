<?php
/**
 * Stores and formats a user's Decker notifications.
 *
 * Notifications live in user meta twice: the capped "all notifications" list
 * the panel shows, and a "pending" list the next Heartbeat cycle drains. This
 * class owns both, plus the mapping from a stored notification to the payload
 * the JavaScript client consumes.
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Notification_Store
 */
class Decker_Notification_Store {

	/**
	 * Maximum notifications to keep in user meta.
	 *
	 * @var int
	 */
	const MAX_NOTIFICATIONS = 15;

	/**
	 * Add a notification to a user's "all notifications" meta,
	 * and also store it in "pending" so Heartbeat can push it once.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $notification Notification data.
	 */
	public function add_notification_to_user( $user_id, $notification ) {
		if ( ! $user_id ) {
			return;
		}

		$notification['notification_id'] = $this->get_notification_id( $notification );

		// Save to "all notifications".
		$all_notifications = get_user_meta( $user_id, 'decker_all_notifications', true );
		if ( ! is_array( $all_notifications ) ) {
			$all_notifications = array();
		}

		// Append this new item at the end so we can limit by self::MAX_NOTIFICATIONS.
		$all_notifications[] = $notification;

		// Prune if over limit.
		if ( count( $all_notifications ) > self::MAX_NOTIFICATIONS ) {
			// Remove the oldest.
			array_shift( $all_notifications );
		}
		update_user_meta( $user_id, 'decker_all_notifications', $all_notifications );

		// Also store it in pending so it is sent via Heartbeat next cycle.
		$pending = get_user_meta( $user_id, 'decker_pending_notifications', true );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}

		$pending[] = $notification;
		update_user_meta( $user_id, 'decker_pending_notifications', $pending );
	}

	/**
	 * Removes a specific notification from user meta.
	 *
	 * @param int   $user_id User ID.
	 * @param array $notification Notification data to remove.
	 */
	public function remove_notification_from_user( $user_id, $notification ) {
		if ( ! $user_id ) {
			return;
		}

		$all_notifications = get_user_meta( $user_id, 'decker_all_notifications', true );
		if ( ! is_array( $all_notifications ) ) {
			return;
		}

		$notification_id = isset( $notification['notification_id'] )
			? sanitize_text_field( $notification['notification_id'] )
			: '';

		// Remove the notification matching type and task_id (if applicable).
		$filtered = array_filter(
			$all_notifications,
			function ( $stored_notification ) use ( $notification, $notification_id ) {
				if ( $notification_id ) {
					return $this->get_notification_id( $stored_notification ) !== $notification_id;
				}

				return (
					$stored_notification['type'] !== $notification['type']
					|| ( isset( $stored_notification['task_id'] )
					&& $stored_notification['task_id'] !== $notification['task_id'] )
				);
			}
		);

		update_user_meta( $user_id, 'decker_all_notifications', array_values( $filtered ) );
	}

	/**
	 * Retrieves a notifications meta value, normalized to an array.
	 *
	 * @param int    $user_id  The user ID.
	 * @param string $meta_key The user meta key to read.
	 * @return array The stored notifications, or an empty array when none are stored.
	 */
	public function get_notifications_meta( $user_id, $meta_key ) {
		$notifications = get_user_meta( $user_id, $meta_key, true );
		if ( ! is_array( $notifications ) ) {
			return array();
		}

		return $notifications;
	}

	/**
	 * Maps a stored notification to the structure consumed by the JS client.
	 *
	 * @param array  $notification  Notification data.
	 * @param string $default_title Title fallback when the notification has none.
	 * @return array The formatted notification payload.
	 */
	public function format_notification_for_client( $notification, $default_title ) {
		return array(
			'notificationId' => $this->get_notification_id( $notification ),
			'url'       => isset( $notification['url'] ) ? $notification['url'] : '#',
			'taskId'    => isset( $notification['task_id'] ) ? $notification['task_id'] : 0,
			'iconColor' => $this->get_icon_color_by_type( $notification['type'] ),
			'iconClass' => $this->get_icon_class_by_type( $notification['type'] ),
			'title'     => isset( $notification['title'] ) ? $notification['title'] : $default_title,
			'action'    => isset( $notification['action'] ) ? $notification['action'] : '',
			'time'      => isset( $notification['time'] ) ? $notification['time'] : '',
		);
	}

	/**
	 * Maps a stored notification to the Heartbeat payload structure.
	 *
	 * Adds the heartbeat-only 'type' key and uses the heartbeat default title.
	 *
	 * @param array $notification Notification data.
	 * @return array The formatted Heartbeat notification payload.
	 */
	public function format_notification_for_heartbeat( $notification ) {
		$formatted         = $this->format_notification_for_client( $notification, 'New Notification' );
		$formatted['type'] = isset( $notification['type'] ) ? $notification['type'] : '';

		return $formatted;
	}

	/**
	 * Gets a stable identifier for a notification.
	 *
	 * @param array $notification Notification data.
	 * @return string
	 */
	private function get_notification_id( $notification ) {
		if ( ! empty( $notification['notification_id'] ) ) {
			return sanitize_text_field( $notification['notification_id'] );
		}

		$identifier_data = array(
			'type'    => isset( $notification['type'] ) ? (string) $notification['type'] : '',
			'task_id' => isset( $notification['task_id'] ) ? (string) $notification['task_id'] : '',
			'title'   => isset( $notification['title'] ) ? (string) $notification['title'] : '',
			'action'  => isset( $notification['action'] ) ? (string) $notification['action'] : '',
			'time'    => isset( $notification['time'] ) ? (string) $notification['time'] : '',
			'url'     => isset( $notification['url'] ) ? (string) $notification['url'] : '',
		);

		return md5( wp_json_encode( $identifier_data ) );
	}

	/**
	 * Maps notification type to icon color.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	private function get_icon_color_by_type( $type ) {
		switch ( $type ) {
			case 'task_created':
				return 'primary';
			case 'task_assigned':
				return 'warning';
			case 'task_completed':
				return 'success';
			case 'task_comment':
				return 'info';
			default:
				return 'primary';
		}
	}

	/**
	 * Maps notification type to icon class.
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	private function get_icon_class_by_type( $type ) {
		switch ( $type ) {
			case 'task_created':
				return 'ri-add-line';
			case 'task_assigned':
				return 'ri-user-add-line';
			case 'task_completed':
				return 'ri-checkbox-circle-line';
			case 'task_comment':
				return 'ri-message-3-line';
			default:
				return 'ri-information-line';
		}
	}
}
