<?php
/**
 * Task notification handling functionality.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Handles email notifications for task-related events.
 */
class Decker_Notification_Handler {

	/**
	 * The mailer instance.
	 *
	 * @var Decker_Mailer.
	 */
	public $mailer;

	/**
	 * Initialize the notification handler.
	 */
	public function __construct() {
		$this->mailer = new Decker_Mailer();
		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks.
	 */
	private function setup_hooks() {
		add_action( 'decker_task_assigned', array( $this, 'handle_task_assigned' ), 10, 2 );
		add_action( 'decker_task_completed', array( $this, 'handle_task_completed' ), 10, 2 );
		add_action( 'decker_task_comment_added', array( $this, 'handle_task_comment' ), 10, 3 );
	}

	/**
	 * Check if email notifications are enabled in admin settings.
	 *
	 * @return bool
	 */
	private function are_notifications_enabled() {
		$options = get_option( 'decker_settings', array() );
		return isset( $options['enable_email_notifications'] ) && $options['enable_email_notifications'];
	}

	/**
	 * Get user notification preferences.
	 *
	 * @param int $user_id The user ID.
	 * @return array User preferences.
	 */
	private function get_user_preferences( $user_id ) {
		$preferences = get_user_meta( $user_id, 'decker_notification_preferences', true );
		if ( ! is_array( $preferences ) ) {
			return array(
				'notify_assigned' => true,
				'notify_completed' => true,
				'notify_comments' => true,
			);
		}
		return $preferences;
	}

	/**
	 * Handle task assignment notification.
	 *
	 * @param int $task_id The task ID.
	 * @param int $assigned_to User ID the task is assigned to.
	 */
	public function handle_task_assigned( $task_id, $assigned_to ) {
		if ( ! $this->are_notifications_enabled() || get_current_user_id() === $assigned_to ) {
			return;
		}

		$preferences = $this->get_user_preferences( $assigned_to );
		if ( ! $preferences['notify_assigned'] ) {
			return;
		}

		$user = get_userdata( $assigned_to );
		if ( ! $user ) {
			return;
		}

		$task = get_post( $task_id );
		$task_url = get_permalink( $task_id );

		$subject = sprintf(
			/* translators: %s: task title */
			esc_html__( 'New Task Assigned: %s', 'decker' ),
			$task->post_title
		);

		$content = sprintf(
			/* translators: %1$s: task title, %2$s: task URL */
			esc_html__( 'You have been assigned to the task "%1$s". Click here to view it: %2$s', 'decker' ),
			$task->post_title,
			$task_url
		);

		$this->mailer->send_email( $user->user_email, $subject, $content );
	}

	/**
	 * Handle task completion notification.
	 *
	 * @param int $task_id The task ID.
	 * @param int $completed_by User ID who completed the task.
	 */
	public function handle_task_completed( $task_id, $completed_by ) {
		if ( ! $this->are_notifications_enabled() ) {
			return;
		}

		$assigned_to = get_post_meta( $task_id, 'assigned_to', true );
		if ( ! $assigned_to || $completed_by === $assigned_to ) {
			return;
		}

		$preferences = $this->get_user_preferences( $assigned_to );
		if ( ! $preferences['notify_completed'] ) {
			return;
		}

		$user = get_userdata( $assigned_to );
		if ( ! $user ) {
			return;
		}

		$task = get_post( $task_id );
		$task_url = get_permalink( $task_id );

		$subject = sprintf(
			/* translators: %s: task title */
			esc_html__( 'Task Completed: %s', 'decker' ),
			$task->post_title
		);

		$content = sprintf(
			/* translators: %1$s: task title, %2$s: user name, %3$s: task URL */
			esc_html__( 'The task "%1$s" has been marked as completed by %2$s. Click here to view it: %3$s', 'decker' ),
			$task->post_title,
			get_userdata( $completed_by )->display_name,
			$task_url
		);

		$this->mailer->send_email( $user->user_email, $subject, $content );
	}

	/**
	 * Handle new comment notification.
	 *
	 * @param int $task_id The task ID.
	 * @param int $comment_id The comment ID.
	 * @param int $commenter_id User ID who made the comment.
	 */
	public function handle_task_comment( $task_id, $comment_id, $commenter_id ) {
		if ( ! $this->are_notifications_enabled() ) {
			return;
		}

		$assigned_to = get_post_meta( $task_id, 'assigned_to', true );
		if ( ! $assigned_to || $commenter_id === $assigned_to ) {
			return;
		}

		$preferences = $this->get_user_preferences( $assigned_to );
		if ( ! $preferences['notify_comments'] ) {
			return;
		}

		$user = get_userdata( $assigned_to );
		if ( ! $user ) {
			return;
		}

		$task = get_post( $task_id );
		$task_url = get_permalink( $task_id );
		$comment = get_comment( $comment_id );

		$subject = sprintf(
			/* translators: %s: task title */
			esc_html__( 'New Comment on Task: %s', 'decker' ),
			$task->post_title
		);

		$content = sprintf(
			/* translators: %1$s: task title, %2$s: user name, %3$s: task URL */
			esc_html__( 'A new comment has been added to task "%1$s" by %2$s. Click here to view it: %3$s', 'decker' ),
			$task->post_title,
			get_userdata( $commenter_id )->display_name,
			$task_url
		);

		$this->mailer->send_email( $user->user_email, $subject, $content );
	}
}