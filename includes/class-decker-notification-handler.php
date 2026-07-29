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
	 * The mailer instance.
	 *
	 * @var Decker_Mailer
	 */
	public $mailer;

	/**
	 * The notification store.
	 *
	 * @var Decker_Notification_Store
	 */
	private $store;


	/**
	 * Constructor
	 *
	 * Both collaborators can be injected so tests exercise the exact instances
	 * the handler persists through, instead of parallel ones that would
	 * register the Heartbeat and AJAX hooks a second time.
	 *
	 * @param Decker_Notification_Store|null $store Optional store to persist through.
	 * @param Decker_Notification_Ajax|null  $ajax  Optional browser-facing side; when given, its hooks are already registered and no second instance is created.
	 */
	public function __construct( $store = null, $ajax = null ) {
		$this->mailer = new Decker_Mailer();
		$this->store  = $store instanceof Decker_Notification_Store ? $store : new Decker_Notification_Store();

		if ( ! $ajax instanceof Decker_Notification_Ajax ) {
			// The browser-facing side (Heartbeat + AJAX) registers its own hooks.
			new Decker_Notification_Ajax( $this->store );
		}

		// Triggered when a new task is created.
		add_action( 'decker_task_created', array( $this, 'handle_task_created' ) );

		// Triggered when a user is assigned to a task.
		add_action( 'decker_user_assigned', array( $this, 'handle_user_assigned' ), 10, 2 );

		// Triggered when a task is completed.
		add_action( 'decker_task_completed', array( $this, 'handle_task_completed' ), 10, 3 );

		// Triggered when a new comment is added to a task.
		add_action( 'wp_insert_comment', array( $this, 'handle_new_comment' ), 10, 2 );

		// Triggered when a responsable is changed.
		add_action( 'decker_task_responsable_changed', array( $this, 'handle_responsable_changed' ), 10, 3 );
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
	 * Retrieves the assigned users for a task, normalized to an array.
	 *
	 * @param int $task_id The task ID.
	 * @return array Assigned user IDs, or an empty array when none are stored.
	 */
	private function get_assigned_users( $task_id ) {
		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( ! is_array( $assigned_users ) ) {
			return array();
		}

		return $assigned_users;
	}

	/**
	 * Resolves the email recipient for a user, honoring global and per-user settings.
	 *
	 * @param int    $user_id        The user ID.
	 * @param string $preference_key The notification preference key to check.
	 * @return WP_User|false The user object when email may be sent, false otherwise.
	 */
	private function get_email_recipient( $user_id, $preference_key ) {
		if ( ! $this->are_notifications_enabled() ) {
			return false;
		}

		$preferences = $this->get_user_preferences( $user_id );
		if ( ! $preferences[ $preference_key ] ) {
			return false;
		}

		return get_userdata( $user_id );
	}

	/**
	 * Sends an HTML email through the mailer with the standard content-type header.
	 *
	 * @param string $email   The recipient email address.
	 * @param string $subject The email subject.
	 * @param string $content The email body.
	 */
	private function send_html_email( $email, $subject, $content ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$this->mailer->send_email( $email, $subject, $content, $headers );
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
			// Skip notification for the creator of the task.
			if ( $user_id == $creator_id ) {
				continue;
			}

			// Store notification in user meta for Heartbeat and UI.
			$this->store->add_notification_to_user(
				$user_id,
				array(
					'type'       => 'task_created',
					'task_id'    => $task_id,
					'title'      => $task->post_title,
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

		/* error_log( 'Entering in handle_user_assigned()...........' ); */

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

		/* error_log( 'Adding notification in handle_user_assigned() for user: ' . $user_id ); */

		// Store notification in user meta for Heartbeat and UI.
		$this->store->add_notification_to_user(
			$user_id,
			array(
				'type'       => 'task_assigned',
				'task_id'    => $task_id,
				'title'      => $task->post_title,
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

		/* error_log( 'Entering in handle_task_completed()' ); */

		if ( ! $task_id ) {
			return;
		}

		$assigned_users = $this->get_assigned_users( $task_id );
		if ( empty( $assigned_users ) ) {
			return;
		}

		// Skip the user who completed the task.
		$assigned_users = array_diff( $assigned_users, array( $completing_user_id ) );

		$task     = get_post( $task_id );
		$finisher = get_userdata( $completing_user_id );

		foreach ( $assigned_users as $user_id ) {
			$this->notify_user_task_completed( $user_id, $task, $task_id, $finisher );
		}
	}

	/**
	 * Notifies a single assigned user that a task was completed.
	 *
	 * Stores the in-app notification (always) and, when email notifications are
	 * enabled for the user, sends the completion email.
	 *
	 * @param int           $user_id  The recipient user ID.
	 * @param WP_Post       $task     The completed task post object.
	 * @param int           $task_id  The completed task ID.
	 * @param WP_User|false $finisher The user who completed the task, or false if unknown.
	 */
	private function notify_user_task_completed( $user_id, $task, $task_id, $finisher ) {

		/* error_log( 'Adding notification in handle_task_completed() for user: ' . $user_id ); */

		$finisher_name = $finisher ? $finisher->display_name : __( 'Unknown user', 'decker' );

		// Store notification in user meta for Heartbeat and UI.
		$this->store->add_notification_to_user(
			$user_id,
			array(
				'type'       => 'task_completed',
				'task_id'    => $task_id,
				'title'      => $task->post_title,
				/* Translators: %s is a username. */
				'action'     => sprintf( __( 'Completed by %s', 'decker' ), $finisher_name ),
				'time'       => gmdate( 'Y-m-d H:i:s' ),
				'url'        => esc_url( $this->build_task_url( $task_id ) ),
			)
		);

		// Check if email notifications are enabled and if the user allows them.
		$user = $this->get_email_recipient( $user_id, 'notify_completed' );
		if ( ! $user ) {
			return;
		}

		$subject = sprintf( 'Task Completed: %s', $task->post_title );
		$content = sprintf(
			'The task "%1$s" has been marked as completed by %2$s. Click here to view it: %3$s',
			$task->post_title,
			$finisher_name,
			$this->build_task_url( $task_id )
		);

		$this->send_html_email( $user->user_email, $subject, $content );
	}

	/**
	 * Captures comments inserted via REST.
	 *
	 * @param int    $comment_id The inserted comment ID.
	 * @param object $comment    The comment object.
	 */
	public function handle_new_comment( $comment_id, $comment ) {
		// Define the commenter ID.
		$commenter_id = (int) $comment->user_id;
		/* error_log( 'Handling comment in handle_new_comment() for user: ' . $commenter_id ); */

		$post_id   = $comment->comment_post_ID;
		$post_type = get_post_type( $post_id );

		// Only proceed if the post type is decker_task.
		if ( 'decker_task' !== $post_type ) {
			return;
		}

		$assigned_users = $this->get_assigned_users( $post_id );
		if ( empty( $assigned_users ) ) {
			return;
		}

		$task   = get_post( $post_id );
		$comment = get_comment( $comment_id );
		$author  = get_userdata( $commenter_id );

		foreach ( $assigned_users as $user_id ) {
			// Skip the commenter to avoid self-notifications.
			if ( $user_id === $commenter_id ) {
				continue;
			}

			$this->notify_user_task_comment( $user_id, $task, $post_id, $author );
		}
	}

	/**
	 * Notifies a single assigned user about a new comment on a task.
	 *
	 * Stores the in-app notification (always) and, when email notifications are
	 * enabled for the user, sends the comment email.
	 *
	 * @param int           $user_id The recipient user ID.
	 * @param WP_Post       $task    The commented task post object.
	 * @param int           $post_id The commented task ID.
	 * @param WP_User|false $author  The comment author, or false if unknown.
	 */
	private function notify_user_task_comment( $user_id, $task, $post_id, $author ) {
		$author_name = $author ? $author->display_name : __( 'Unknown user', 'decker' );

		// Store notification in user meta for Heartbeat and UI.
		$this->store->add_notification_to_user(
			$user_id,
			array(
				'type'    => 'task_comment',
				'task_id' => $post_id,
				// Translators: %s is the task title.
				'title'   => sprintf( __( 'New Comment on Task: %s', 'decker' ), $task->post_title ),
				// Translators: %s is a username.
				'action'  => sprintf( __( 'Comment by %s', 'decker' ), $author_name ),
				'time'    => gmdate( 'Y-m-d H:i:s' ),
				'url'     => esc_url( $this->build_task_url( $post_id ) ),
			)
		);

		// Check if email notifications are enabled and if the user allows them.
		$user = $this->get_email_recipient( $user_id, 'notify_comments' );
		if ( ! $user ) {
			return;
		}

		// Translators: %s is the task title.
		$subject = sprintf( 'New Comment on Task: %s', $task->post_title );
		$content = sprintf(
			// translators: 1: Task title, 2: Author name, 3: Task URL.
			'A new comment has been added to the task "%1$s" by %2$s. Click here to view it: %3$s',
			$task->post_title,
			$author_name,
			$this->build_task_url( $post_id )
		);

		$this->send_html_email( $user->user_email, $subject, $content );
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
}
