<?php
/**
 * Edge case tests for Decker_Notification_Handler.
 *
 * Exercises failure/boundary paths that are not covered by the main
 * NotificationHandlerTest.
 *
 * @package Decker
 */

/**
 * Supplementary edge-case coverage for the notification handler.
 */
class NotificationHandlerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * The notification handler under test.
	 *
	 * @var Decker_Notification_Handler
	 */
	private $handler;

	/**
	 * Emails captured by the wp_mail filter.
	 *
	 * @var array
	 */
	private $captured_emails = array();

	/**
	 * Editor user created for the test run.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );
		$this->enable_wp_send_json_capture();

		$this->captured_emails = array();
		add_filter( 'wp_mail', array( $this, 'capture_email' ) );

		update_option(
			'decker_settings',
			array( 'allow_email_notifications' => true )
		);

		$this->editor_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'edge-editor@example.com',
			)
		);
		wp_set_current_user( $this->editor_id );

		$this->handler = new Decker_Notification_Handler();
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		remove_filter( 'wp_mail', array( $this, 'capture_email' ) );
		delete_option( 'decker_settings' );
		$this->disable_wp_send_json_capture();
		parent::tear_down();
	}

	/**
	 * Capture outgoing emails.
	 *
	 * @param array $args The wp_mail arguments.
	 * @return array Unchanged arguments.
	 */
	public function capture_email( array $args ): array {
		$this->captured_emails[] = $args;
		return $args;
	}

	// -----------------------------------------------------------------------
	// handle_task_created – guard clauses
	// -----------------------------------------------------------------------

	/**
	 * Passing a non-existent task ID to handle_task_created() returns
	 * silently without sending any email.
	 */
	public function test_handle_task_created_with_nonexistent_task_sends_no_email() {
		$this->handler->handle_task_created( 999999 );

		$this->assertCount( 0, $this->captured_emails );
	}

	/**
	 * A task with no assigned users triggers no email from handle_task_created().
	 */
	public function test_handle_task_created_with_no_assigned_users_sends_no_email() {
		$task_id = self::factory()->task->create();
		delete_post_meta( $task_id, 'assigned_users' );

		$this->handler->handle_task_created( $task_id );

		$this->assertCount( 0, $this->captured_emails );
	}

	/**
	 * When email notifications are globally disabled, handle_task_created()
	 * stores an in-app notification but does NOT send an email.
	 */
	public function test_handle_task_created_skips_email_when_notifications_disabled() {
		update_option( 'decker_settings', array( 'allow_email_notifications' => false ) );

		$creator_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'creator@example.com',
			)
		);
		$assignee_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'assignee@example.com',
			)
		);

		$task_id = self::factory()->task->create(
			array(
				'author'         => $creator_id,
				'assigned_users' => array( $assignee_id ),
			)
		);

		$this->handler->handle_task_created( $task_id );

		$this->assertCount( 0, $this->captured_emails );
	}

	// -----------------------------------------------------------------------
	// handle_user_assigned – non-existent user
	// -----------------------------------------------------------------------

	/**
	 * Assigning a non-existent user ID does not send an email and does not
	 * throw.
	 */
	public function test_handle_user_assigned_with_nonexistent_user_sends_no_email() {
		$task_id = self::factory()->task->create();

		$this->handler->handle_user_assigned( $task_id, 999999 );

		$this->assertCount( 0, $this->captured_emails );
	}

	/**
	 * Assigning a non-existent task ID does not send an email and does not
	 * throw.
	 */
	public function test_handle_user_assigned_with_nonexistent_task_sends_no_email() {
		$this->handler->handle_user_assigned( 999999, $this->editor_id );

		$this->assertCount( 0, $this->captured_emails );
	}

	// -----------------------------------------------------------------------
	// handle_responsable_changed – guard clauses
	// -----------------------------------------------------------------------

	/**
	 * Changing the responsible to a non-existent user returns silently
	 * without sending an email.
	 */
	public function test_handle_responsable_changed_with_nonexistent_new_user_sends_no_email() {
		$task_id = self::factory()->task->create();

		$this->handler->handle_responsable_changed( $task_id, $this->editor_id, 999999 );

		$this->assertCount( 0, $this->captured_emails );
	}

	// -----------------------------------------------------------------------
	// handle_task_completed – no assigned users
	// -----------------------------------------------------------------------

	/**
	 * Completing a task with no assigned users triggers no email.
	 */
	public function test_handle_task_completed_with_no_assigned_users_sends_no_email() {
		$task_id = self::factory()->task->create();
		delete_post_meta( $task_id, 'assigned_users' );

		$this->handler->handle_task_completed( $task_id, 'done', $this->editor_id );

		$this->assertCount( 0, $this->captured_emails );
	}

	// -----------------------------------------------------------------------
	// handle_new_comment – non-task post
	// -----------------------------------------------------------------------

	/**
	 * A comment on a non-decker_task post is silently ignored.
	 */
	public function test_handle_new_comment_on_non_task_post_sends_no_email() {
		$page_id    = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $page_id,
				'user_id'         => $this->editor_id,
			)
		);
		$comment = get_comment( $comment_id );

		$this->handler->handle_new_comment( $comment_id, $comment );

		$this->assertCount( 0, $this->captured_emails );
	}

	// -----------------------------------------------------------------------
	// User preference: notify_created = false
	// -----------------------------------------------------------------------

	/**
	 * When an assignee has disabled the "notify_created" preference, they
	 * receive an in-app notification but NOT an email.
	 */
	public function test_handle_task_created_skips_email_when_user_pref_disabled() {
		$creator_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'pref-creator@example.com',
			)
		);
		$assignee_id = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'pref-assignee@example.com',
			)
		);

		update_user_meta(
			$assignee_id,
			'decker_notification_preferences',
			array( 'notify_created' => false )
		);

		$task_id = self::factory()->task->create(
			array(
				'author'         => $creator_id,
				'assigned_users' => array( $assignee_id ),
			)
		);

		$this->handler->handle_task_created( $task_id );

		$this->assertCount( 0, $this->captured_emails );
	}
}
