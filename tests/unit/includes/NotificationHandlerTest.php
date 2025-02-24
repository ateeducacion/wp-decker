<?php
/**
 * Class DeckerNotificationHandlerTest
 *
 * @package Decker
 */

class DeckerNotificationHandlerTest extends Decker_Test_Base {

	/**
	 * Instance of Decker_Notifications.
	 *
	 * @var Decker_Notifications
	 */
	private $notifications;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $test_user;

	/**
	 * Test task ID.
	 *
	 * @var int
	 */
	private $test_task;

	/**
	 * Tracks fired hooks.
	 *
	 * @var array
	 */
	private $fired_hooks = array();

	/**
	 * Captured email data.
	 *
	 * @var array
	 */
	private $captured_mail = array();

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();


		$this->captured_mail = [];

		// Enable email notifications.
		update_option(
			'decker_settings',
			array(
				'allow_email_notifications' => true,
			)
		);

		$this->fired_hooks = array();

		// Set up email capturing.
		add_filter( 'wp_mail', array( $this, 'capture_mail' ) );

		// Create a test user.
		$this->test_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test@example.com',
			)
		);
		wp_set_current_user( $this->test_user );

		// Create a test task.
		$this->task_id = $this->factory->task->create(
			array(
				'post_title' => 'Test Task',
			)
		);


		// Initialize the notifications handler.
		$this->notifications = new Decker_Notification_Handler();

		// Track hooks being fired.
		add_action( 'decker_user_assigned', array( $this, 'track_hook' ), 10, 2 );
		add_action( 'decker_task_completed', array( $this, 'track_hook' ), 10, 2 );
		add_action( 'decker_task_comment_added', array( $this, 'track_hook' ), 10, 3 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		// Delete the test task and user.
		wp_delete_post( $this->task_id, true );
		wp_delete_user( $this->test_user );
		delete_option( 'decker_settings' );

		$this->fired_hooks  = array();
		$this->captured_mail = array();

		remove_action( 'decker_user_assigned', array( $this, 'track_hook' ), 10 );
		remove_action( 'decker_task_completed', array( $this, 'track_hook' ), 10 );
		remove_action( 'decker_task_comment_added', array( $this, 'track_hook' ), 10 );
		remove_filter( 'wp_mail', array( $this, 'capture_mail' ) );

		wp_cache_flush();

		parent::tear_down();
	}

	/**
	 * Tracks the hook name and its arguments whenever a relevant hook is triggered.
	 */
	public function track_hook() {
		$this->fired_hooks[] = array(
			'hook' => current_filter(),
			'args' => func_get_args(),
		);
	}

	/**
	 * Helper method to trigger notification actions.
	 *
	 * @param string $action Hook name.
	 * @param mixed  ...$params Hook arguments.
	 */
	private function trigger_notifications( $action, ...$params ) {
		do_action( $action, ...$params );
	}

	/**
	 * Tests Heartbeat response when no notifications exist for the current user.
	 */
	public function test_heartbeat_no_notifications() {
		$response = array();
		$data     = array();

		$result = $this->notifications->heartbeat_received( $response, $data );

		$this->assertEquals( $response, $result, 'Response should not be modified when no notifications exist.' );
	}

	/**
	 * Tests Heartbeat response when there are pending notifications.
	 */
	public function test_heartbeat_with_notifications() {
		$notification = array(
			'type'       => 'task_assigned',
			'task_id'    => $this->task_id,
			'task_title' => 'Test Task',
			'timestamp'  => current_time( 'timestamp' ),
			'message'    => 'Test notification message',
		);

		update_user_meta( $this->test_user, 'decker_pending_notifications', array( $notification ) );

		$response = array();
		$data     = array();

		$result = $this->notifications->heartbeat_received( $response, $data );

		$this->assertArrayHasKey( 'decker_notifications', $result, 'Response should include notifications.' );
		$this->assertEquals( array( $notification ), $result['decker_notifications'], 'Notifications should match.' );

		// Verify that the notifications were cleared.
		$pending = get_user_meta( $this->test_user, 'decker_pending_notifications', true );
		$this->assertEmpty( $pending, 'Pending notifications should be cleared after Heartbeat.' );
	}

	/**
	 * Tests user-assigned notification hook processing.
	 */
	public function test_user_assigned_notification() {
		// Set user preferences.
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_assigned' => true,
			)
		);

		// Trigger the assignment hook.
		do_action( 'decker_user_assigned', $this->task_id, $this->test_user );

		// Verify hook was processed.
		$this->assertCount( 1, $this->fired_hooks, 'Hook should have been processed once.' );
		$this->assertEquals( 'decker_user_assigned', $this->fired_hooks[0]['hook'], 'Unexpected hook name.' );
		$this->assertEquals(
			array( $this->task_id, $this->test_user ),
			$this->fired_hooks[0]['args'],
			'Unexpected hook arguments.'
		);
	}

	/**
	 * Tests task completion notification.
	 */
	public function test_task_completed_notification() {

		// Set user preferences.
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_completed' => true,
				'notify_created'   => false,
				'notify_assigned'  => false,
				'notify_completed' => true,
				'notify_comments'  => false,

			)
		);

		// Assign the task to the test user (for backward compatibility in meta).
		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user ),
				'stack' => 'done'
			)
		);

		// Verificar metadata de prioridad
		$this->assertEquals(
		    '0', 
		    get_post_meta($this->task_id, 'max_priority', true),
		    'Priority meta not set correctly'
		);

		// Trigger the completion hook.
		$this->trigger_notifications( 'decker_task_completed', $this->task_id, $this->test_user );

		// Check that at least one email was captured.
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );

		// Verify email details.
		$this->assertEquals( 'test@example.com', $this->captured_mail[0]['to'], 'Recipient does not match.' );
		$this->assertStringContainsString( 'Task Completed', $this->captured_mail[0]['subject'], 'Subject mismatch.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail[0]['message'], 'Task title not found in email body.' );

		// Verify that a notification was saved for Heartbeat.
		$pending = get_user_meta( $this->test_user, 'decker_pending_notifications', true );
		$this->assertNotEmpty( $pending, 'No notification was saved for Heartbeat.' );
		$this->assertEquals( 'task_completed', $pending[0]['type'], 'Notification type mismatch.' );
		$this->assertEquals( $this->task_id, $pending[0]['task_id'], 'Task ID mismatch.' );
	}

	/**
	 * Tests task comment notification.
	 */
	public function test_task_comment_notification() {

		// Set user preferences.
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_comments' => true,
			)
		);

		// Assign the task to the test user (for backward compatibility in meta).
		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user ),
			)
		);

		$commenter_id = self::factory()->user->create(['role' => 'subscriber']);		

		// Create a comment using the WordPress factory.
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Test comment',
				'user_id'         => $commenter_id,
				'comment_approved' => 1,
			)
		);

		$this->assertNotEquals( 0, $comment_id, 'Failed to create comment.' );
		$this->assertNotFalse( $comment_id, 'Failed to create comment.' );

		$comment = get_comment( $comment_id );
		$this->assertEquals( 'Test comment', $comment->comment_content, 'Comment content mismatch.' );

		// Trigger comment notification.
		$this->trigger_notifications( 'decker_task_comment_added', $this->task_id, $comment_id, $commenter_id );

		$comment_notification = null;


		// Buscar la notificación de comentario específica
		foreach ($this->captured_mail as $mail) {
		    if (str_contains($mail['subject'], 'New Comment')) {
		        $comment_notification = $mail;
		        break;
		    }
		}

		$this->assertNotNull($comment_notification, 'No se encontró email de comentario');
		$this->assertEquals('test@example.com', $comment_notification['to']);





		// Check that at least one email was captured.
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );

		// Verify email details.
		$this->assertEquals( 'test@example.com', $this->captured_mail[0]['to'], 'Recipient mismatch.' );
		$this->assertStringContainsString( 'New Comment', $this->captured_mail[0]['subject'], 'Subject mismatch.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail[0]['message'], 'Task title not found in email body.' );

		// Verify that a notification was saved for Heartbeat.
		$pending = get_user_meta( $this->test_user, 'decker_pending_notifications', true );
		$this->assertNotEmpty( $pending, 'No notification was saved for Heartbeat.' );
		$this->assertEquals( 'task_comment', $pending[0]['type'], 'Notification type mismatch.' );
		$this->assertEquals( $this->task_id, $pending[0]['task_id'], 'Task ID mismatch.' );
	}

	/**
	 * Tests that no notifications are sent when the current user is the same user performing the action.
	 */
	public function test_no_self_notifications() {

		// Set the current user to the test user.
		wp_set_current_user( $this->test_user );

		// Set user preferences.
		update_user_meta(
			$this->test_user,
			'decker_notification_preferences',
			array(
				'notify_assigned'  => true,
				'notify_completed' => true,
				'notify_comments'  => true,
			)
		);

		// Trigger notifications that should be ignored for self-actions.
		$this->trigger_notifications( 'decker_user_assigned', $this->task_id, $this->test_user );
		$this->trigger_notifications( 'decker_task_completed', $this->task_id, $this->test_user );
		$this->trigger_notifications( 'decker_task_comment_added', $this->task_id, 1, $this->test_user );

		// Verify that no email was sent.
		$this->assertEmpty( $this->captured_mail, 'No email should have been sent for self-actions.' );
	}

	/**
	 * Captures emails sent by wp_mail().
	 *
	 * @param array $args Email arguments.
	 *
	 * @return array The same email arguments, unchanged.
	 */
	public function capture_mail($args) {
	    $this->captured_mail[] = $args; // Cambiar a array de emails
	    return $args;
	}
}
