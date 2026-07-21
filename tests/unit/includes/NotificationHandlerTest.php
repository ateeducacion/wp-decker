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

		// ajax_get_decker_notifications() ends in wp_send_json_*(); make it catchable.
		$this->enable_wp_send_json_capture();

		$this->captured_mail = array();

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
		add_action( 'decker_task_completed', array( $this, 'track_hook' ), 10, 3 );
		add_action( 'decker_task_comment_added', array( $this, 'track_hook' ), 10, 3 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		$this->disable_wp_send_json_capture();

		// Delete the test task and user.
		wp_delete_post( $this->task_id, true );
		wp_delete_user( $this->test_user );
		delete_option( 'decker_settings' );

		$this->fired_hooks   = array();
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

		$result = $this->notifications->heartbeat_received( $response, $data, null );

		$this->assertEquals( $response, $result, 'Response should not be modified when no notifications exist.' );
	}

	/**
	 * Tests Heartbeat response when there are pending notifications.
	 */
	public function test_heartbeat_with_notifications() {
		$notification = array(
			'url'       => '#',
			'taskId'    => 0,
			'iconColor' => 'primary',
			'iconClass' => 'ri-add-line',
			'title'     => 'New Notification',
			'action'    => '',
			'time'      => '',
			'type'      => 'task_created',
		);

		update_user_meta( $this->test_user, 'decker_pending_notifications', array( $notification ) );

		$response = array();
		$data     = array();

		$result = $this->notifications->heartbeat_received( $response, $data, null );

		$this->assertArrayHasKey( 'decker_notifications', $result, 'Response should include notifications.' );
		$this->assertCount( 1, $result['decker_notifications'], 'Response should include exactly one notification.' );
		$this->assertArrayHasKey( 'notificationId', $result['decker_notifications'][0], 'Notification should include a generated identifier.' );
		$this->assertNotEmpty( $result['decker_notifications'][0]['notificationId'], 'Notification identifier should not be empty.' );
		unset( $result['decker_notifications'][0]['notificationId'] );
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
	public function test_task_completed_notification_own_user() {

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
				'stack'          => 'done',
			)
		);

           // Check priority metadata
		$this->assertEquals(
			'0',
			get_post_meta( $this->task_id, 'max_priority', true ),
			'Priority meta not set correctly'
		);

		// Trigger the completion hook.
		$this->trigger_notifications( 'decker_task_completed', $this->task_id, 'done', $this->test_user );

		// Check that at least one email was captured.
		$this->assertEmpty( $this->captured_mail, 'Email was captured.' );

		// Verify that a notification was saved for Heartbeat.
		$pending = get_user_meta( $this->test_user, 'decker_pending_notifications', true );
		$this->assertEmpty( $pending, 'Notification was saved for Heartbeat.' );
	}


	/**
	 * Tests task completion notification.
	 */
	public function test_task_completed_notification_other_user() {

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

		$other_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test2@example.com',
			)
		);

		// Assign the task to the test user (for backward compatibility in meta).
		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user, $other_user ),
				'stack'          => 'done',
			)
		);

           // Check priority metadata
		$this->assertEquals(
			'0',
			get_post_meta( $this->task_id, 'max_priority', true ),
			'Priority meta not set correctly'
		);

		// Trigger the completion hook.
		$this->trigger_notifications( 'decker_task_completed', $this->task_id, 'done', $this->test_user );

		// Check that at least one email was captured.
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );

		// Verify email details.
		$this->assertEquals( 'test2@example.com', $this->captured_mail[0]['to'], 'Recipient does not match.' );
		$this->assertStringContainsString( 'Task Completed', $this->captured_mail[0]['subject'], 'Subject mismatch.' );
		$this->assertStringContainsString( 'Test Task', $this->captured_mail[0]['message'], 'Task title not found in email body.' );

		// Verify that a notification was saved for Heartbeat.
		$pending = get_user_meta( $other_user, 'decker_pending_notifications', true );
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

		$commenter_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a comment using the WordPress factory.
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'comment_content'  => 'Test comment',
				'user_id'          => $commenter_id,
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

           // Find the specific comment notification
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'New Comment' ) ) {
				$comment_notification = $mail;
				break;
			}
		}

		$this->assertNotNull( $comment_notification, 'No se encontró email de comentario' );
		$this->assertEquals( 'test@example.com', $comment_notification['to'] );

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
		$this->trigger_notifications( 'decker_task_completed', $this->task_id, 'done', $this->test_user );
		$this->trigger_notifications( 'decker_task_comment_added', $this->task_id, 1, $this->test_user );

		// Verify that no email was sent.
		$this->assertEmpty( $this->captured_mail, 'No email should have been sent for self-actions.' );
	}

	/**
	 * Tests notifications are stored with a notification identifier.
	 */
	public function test_add_notification_assigns_notification_id() {
		$notification = array(
			'type'    => 'task_created',
			'task_id' => $this->task_id,
			'title'   => 'Created',
			'action'  => 'A new task was created',
			'time'    => '2026-03-17 12:00:00',
			'url'     => '#',
		);

		$this->notifications->add_notification_to_user( $this->test_user, $notification );

		$all_notifications = get_user_meta( $this->test_user, 'decker_all_notifications', true );

		$this->assertNotEmpty( $all_notifications[0]['notification_id'] );
	}

	/**
	 * Tests removing a notification by identifier only removes the matching item.
	 */
	public function test_remove_notification_from_user_by_notification_id() {
		$first_notification = array(
			'notification_id' => 'notification-1',
			'type'            => 'task_comment',
			'task_id'         => $this->task_id,
			'title'           => 'Comment 1',
			'action'          => 'First comment',
			'time'            => '2026-03-17 12:00:00',
			'url'             => '#',
		);
		$second_notification = array(
			'notification_id' => 'notification-2',
			'type'            => 'task_comment',
			'task_id'         => $this->task_id,
			'title'           => 'Comment 2',
			'action'          => 'Second comment',
			'time'            => '2026-03-17 12:05:00',
			'url'             => '#',
		);

		update_user_meta(
			$this->test_user,
			'decker_all_notifications',
			array( $first_notification, $second_notification )
		);

		$this->notifications->remove_notification_from_user(
			$this->test_user,
			array(
				'notification_id' => 'notification-1',
			)
		);

		$remaining_notifications = get_user_meta( $this->test_user, 'decker_all_notifications', true );

		$this->assertCount( 1, $remaining_notifications );
		$this->assertSame( 'notification-2', $remaining_notifications[0]['notification_id'] );
	}

	/**
	 * Heartbeat must not touch pending meta for logged-out users.
	 */
	public function test_heartbeat_returns_response_unchanged_for_logged_out_user() {
		update_user_meta(
			$this->test_user,
			'decker_pending_notifications',
			array( array( 'type' => 'task_created' ) )
		);

		wp_set_current_user( 0 );

		$result = $this->notifications->heartbeat_received( array( 'foo' => 'bar' ), array(), null );

		$this->assertSame( array( 'foo' => 'bar' ), $result, 'Anonymous heartbeat should return the response untouched.' );
		$this->assertNotEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'Pending notifications must not be deleted for anonymous heartbeats.'
		);
	}

	/**
	 * Locks the heartbeat payload defaults and icon mappings.
	 */
	public function test_heartbeat_payload_defaults_and_icon_mapping() {
		update_user_meta(
			$this->test_user,
			'decker_pending_notifications',
			array(
				array( 'type' => 'task_comment' ),
				array( 'type' => 'unknown_type' ),
			)
		);

		$result = $this->notifications->heartbeat_received( array(), array(), null );

		$items = $result['decker_notifications'];

		$this->assertSame( 'info', $items[0]['iconColor'], 'task_comment icon color mismatch.' );
		$this->assertSame( 'ri-message-3-line', $items[0]['iconClass'], 'task_comment icon class mismatch.' );
		$this->assertSame( '#', $items[0]['url'], 'url default mismatch.' );
		$this->assertSame( 0, $items[0]['taskId'], 'taskId default mismatch.' );
		$this->assertSame( 'New Notification', $items[0]['title'], 'Heartbeat title default mismatch.' );
		$this->assertSame( '', $items[0]['action'], 'action default mismatch.' );
		$this->assertSame( '', $items[0]['time'], 'time default mismatch.' );
		$this->assertSame( 'task_comment', $items[0]['type'], 'Heartbeat-only type key mismatch.' );

		$this->assertSame( 'primary', $items[1]['iconColor'], 'unknown type icon color default mismatch.' );
		$this->assertSame( 'ri-information-line', $items[1]['iconClass'], 'unknown type icon class default mismatch.' );
	}

	/**
	 * Heartbeat must preserve existing response keys and clear pending only after formatting.
	 */
	public function test_heartbeat_preserves_existing_response_keys() {
		update_user_meta(
			$this->test_user,
			'decker_pending_notifications',
			array( array( 'type' => 'task_created' ) )
		);

		$result = $this->notifications->heartbeat_received( array( 'existing' => 1 ), array(), null );

		$this->assertSame( 1, $result['existing'], 'Existing response key should be preserved.' );
		$this->assertCount( 1, $result['decker_notifications'], 'Should format exactly one notification.' );
		$this->assertEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'Pending notifications should be cleared after formatting.'
		);
	}

	/**
	 * Locks the CURRENT ascending sort + front slice for the AJAX endpoint.
	 */
	public function test_ajax_get_notifications_returns_oldest_first_and_caps_at_15() {
		$notifications = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$notifications[] = array(
				'type'  => 'task_created',
				'title' => 'n' . $i,
				'time'  => sprintf( '2026-01-01 00:00:%02d', $i ),
			);
		}
		update_user_meta( $this->test_user, 'decker_all_notifications', $notifications );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'heartbeat-nonce' );

		ob_start();
		try {
			$this->notifications->ajax_get_decker_notifications();
		} catch ( WPDieException $e ) {
			$e->getMessage();
		}
		$json = json_decode( ob_get_clean(), true );

		$this->assertTrue( $json['success'], 'Response should be successful.' );
		$this->assertCount( 15, $json['data'], 'Should cap at MAX_NOTIFICATIONS.' );
		$this->assertSame( 'n1', $json['data'][0]['title'], 'Oldest notification should be first.' );
		$this->assertSame( 'n15', $json['data'][14]['title'], 'Newest five should be dropped from the front slice.' );
	}

	/**
	 * Locks the AJAX payload shape so a shared formatter cannot silently merge it with the heartbeat one.
	 */
	public function test_ajax_get_notifications_payload_shape_differs_from_heartbeat() {
		update_user_meta(
			$this->test_user,
			'decker_all_notifications',
			array(
				array(
					'type' => 'task_created',
					'time' => '2026-01-01 00:00:01',
				),
			)
		);

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'heartbeat-nonce' );

		ob_start();
		try {
			$this->notifications->ajax_get_decker_notifications();
		} catch ( WPDieException $e ) {
			$e->getMessage();
		}
		$json = json_decode( ob_get_clean(), true );

		$this->assertSame( 'Notification', $json['data'][0]['title'], 'AJAX title default must be "Notification".' );
		$this->assertArrayNotHasKey( 'type', $json['data'][0], 'AJAX payload must not contain a type key.' );
		$this->assertNotEmpty( $json['data'][0]['notificationId'], 'notificationId should be populated.' );
		$this->assertSame( 'primary', $json['data'][0]['iconColor'], 'task_created icon color mismatch.' );
	}

	/**
	 * The AJAX endpoint must require a logged-in user.
	 */
	public function test_ajax_get_notifications_requires_login() {
		wp_set_current_user( 0 );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'heartbeat-nonce' );

		ob_start();
		try {
			$this->notifications->ajax_get_decker_notifications();
		} catch ( WPDieException $e ) {
			$e->getMessage();
		}
		$json = json_decode( ob_get_clean(), true );

		$this->assertFalse( $json['success'], 'Response should fail for logged-out users.' );
		$this->assertSame( 'Not logged in', $json['data'], 'Error message mismatch.' );
	}

	/**
	 * In-app notifications must be stored even when emails are disabled globally.
	 */
	public function test_task_completed_stores_in_app_notification_even_when_email_disabled_globally() {
		update_option( 'decker_settings', array( 'allow_email_notifications' => false ) );

		$other_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test2@example.com',
			)
		);

		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user, $other_user ),
				'stack'          => 'done',
			)
		);

		do_action( 'decker_task_completed', $this->task_id, 'done', $this->test_user );

		$this->assertEmpty( $this->captured_mail, 'No email should be sent when globally disabled.' );

		$pending = get_user_meta( $other_user, 'decker_pending_notifications', true );
		$this->assertNotEmpty( $pending, 'In-app notification should be stored even with email disabled.' );
		$this->assertSame( 'task_completed', $pending[0]['type'], 'Notification type mismatch.' );
	}

	/**
	 * Per-user preference disables email but in-app notification still stored.
	 */
	public function test_task_completed_skips_email_when_user_pref_disabled_but_stores_notification() {
		$other_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test2@example.com',
			)
		);
		update_user_meta(
			$other_user,
			'decker_notification_preferences',
			array( 'notify_completed' => false )
		);

		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user, $other_user ),
				'stack'          => 'done',
			)
		);

		do_action( 'decker_task_completed', $this->task_id, 'done', $this->test_user );

		// The per-user "notify_completed" preference disables only the completion email.
		$completed_mail = array();
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'Task Completed' ) ) {
				$completed_mail[] = $mail;
			}
		}
		$this->assertEmpty( $completed_mail, 'No completion email should be sent when user preference disabled.' );

		// The in-app completion notification must still be stored.
		$pending = get_user_meta( $other_user, 'decker_pending_notifications', true );
		$this->assertNotEmpty( $pending, 'In-app notification should still be stored.' );
		$completed_types = array_filter(
			$pending,
			function ( $notification ) {
				return 'task_completed' === $notification['type'];
			}
		);
		$this->assertNotEmpty( $completed_types, 'A completion in-app notification should be stored.' );
	}

	/**
	 * Unknown finisher must be labelled "Unknown user" in both the action and email body.
	 */
	public function test_task_completed_unknown_finisher_labels_unknown_user() {
		$other_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test2@example.com',
			)
		);
		update_user_meta(
			$other_user,
			'decker_notification_preferences',
			array( 'notify_completed' => true )
		);

		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $other_user ),
				'stack'          => 'done',
			)
		);

		do_action( 'decker_task_completed', $this->task_id, 'done', 999999 );

		// The unknown finisher firing stores a completion notification labelled "Unknown user".
		$pending          = get_user_meta( $other_user, 'decker_pending_notifications', true );
		$has_unknown_note = false;
		foreach ( $pending as $notification ) {
			if ( 'task_completed' === $notification['type'] && str_contains( $notification['action'], 'Unknown user' ) ) {
				$has_unknown_note = true;
			}
		}
		$this->assertTrue( $has_unknown_note, 'Action should label unknown finisher.' );

		// The unknown finisher firing sends a completion email whose body labels "Unknown user".
		$has_unknown_mail = false;
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'Task Completed' ) && str_contains( $mail['message'], 'Unknown user' ) ) {
				$has_unknown_mail = true;
			}
		}
		$this->assertTrue( $has_unknown_mail, 'Email body should label unknown finisher.' );
	}

	/**
	 * No assigned users returns early without email or notification.
	 */
	public function test_task_completed_returns_early_with_no_assigned_users() {
		$new_task_id = $this->factory->task->create();

		do_action( 'decker_task_completed', $new_task_id, 'done', $this->test_user );

		$this->assertEmpty( $this->captured_mail, 'No email should be sent with no assigned users.' );
		$this->assertEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'No notification should be stored with no assigned users.'
		);

		wp_delete_post( $new_task_id, true );
	}

	/**
	 * The comment handler must ignore non-task posts.
	 */
	public function test_new_comment_ignores_non_task_posts() {
		$post_id = $this->factory->post->create();
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'user_id'          => self::factory()->user->create(),
				'comment_approved' => 1,
			)
		);

		$this->assertEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'Non-task comments should not create notifications.'
		);

		$has_comment_mail = false;
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'New Comment' ) ) {
				$has_comment_mail = true;
			}
		}
		$this->assertFalse( $has_comment_mail, 'Non-task comments should not send email.' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * The commenter is skipped while other assignees are notified.
	 */
	public function test_new_comment_skips_commenter_and_notifies_other_assignees() {
		$other_user = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'test2@example.com',
			)
		);

		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user, $other_user ),
			)
		);

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'user_id'          => $this->test_user,
				'comment_approved' => 1,
			)
		);

		$this->assertEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'Commenter should be skipped via strict comparison.'
		);

		$pending      = get_user_meta( $other_user, 'decker_pending_notifications', true );
		$comment_note = null;
		foreach ( $pending as $notification ) {
			if ( 'task_comment' === $notification['type'] ) {
				$comment_note = $notification;
			}
		}
		$this->assertNotNull( $comment_note, 'A comment notification should be stored for the other assignee.' );
		$this->assertStringContainsString( 'Test Task', $comment_note['title'], 'Task title not found in notification.' );

		$comment_recipients = array();
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'New Comment' ) ) {
				$comment_recipients[] = $mail['to'];
			}
		}
		$this->assertContains( 'test2@example.com', $comment_recipients, 'Other assignee must receive a comment email.' );
		$this->assertNotContains( 'test@example.com', $comment_recipients, 'Commenter must not receive a comment email.' );
	}

	/**
	 * Guest comments are labelled "Unknown user" and the guest id does not match assignees.
	 */
	public function test_new_comment_guest_comment_labels_unknown_user() {
		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user ),
			)
		);

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'user_id'          => 0,
				'comment_approved' => 1,
			)
		);

		$pending = get_user_meta( $this->test_user, 'decker_pending_notifications', true );
		$this->assertNotEmpty( $pending, 'Assignee should be notified for a guest comment.' );
		$this->assertStringContainsString( 'Unknown user', $pending[0]['action'], 'Action should label guest author.' );

		$comment_mail = null;
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'New Comment' ) ) {
				$comment_mail = $mail;
			}
		}
		$this->assertNotNull( $comment_mail, 'Comment email expected.' );
		$this->assertSame( 'test@example.com', $comment_mail['to'], 'Recipient mismatch.' );
		$this->assertStringContainsString( 'Unknown user', $comment_mail['message'], 'Email body should label guest author.' );
	}

	/**
	 * Global email disabled skips comment email but still stores in-app notification.
	 */
	public function test_new_comment_email_skipped_when_global_disabled_but_in_app_stored() {
		update_option( 'decker_settings', array( 'allow_email_notifications' => false ) );

		$this->task_id = self::factory()->task->update_object(
			$this->task_id,
			array(
				'assigned_users' => array( $this->test_user ),
			)
		);

		$commenter_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'user_id'          => $commenter_id,
				'comment_approved' => 1,
			)
		);

		$comment_mail = false;
		foreach ( $this->captured_mail as $mail ) {
			if ( str_contains( $mail['subject'], 'New Comment' ) ) {
				$comment_mail = true;
			}
		}
		$this->assertFalse( $comment_mail, 'No comment email should be sent when globally disabled.' );

		$this->assertNotEmpty(
			get_user_meta( $this->test_user, 'decker_pending_notifications', true ),
			'In-app comment notification should still be stored.'
		);
	}

	/**
	 * The notification identifier is a deterministic md5 of the normalized fields.
	 */
	public function test_notification_id_is_deterministic_md5() {
		$notification = array(
			'type'    => 'task_created',
			'task_id' => 7,
			'title'   => 'T',
			'action'  => 'A',
			'time'    => '2026-01-01 00:00:00',
			'url'     => '#',
		);

		$this->notifications->add_notification_to_user( $this->test_user, $notification );

		$all      = get_user_meta( $this->test_user, 'decker_all_notifications', true );
		$expected = md5(
			wp_json_encode(
				array(
					'type'    => 'task_created',
					'task_id' => '7',
					'title'   => 'T',
					'action'  => 'A',
					'time'    => '2026-01-01 00:00:00',
					'url'     => '#',
				)
			)
		);

		$this->assertSame( $expected, $all[0]['notification_id'], 'notificationId must be a stable md5.' );
	}

	/**
	 * Captures emails sent by wp_mail().
	 *
	 * @param array $args Email arguments.
	 *
	 * @return array The same email arguments, unchanged.
	 */
	public function capture_mail( $args ) {
           $this->captured_mail[] = $args; // Change to an array of emails
		return $args;
	}
}
