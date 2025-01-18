<?php
/**
 * Decker Notifications Class.
 *
 * Handles both Heartbeat and email notifications for task events in the Decker plugin.
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Notification_Handler
 *
 * Sends email notifications to assigned users (if allowed) and creates
 * user-meta-based notifications for Heartbeat API to display them in real-time.
 */
class Decker_Notification_Handler {

	/**
	 * The mailer instance.
	 *
	 * @var Decker_Mailer
	 */
	public $mailer;

	/**
	 * Constructor.
	 *
	 * Initializes the class, the mailer, and hooks into WordPress actions and filters.
	 */
	public function __construct() {
		$this->mailer = new Decker_Mailer();
		$this->setup_hooks();

		// Heartbeat filters (anonymous and logged in).
		add_filter( 'heartbeat_received', array( $this, 'heartbeat_received' ), 10, 2 );
		add_filter( 'heartbeat_nopriv_received', array( $this, 'heartbeat_received' ), 10, 2 );
	}

	/**
	 * Sets up WordPress hooks for task-related events.
	 *
	 * Hooks are only registered if plugin notifications are enabled globally.
	 * User-specific settings are handled separately.
	 */
	private function setup_hooks() {
		if ( $this->are_notifications_enabled() ) {
			// Triggered when a new task is created.
			add_action( 'decker_task_created', array( $this, 'handle_task_created' ) );

			// Triggered when a user is assigned to a task.
			add_action( 'decker_user_assigned', array( $this, 'handle_user_assigned' ), 10, 2 );

			// Triggered when a task is completed.
			add_action( 'decker_task_completed', array( $this, 'handle_task_completed' ), 10, 2 );

			// Triggered when a new comment is added to a task.
			add_action( 'decker_task_comment_added', array( $this, 'handle_new_comment' ), 10, 3 );
		}
	}

	/**
	 * Checks if email notifications are enabled in the plugin settings.
	 *
	 * @return bool True if global email notifications are enabled, false otherwise.
	 */
	private function are_notifications_enabled() {
		$options = get_option( 'decker_settings', array() );

		if ( isset( $options['enable_email_notifications'] ) && $options['enable_email_notifications'] ) {
			return true;
		}

		return false;
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

		// Merge user preferences with defaults to avoid missing keys.
		return wp_parse_args( $preferences, $defaults );
	}

