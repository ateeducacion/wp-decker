<?php
/**
 * Tests for the notification AJAX endpoints and Heartbeat integration.
 *
 * Every endpoint ends in wp_send_json_*(), so the assertions decode the JSON
 * carried by the WPDieException the test base installs. They cover the logged
 * out and unprivileged paths as well as the happy ones, plus the Heartbeat
 * drain that must only deliver each pending notification once.
 *
 * @package Decker
 */

class DeckerNotificationAjaxTest extends Decker_Test_Base {

	/**
	 * The endpoint under test.
	 *
	 * @var Decker_Notification_Ajax
	 */
	private $ajax;

	/**
	 * The backing store.
	 *
	 * @var Decker_Notification_Store
	 */
	private $store;

	/**
	 * Editor used as the current user.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->store = new Decker_Notification_Store();
		$this->ajax  = new Decker_Notification_Ajax( $this->store );

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$_POST = array();
		$this->enable_wp_send_json_capture();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$this->disable_wp_send_json_capture();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Run an endpoint and decode the JSON response it dies with.
	 *
	 * @param string $method The Decker_Notification_Ajax method to call.
	 * @return array The decoded JSON response.
	 */
	private function call_endpoint( string $method ): array {
		ob_start();
		try {
			$this->ajax->{$method}();
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		$decoded = json_decode( ob_get_clean(), true );
		$this->assertIsArray( $decoded, $method . '() should end the request with a JSON response.' );

		return $decoded;
	}

	/**
	 * Build a notification payload.
	 *
	 * @param string $type  Notification type.
	 * @param string $title Notification title.
	 * @param string $time  Notification timestamp.
	 * @param int    $task  Related task id.
	 * @return array The notification.
	 */
	private function notification( string $type, string $title, string $time, int $task = 0 ): array {
		return array(
			'type'    => $type,
			'task_id' => $task,
			'title'   => $title,
			'action'  => 'Test',
			'time'    => $time,
			'url'     => 'https://example.org/task',
		);
	}

	/**
	 * The Heartbeat interval is shortened to 15 seconds.
	 */
	public function test_heartbeat_interval_is_shortened() {
		$settings = $this->ajax->modify_heartbeat_settings( array( 'interval' => 60 ) );

		$this->assertSame( 15, $settings['interval'] );
	}

	/**
	 * Heartbeat delivers pending notifications once and then drains them.
	 */
	public function test_heartbeat_delivers_pending_notifications_once() {
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_assigned', 'You were assigned', '2031-01-01 10:00:00', 7 )
		);

		$response = $this->ajax->heartbeat_received( array(), array(), 'decker' );

		$this->assertCount( 1, $response['decker_notifications'] );
		$delivered = $response['decker_notifications'][0];
		$this->assertSame( 'You were assigned', $delivered['title'] );
		$this->assertSame( 'task_assigned', $delivered['type'] );
		$this->assertSame( 7, $delivered['taskId'] );
		$this->assertSame( 'warning', $delivered['iconColor'] );

		// The pending queue is drained, so the next beat carries nothing.
		$second = $this->ajax->heartbeat_received( array(), array(), 'decker' );
		$this->assertArrayNotHasKey( 'decker_notifications', $second );
	}

	/**
	 * Heartbeat adds nothing for a logged-out visitor.
	 */
	public function test_heartbeat_ignores_logged_out_visitors() {
		wp_set_current_user( 0 );

		$response = $this->ajax->heartbeat_received( array( 'existing' => true ), array(), 'decker' );

		$this->assertSame( array( 'existing' => true ), $response );
	}

