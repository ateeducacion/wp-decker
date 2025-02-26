<?php
/**
 * Notification Handler for Decker plugin
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Notification_Handler
 *
 * Manages user notifications, including storing them in user meta
 * and responding to AJAX actions.
 */
class Decker_Notification_Handler {

	/**
	 * Maximum notifications to keep in user meta.
	 *
	 * @var int
	 */
	const MAX_NOTIFICATIONS = 15;

	/**
	 * The mailer instance.
	 *
	 * @var Decker_Mailer
	 */
	public $mailer;


	/**
	 * Constructor
	 */
	public function __construct() {
		$this->mailer = new Decker_Mailer();

		// Heartbeat hook.
		add_filter( 'heartbeat_received', array( $this, 'heartbeat_received' ), 10, 3 );

		add_filter( 'heartbeat_settings', array( $this, 'modify_heartbeat_settings' ), 10, 1 );

		// AJAX hooks.
		add_action( 'wp_ajax_get_decker_notifications', array( $this, 'ajax_get_decker_notifications' ) );
		add_action( 'wp_ajax_clear_decker_notifications', array( $this, 'ajax_clear_decker_notifications' ) );
		add_action( 'wp_ajax_remove_decker_notification', array( $this, 'ajax_remove_decker_notification' ) );
		add_action( 'wp_ajax_send_test_notification', array( $this, 'ajax_send_test_notification' ) );

		// Triggered when a new task is created.
		add_action( 'decker_task_created', array( $this, 'handle_task_created' ) );

		// Triggered when a user is assigned to a task.
		add_action( 'decker_user_assigned', array( $this, 'handle_user_assigned' ), 10, 2 );

		// Triggered when a task is completed.
		add_action( 'decker_task_completed', array( $this, 'handle_task_completed' ), 10, 3 );

		// Triggered when a new comment is added to a task.
		add_action( 'decker_task_comment_added', array( $this, 'handle_new_comment' ), 10, 3 );

		// Triggered when a responsable is changed.
		add_action( 'decker_task_responsable_changed', array( $this, 'handle_responsable_changed' ), 10, 3 );
	}

	/**
	 * Modifies the WordPress Heartbeat settings.
	 *
	 * Adjusts the heartbeat interval to n seconds.
	 *
	 * @param array $settings The existing Heartbeat settings.
	 * @return array Modified Heartbeat settings with a new interval.
	 */
	public function modify_heartbeat_settings( $settings ) {
		$settings['interval'] = 1; // Changed to 15 seconds.
		return $settings;
	}


	/**
	 * Checks if email notifications are enabled in the plugin settings.
	 *
	 * @return bool True if global email notifications are enabled, false otherwise.
	 */
	private function are_notifications_enabled() {
		$options = get_option( 'decker_settings', array() );
		return ( ! empty( $options['allow_email_notifications'] ) && $options['allow_email_notifications'] );
	}

	/**
	 * Retrieves user notification preferences.
	 *
	 * @param int $user_id The user ID.
	 * @return array An associative array of user preferences for various task events.
	 */
	private function get_user_preferences( $user_id ) {
		$defaults = array(
			'notify_created'   => true,
			'notify_assigned'  => true,
			'notify_completed' => true,
			'notify_comments'  => true,
		);

		$preferences = get_user_meta( $user_id, 'decker_notification_preferences', true );
		if ( ! is_array( $preferences ) ) {
			return $defaults;
		}

		return wp_parse_args( $preferences, $defaults );
	}

	/**
	 * Builds the task URL.
	 *
	 * @param int $task_id The task ID.
	 * @return string URL to the task.
	 */
	private function build_task_url( $task_id ) {
		return esc_url( home_url( "?decker_page=task&id=$task_id" ) );
	}

	/**
	 * Handles sending of newly created task notifications (for assigned users).
	 *
	 * This is triggered by the 'decker_task_created' hook.
	 *
	 * @param int $task_id The ID of the newly created task.
	 */
	public function handle_task_created( $task_id ) {
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}

		$task = get_post( $task_id );
		if ( ! $task ) {
			return;
		}

		// Get author info.
		$creator_id   = $task->post_author;
		$creator      = get_userdata( $creator_id );
		$creator_name = $creator ? $creator->display_name : __( 'Unknown user', 'decker' );

