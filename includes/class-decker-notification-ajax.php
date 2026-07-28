<?php
/**
 * Serves a user's Decker notifications to the browser.
 *
 * Owns the Heartbeat integration and the AJAX endpoints the notification
 * panel calls; persistence and formatting are delegated to the store.
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Notification_Ajax
 */
class Decker_Notification_Ajax {

	/**
	 * The notification store.
	 *
	 * @var Decker_Notification_Store
	 */
	private $store;

	/**
	 * Hook the Heartbeat filters and the AJAX endpoints.
	 *
	 * @param Decker_Notification_Store $store The notification store.
	 */
	public function __construct( Decker_Notification_Store $store ) {
		$this->store = $store;

		// Heartbeat hook.
		add_filter( 'heartbeat_received', array( $this, 'heartbeat_received' ), 10, 3 );

		add_filter( 'heartbeat_settings', array( $this, 'modify_heartbeat_settings' ), 10, 1 );

		// AJAX hooks.
		add_action( 'wp_ajax_get_decker_notifications', array( $this, 'ajax_get_decker_notifications' ) );
		add_action( 'wp_ajax_clear_decker_notifications', array( $this, 'ajax_clear_decker_notifications' ) );
		add_action( 'wp_ajax_remove_decker_notification', array( $this, 'ajax_remove_decker_notification' ) );
		add_action( 'wp_ajax_send_test_notification', array( $this, 'ajax_send_test_notification' ) );
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
		$settings['interval'] = 15; // Changed to 15 seconds.
		return $settings;
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

		$pending = $this->store->get_notifications_meta( $user_id, 'decker_pending_notifications' );
		if ( empty( $pending ) ) {
			return $response;
		}

		$response['decker_notifications'] = array();

		foreach ( $pending as $notification ) {
			// Prepare data for JS.
			$response['decker_notifications'][] = $this->store->format_notification_for_heartbeat( $notification );
		}

		// Clear pending after sending them once.
		delete_user_meta( $user_id, 'decker_pending_notifications' );

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

		$all_notifications = $this->store->get_notifications_meta( $user_id, 'decker_all_notifications' );

		// Reverse so newest is at the front.
		usort(
			$all_notifications,
			function ( $a, $b ) {
				return strtotime( $a['time'] ) - strtotime( $b['time'] );
			}
		);
		// Return only the last 15 (most recent first).
		$last_notifications = array_slice( $all_notifications, 0, Decker_Notification_Store::MAX_NOTIFICATIONS );

		// Map them to the same structure used in JS.
		$formatted = array();
		foreach ( $last_notifications as $notification ) {
			$formatted[] = $this->store->format_notification_for_client( $notification, 'Notification' );
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
	 * AJAX: Remove one notification that has a matching task_id.
	 */
	public function ajax_remove_decker_notification() {
		check_ajax_referer( 'heartbeat-nonce', false, false ); // Optional, adjust if needed.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( 'Not logged in' );
		}

		$task_id         = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
		$type            = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$notification_id = isset( $_POST['notification_id'] )
			? sanitize_text_field( wp_unslash( $_POST['notification_id'] ) )
			: '';

		if ( ! $task_id && ! $type && ! $notification_id ) {
			wp_send_json_error( 'No valid identifier provided' );
		}

		$notification_to_remove = array(
			'type'            => $type,
			'task_id'         => $task_id,
			'notification_id' => $notification_id,
		);
		$this->store->remove_notification_from_user( $user_id, $notification_to_remove );

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
			$this->store->add_notification_to_user(
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
}