	/**
	 * The listing endpoint returns notifications newest first.
	 */
	public function test_listing_returns_notifications_newest_first() {
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_created', 'Older', '2031-01-01 09:00:00' )
		);
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_comment', 'Newer', '2031-01-02 09:00:00' )
		);

		$response = $this->call_endpoint( 'ajax_get_decker_notifications' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( 'Newer', $response['data'][0]['title'] );
		$this->assertSame( 'Older', $response['data'][1]['title'] );
		$this->assertSame( 'info', $response['data'][0]['iconColor'] );
		$this->assertSame( 'ri-message-3-line', $response['data'][0]['iconClass'] );
	}

	/**
	 * The listing endpoint refuses logged-out callers.
	 */
	public function test_listing_refuses_logged_out_callers() {
		wp_set_current_user( 0 );

		$response = $this->call_endpoint( 'ajax_get_decker_notifications' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Not logged in', $response['data'] );
	}

	/**
	 * Clearing removes both the stored and the pending notifications.
	 */
	public function test_clearing_removes_stored_and_pending_notifications() {
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_created', 'Anything', '2031-01-01 09:00:00' )
		);

		$response = $this->call_endpoint( 'ajax_clear_decker_notifications' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array(), $this->store->get_notifications_meta( $this->editor, 'decker_all_notifications' ) );
		$this->assertSame( array(), $this->store->get_notifications_meta( $this->editor, 'decker_pending_notifications' ) );
	}

	/**
	 * Clearing refuses logged-out callers.
	 */
	public function test_clearing_refuses_logged_out_callers() {
		wp_set_current_user( 0 );

		$response = $this->call_endpoint( 'ajax_clear_decker_notifications' );

		$this->assertFalse( $response['success'] );
	}

	/**
	 * Removing a single notification leaves the others in place.
	 */
	public function test_removing_one_notification_keeps_the_rest() {
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_created', 'Keep me', '2031-01-01 09:00:00', 11 )
		);
		$this->store->add_notification_to_user(
			$this->editor,
			$this->notification( 'task_comment', 'Drop me', '2031-01-01 10:00:00', 22 )
		);

		$stored = $this->store->get_notifications_meta( $this->editor, 'decker_all_notifications' );
		$target = null;
		foreach ( $stored as $notification ) {
			if ( 'Drop me' === $notification['title'] ) {
				$target = $notification['notification_id'];
			}
		}
		$this->assertNotNull( $target, 'The stored notification carries a stable id.' );

		$_POST['notification_id'] = $target;

		$response = $this->call_endpoint( 'ajax_remove_decker_notification' );
		$this->assertTrue( $response['success'] );

		$remaining = wp_list_pluck(
			$this->store->get_notifications_meta( $this->editor, 'decker_all_notifications' ),
			'title'
		);
		$this->assertSame( array( 'Keep me' ), $remaining );
	}

	/**
	 * Removing without any identifier is rejected.
	 */
	public function test_removing_without_an_identifier_is_rejected() {
		$response = $this->call_endpoint( 'ajax_remove_decker_notification' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'No valid identifier provided', $response['data'] );
	}

	/**
	 * Removing refuses logged-out callers.
	 */
	public function test_removing_refuses_logged_out_callers() {
		wp_set_current_user( 0 );

		$response = $this->call_endpoint( 'ajax_remove_decker_notification' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Not logged in', $response['data'] );
	}

	/**
	 * Only administrators may broadcast a test notification.
	 */
	public function test_test_notification_requires_manage_options() {
		$_POST['message'] = 'Hello everyone';

		$response = $this->call_endpoint( 'ajax_send_test_notification' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'No permission', $response['data'] );
		$this->assertSame( array(), $this->store->get_notifications_meta( $this->editor, 'decker_all_notifications' ) );
	}

	/**
	 * An administrator cannot broadcast an empty message.
	 */
	public function test_test_notification_rejects_an_empty_message() {
		wp_set_current_user( 1 );

		$response = $this->call_endpoint( 'ajax_send_test_notification' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Message cannot be empty', $response['data'] );
	}

	/**
	 * An administrator can send a test notification to a single user.
	 */
	public function test_test_notification_targets_a_single_user() {
		wp_set_current_user( 1 );

		$_POST['message'] = 'Just for you';
		$_POST['user_id'] = (string) $this->editor;
		$_POST['type']    = 'task_completed';

		$response = $this->call_endpoint( 'ajax_send_test_notification' );
		$this->assertTrue( $response['success'] );

		$stored = $this->store->get_notifications_meta( $this->editor, 'decker_all_notifications' );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'Just for you', $stored[0]['title'] );
		$this->assertSame( 'task_completed', $stored[0]['type'] );
		$this->assertSame( 'Manual Notification', $stored[0]['action'] );

		// Nobody else was notified.
		$this->assertSame( array(), $this->store->get_notifications_meta( 1, 'decker_all_notifications' ) );
	}

	/**
	 * An administrator can broadcast to every user at once.
	 */
	public function test_test_notification_broadcasts_to_every_user() {
		wp_set_current_user( 1 );

		$_POST['message'] = 'Site-wide announcement';

		$response = $this->call_endpoint( 'ajax_send_test_notification' );
		$this->assertTrue( $response['success'] );

		foreach ( array( 1, $this->editor ) as $user_id ) {
			$stored = $this->store->get_notifications_meta( $user_id, 'decker_all_notifications' );
			$this->assertCount( 1, $stored, 'User ' . $user_id . ' should have been notified.' );
			$this->assertSame( 'Site-wide announcement', $stored[0]['title'] );
		}
	}
}