		foreach ( $assigned_users as $user_id ) {
			// Store notification in user meta for Heartbeat and UI.
			$this->add_notification_to_user(
				$user_id,
				array(
					'type'       => 'task_created',
					'task_id'    => $task_id,
					/* translators: %s is the title of the task. */
					'title'      => sprintf( __( 'New Task: %s', 'decker' ), $task->post_title ),
					'action'     => __( 'Task Created', 'decker' ),
					'time'       => gmdate( 'Y-m-d H:i:s' ),
					'url'        => esc_url( $this->build_task_url( $task_id ) ),
				)
			);

			// Check user-level preference for receiving email.
			$user_prefs = $this->get_user_preferences( $user_id );
			if ( ! $user_prefs['notify_created'] ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$task_url = esc_url( $this->build_task_url( $task_id ) );
			$subject  = sprintf( 'New Task Created: %s', $task->post_title );
			$content  = sprintf(
				/* translators: 1: Task title, 2: Task URL, 3: Creator name */
				__( 'A new task "%1$s" has been created by %3$s. <a href="%2$s">Click here to view the task</a>.', 'decker' ),
				$task->post_title,
				$task_url,
				$creator_name
			);

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
		}
	}


	/**
	 * Handles user assignment to a task.
	 *
	 * This is triggered by the 'decker_user_assigned' hook.
	 *
	 * @param int $task_id The task ID.
	 * @param int $user_id The ID of the user being assigned.
	 */
	public function handle_user_assigned( $task_id, $user_id ) {

		error_log( 'Entering in handle_user_assigned()...........' );

		if ( ! $task_id || ! $user_id ) {
			return;
		}

		// If the assigned user is the one performing the action, skip sending.
		if ( get_current_user_id() === $user_id ) {
			return;
		}

		$task = get_post( $task_id );
		if ( ! $task ) {
			return;
		}

		error_log( 'Adding notification in handle_user_assigned() for user: ' . $user_id );

		// Store notification in user meta for Heartbeat and UI.
		$this->add_notification_to_user(
			$user_id,
			array(
				'type'       => 'task_assigned',
				'task_id'    => $task_id,
				/* translators: %s is the title of the task. */
				'title'      => sprintf( __( 'Task Assigned: %s', 'decker' ), $task->post_title ),
				'action'     => __( 'You have been assigned a task', 'decker' ),
				'time'       => gmdate( 'Y-m-d H:i:s' ),
				'url'        => esc_url( $this->build_task_url( $task_id ) ),
			)
		);

		// Check global settings and user preference for email.
		if ( ! $this->are_notifications_enabled() ) {
			return;
		}

		$preferences = $this->get_user_preferences( $user_id );
		if ( ! $preferences['notify_assigned'] ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$subject = sprintf( 'New Task Assigned: %s', $task->post_title );
		$content = sprintf(
			'You have been assigned to the task "%1$s". Click here to view it: %2$s',
			$task->post_title,
			$this->build_task_url( $task_id )
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
	}


	/**
	 * Handles task completion notifications.
	 *
	 * Sends notifications and emails to assigned users when a task is completed,
	 * excluding the user who performed the action.
	 *
	 * @param int    $task_id          The ID of the completed task.
	 * @param string $target_stack     The new stack of the task (e.g., 'done').
	 * @param int    $completing_user_id The user ID who completed the task.
	 */
	public function handle_task_completed( $task_id, $target_stack, $completing_user_id ) {

		error_log( 'Entering in handle_task_completed()...........' );

		if ( ! $task_id ) {
			return;
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}
		if ( ! is_array( $assigned_users ) ) {
			$assigned_users = array();
		}

		// Skip the user who completed the task.
		$assigned_users = array_diff( $assigned_users, array( $completing_user_id ) );

		$task     = get_post( $task_id );
		$finisher = get_userdata( $completing_user_id );

		foreach ( $assigned_users as $user_id ) {

			error_log( 'Adding notification in handle_task_completed() for user: ' . $user_id );

			// Store notification in user meta for Heartbeat and UI.
			$this->add_notification_to_user(
				$user_id,
				array(
					'type'       => 'task_completed',
					'task_id'    => $task_id,
					/* translators: %s is the title of the task. */
					'title'      => sprintf( __( 'Task Completed: %s', 'decker' ), $task->post_title ),
					/* translators: %s is the name of the user who completed the task. */
					'action'     => sprintf( __( 'Completed by %s', 'decker' ), $finisher ? $finisher->display_name : __( 'Unknown user', 'decker' ) ),
					'time'       => gmdate( 'Y-m-d H:i:s' ),
					'url'        => esc_url( $this->build_task_url( $task_id ) ),
				)
			);

			// Check if email notifications are enabled and if the user allows them.
			if ( ! $this->are_notifications_enabled() ) {
				continue;
			}
			$preferences = $this->get_user_preferences( $user_id );
			if ( ! $preferences['notify_completed'] ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$subject = sprintf( 'Task Completed: %s', $task->post_title );
			$content = sprintf(
				'The task "%1$s" has been marked as completed by %2$s. Click here to view it: %3$s',
				$task->post_title,
				$finisher ? $finisher->display_name : __( 'Unknown user', 'decker' ),
				$this->build_task_url( $task_id )
			);

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
		}
	}

	/**
	 * Handles new comment notifications.
	 *
	 * This is triggered by the 'decker_task_comment_added' hook.
	 *
	 * @param int $task_id The ID of the task on which the comment is added.
	 * @param int $comment_id The comment ID.
	 * @param int $commenter_id The user ID who made the comment.
	 */
	public function handle_new_comment( $task_id, $comment_id, $commenter_id ) {
		if ( ! $task_id || ! $comment_id ) {
			return;
		}

		// Skip self-notifications.
		if ( get_current_user_id() === $commenter_id ) {
			return;
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}

		$task    = get_post( $task_id );
		$comment = get_comment( $comment_id );
		$author  = get_userdata( $commenter_id );

		foreach ( $assigned_users as $user_id ) {
			// Skip the commenter to avoid self-notifications.
			if ( $user_id === $commenter_id ) {
				continue;
			}

			// Store notification in user meta for Heartbeat and UI.
			$this->add_notification_to_user(
				$user_id,
				array(
					'type'       => 'task_comment',
					'task_id'    => $task_id,
					/* translators: %s is the title of the task. */
					'title'      => sprintf( __( 'New Comment on Task: %s', 'decker' ), $task->post_title ),
					/* translators: %s is the name of the user who commented on the task. */
					'action'     => sprintf( __( 'Comment by %s', 'decker' ), $author ? $author->display_name : __( 'Unknown user', 'decker' ) ),
					'time'       => gmdate( 'Y-m-d H:i:s' ),
					'url'        => esc_url( $this->build_task_url( $task_id ) ),
				)
			);

			// Check if email notifications are enabled and if the user allows them.
			if ( ! $this->are_notifications_enabled() ) {
				continue;
			}

			$preferences = $this->get_user_preferences( $user_id );
			if ( ! $preferences['notify_comments'] ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$subject = sprintf( 'New Comment on Task: %s', $task->post_title );
			$content = sprintf(
				'A new comment has been added to the task "%1$s" by %2$s. Click here to view it: %3$s',
				$task->post_title,
				$author ? $author->display_name : __( 'Unknown user', 'decker' ),
				$this->build_task_url( $task_id )
			);

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
		}
	}

	/**
	 * Handle notification when the task responsible changes.
	 *
	 * This function sends an email notification to the new responsible user when a task's
	 * responsible is changed. It excludes sending notification to the user performing the change.
	 *
	 * @param int $task_id         The task ID.
	 * @param int $old_responsible The previous responsible user ID.
	 * @param int $new_responsible The new responsible user ID.
	 *
	 * @return void
	 */
	public function handle_responsable_changed( $task_id, $old_responsible, $new_responsible ) {
		// Retrieve the new responsible user's data.
		$new_user = get_userdata( $new_responsible );
		if ( ! $new_user ) {
			return;
		}

		// Prepare the email subject and message.
		$task_title = get_the_title( $task_id );
		$subject    = sprintf( 'Task #%d Responsible Changed', $task_id );
		$message    = sprintf(
			"The task '%s' (ID: %d) has changed its responsible from user #%d to %s.\n\nPlease review the updated task details.",
			$task_title,
			$task_id,
			$old_responsible,
			$new_user->display_name
		);
	}

	/**
	 * Process data from the Heartbeat API and add notifications to the response.
	 *
	 * @param array       $response Response data.
	 * @param array       $data Data sent by the client.
	 * @param string|null $screen_id Screen ID or null.
	 *
	 * @return array Modified response data with decker_notifications if any.
	 */
	public function heartbeat_received( $response, $data, $screen_id ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}

		$pending = get_user_meta( $user_id, 'decker_pending_notifications', true );
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			$pending = array();
		}

		if ( ! empty( $pending ) ) {
			$response['decker_notifications'] = array();

			foreach ( $pending as $notification ) {
				// Prepare data for JS.
				$response['decker_notifications'][] = array(
					'url'       => isset( $notification['url'] ) ? $notification['url'] : '#',
					'taskId'    => isset( $notification['task_id'] ) ? $notification['task_id'] : 0,
					'iconColor' => $this->get_icon_color_by_type( $notification['type'] ),
					'iconClass' => $this->get_icon_class_by_type( $notification['type'] ),
					'title'     => isset( $notification['title'] ) ? $notification['title'] : 'New Notification',
					'action'    => isset( $notification['action'] ) ? $notification['action'] : '',
					'time'      => isset( $notification['time'] ) ? $notification['time'] : '',
					'type'      => isset( $notification['type'] ) ? $notification['type'] : '',
				);
			}

			// Clear pending after sending them once.
			delete_user_meta( $user_id, 'decker_pending_notifications' );
		}

		return $response;
	}

	/**
	 * AJAX: Return the last 15 notifications from user meta.
	 */
	public function ajax_get_decker_notifications() {
		check_ajax_referer( 'heartbeat-nonce', false, false ); // Optional, adjust if needed.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$all_notifications = get_user_meta( $user_id, 'decker_all_notifications', true );
		if ( empty( $all_notifications ) || ! is_array( $all_notifications ) ) {
			$all_notifications = array();
		}

		// Return only the last 15 (most recent first).
		$last_notifications = array_reverse( $all_notifications ); // Reverse so newest is at the front.
		$last_notifications = array_slice( $last_notifications, 0, self::MAX_NOTIFICATIONS );

		// Map them to the same structure used in JS.
		$formatted = array();
		foreach ( $last_notifications as $notification ) {
			$formatted[] = array(
				'url'       => isset( $notification['url'] ) ? $notification['url'] : '#',
				'taskId'    => isset( $notification['task_id'] ) ? $notification['task_id'] : 0,
				'iconColor' => $this->get_icon_color_by_type( $notification['type'] ),
				'iconClass' => $this->get_icon_class_by_type( $notification['type'] ),
				'title'     => isset( $notification['title'] ) ? $notification['title'] : 'Notification',
				'action'    => isset( $notification['action'] ) ? $notification['action'] : '',
				'time'      => isset( $notification['time'] ) ? $notification['time'] : '',
			);
		}

		wp_send_json_success( $formatted );
	}

	/**
	 * AJAX: Clear all notifications for current user.
	 */
	public function ajax_clear_decker_notifications() {
		check_ajax_referer( 'heartbeat-nonce', false, false ); // Optional, adjust if needed.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		delete_user_meta( $user_id, 'decker_all_notifications' );
		delete_user_meta( $user_id, 'decker_pending_notifications' );
		wp_send_json_success( 'All notifications cleared' );
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

		// Remove the notification matching type and task_id (if applicable).
		$filtered = array_filter(
			$all_notifications,
			function ( $n ) use ( $notification ) {
				return ( $n['type'] !== $notification['type'] || ( isset( $n['task_id'] ) && $n['task_id'] !== $notification['task_id'] ) );
			}
		);

		update_user_meta( $user_id, 'decker_all_notifications', array_values( $filtered ) );
	}

	/**
	 * AJAX: Remove one notification that has a matching task_id.
	 */
	public function ajax_remove_decker_notification() {
		check_ajax_referer( 'heartbeat-nonce', false, false ); // Optional, adjust if needed.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$task_id = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! $task_id && ! $type ) {
			wp_send_json_error( 'No valid identifier provided' );
		}

		$notification_to_remove = array(
			'type' => $type,
			'task_id' => $task_id,
		);
		$this->remove_notification_from_user( $user_id, $notification_to_remove );

		wp_send_json_success( 'Notification removed' );
	}

	/**
	 * AJAX: Send test notification to all users (admin only).
	 */
	public function ajax_send_test_notification() {
		check_ajax_referer( 'heartbeat-nonce', false, false );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'No permission' );
		}

		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : 'all';
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'info';

		if ( empty( $message ) ) {
			wp_send_json_error( 'Message cannot be empty' );
		}

		// Determine the users to notify.
		$users_to_notify = ( 'all' === $user_id ) ? get_users( array( 'fields' => 'ID' ) ) : array( $user_id );

		foreach ( $users_to_notify as $uid ) {
			$this->add_notification_to_user(
				$uid,
				array(
					'type'       => $type,
					'task_id'    => 0,
					'title'      => $message,
					'action'     => 'Manual Notification',
					'time'       => gmdate( 'Y-m-d H:i:s' ),
					'url'        => '#',
				)
			);
		}

		wp_send_json_success( 'Notification sent successfully' );
	}

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