	/**
	 * Handles sending of newly created task notifications (for assigned users).
	 *
	 * This is triggered by the 'decker_task_created' hook.
	 *
	 * @param int $task_id The ID of the newly created task.
	 */
	public function handle_task_created( $task_id ) {
		// Fetch assigned users.
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );

		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}

		$task    = get_post( $task_id );
		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id' => $task_id,
			),
			site_url()
		);

		// For each assigned user, send an email if their preferences allow it.
		foreach ( $assigned_users as $user_id ) {
			// Heartbeat notification is always saved, regardless of preferences.
			$notification_data = array(
				'type'      => 'task_created',
				'task_id'   => $task_id,
				'task_title' => $task->post_title,
				'timestamp' => current_time( 'timestamp' ),
				'message'   => sprintf(
					'A new task "%s" has been created. Click here to view it: %s',
					$task->post_title,
					$task_url
				),
			);
			$this->save_notification( $user_id, $notification_data );

			// Check if the user wants to receive an email for new tasks.
			$user_prefs = $this->get_user_preferences( $user_id );
			if ( ! $user_prefs['notify_created'] ) {
				continue;
			}

			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$subject = sprintf(
				'New Task Created: %s',
				$task->post_title
			);

			$content = sprintf(
				'A new task "%1$s" has been created. Click here to view it: %2$s',
				$task->post_title,
				$task_url
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
		if ( ! $task_id || ! $user_id ) {
			return;
		}

		// Heartbeat notification is always saved.
		$task     = get_post( $task_id );
		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id' => $task_id,
			),
			site_url()
		);

		$notification_data = array(
			'type'      => 'task_assigned',
			'task_id'   => $task_id,
			'task_title' => $task->post_title,
			'timestamp' => current_time( 'timestamp' ),
			'message'   => sprintf(
				'You have been assigned to the task "%1$s". Click here to view it: %2$s',
				$task->post_title,
				$task_url
			),
		);
		$this->save_notification( $user_id, $notification_data );

		// Check global and user-level preferences for sending an email.
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

		$subject = sprintf(
			'New Task Assigned: %s',
			$task->post_title
		);

		$content = sprintf(
			'You have been assigned to the task "%1$s". Click here to view it: %2$s',
			$task->post_title,
			$task_url
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
	}

	/**
	 * Handles task completion notifications.
	 *
	 * This is triggered by the 'decker_task_completed' hook.
	 *
	 * @param int $task_id The task ID.
	 * @param int $completed_by The user ID who completed the task.
	 */
	public function handle_task_completed( $task_id, $completed_by ) {
		if ( ! $task_id ) {
			return;
		}

		// Retrieve all assigned users. They might differ from the user who completed the task.
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}

		$task     = get_post( $task_id );
		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id' => $task_id,
			),
			site_url()
		);

		$finisher = get_userdata( $completed_by );

		foreach ( $assigned_users as $user_id ) {
			// Heartbeat notification is always saved.
			$notification_data = array(
				'type'      => 'task_completed',
				'task_id'   => $task_id,
				'task_title' => $task->post_title,
				'timestamp' => current_time( 'timestamp' ),
				'message'   => sprintf(
					'The task "%1$s" has been completed by %2$s. Click here to view it: %3$s',
					$task->post_title,
					$finisher ? $finisher->display_name : 'Unknown user',
					$task_url
				),
			);
			$this->save_notification( $user_id, $notification_data );

			// Check global and user-level preferences for sending an email.
			if ( ! $this->are_notifications_enabled() ) {
				continue;
			}

			// Skip sending an email to the user who completed the task if desired.
			// Example condition: $completed_by !== $user_id (uncomment if needed).
			$preferences = $this->get_user_preferences( $user_id );
			if ( ! $preferences['notify_completed'] ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$subject = sprintf(
				'Task Completed: %s',
				$task->post_title
			);

			$content = sprintf(
				'The task "%1$s" has been marked as completed by %2$s. Click here to view it: %3$s',
				$task->post_title,
				$finisher ? $finisher->display_name : 'Unknown user',
				$task_url
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

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( empty( $assigned_users ) || ! is_array( $assigned_users ) ) {
			return;
		}

		$task     = get_post( $task_id );
		$task_url = add_query_arg(
			array(
				'decker_page' => 'task',
				'id' => $task_id,
			),
			site_url()
		);

		$comment  = get_comment( $comment_id );
		$author   = get_userdata( $commenter_id );

		foreach ( $assigned_users as $user_id ) {
			// Heartbeat notification is always saved.
			$notification_data = array(
				'type'      => 'task_comment',
				'task_id'   => $task_id,
				'task_title' => $task->post_title,
				'timestamp' => current_time( 'timestamp' ),
				'message'   => sprintf(
					'A new comment has been added to task "%1$s" by %2$s. Click here to view it: %3$s',
					$task->post_title,
					$author ? $author->display_name : 'Unknown user',
					$task_url
				),
			);
			$this->save_notification( $user_id, $notification_data );

			// Check global and user-level preferences for sending an email.
			if ( ! $this->are_notifications_enabled() ) {
				continue;
			}

			// Optional: skip email if the commenter is the same as the user.
			if ( $user_id === $commenter_id ) {
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

			$subject = sprintf(
				'New Comment on Task: %s',
				$task->post_title
			);

			$content = sprintf(
				'A new comment has been added to the task "%1$s" by %2$s. Click here to view it: %3$s',
				$task->post_title,
				$author ? $author->display_name : 'Unknown user',
				$task_url
			);

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$this->mailer->send_email( $user->user_email, $subject, $content, $headers );
		}
	}

	/**
	 * Stores a Heartbeat notification for a specific user.
	 *
	 * These notifications are retrieved by the 'heartbeat_received' filter
	 * to be displayed to the user in real-time.
	 *
	 * @param int   $user_id The user ID.
	 * @param array $notification An associative array containing notification data.
	 */
	private function save_notification( $user_id, $notification ) {
		$notifications = get_user_meta( $user_id, 'decker_pending_notifications', true );
		if ( ! is_array( $notifications ) ) {
			$notifications = array();
		}

		$notifications[] = $notification;
		update_user_meta( $user_id, 'decker_pending_notifications', $notifications );
	}

	/**
	 * Processes Heartbeat data for pending notifications.
	 *
	 * When a user has pending notifications stored in user meta, they are
	 * returned in the Heartbeat response and then cleared from the database.
	 *
	 * @param array $response The current Heartbeat response.
	 * @param array $data The data received from the client.
	 * @return array Modified Heartbeat response with any pending notifications added.
	 */
	public function heartbeat_received( $response, $data ) {
		$current_user_id = get_current_user_id();

		if ( ! $current_user_id ) {
			return $response;
		}

		$notifications = get_user_meta( $current_user_id, 'decker_pending_notifications', true );
		if ( ! empty( $notifications ) ) {
			$response['decker_notifications'] = $notifications;
			// Clear notifications after retrieving them.
			delete_user_meta( $current_user_id, 'decker_pending_notifications' );
		}

		return $response;
	}
}
