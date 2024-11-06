<?php
/**
 * Push Notifications class
 *
 * @package    Decker
 * @subpackage Decker/includes/notifications
 */

/**
 * Send OneSignal notification.
 *
 * @param int $user_id The user ID.
 * @param int $task_id The task ID.
 */
function send_onesignal_notification( $user_id, $task_id ) {
	$onesignal_app_id = 'YOUR_ONESIGNAL_APP_ID';
	$onesignal_rest_api_key = 'YOUR_ONESIGNAL_REST_API_KEY';

	$user = get_userdata( $user_id );
	$task = get_post( $task_id );
	$title = 'Nueva tarea asignada';
	$message = 'Se te ha asignado una nueva tarea: ' . $task->post_title;

	$fields = array(
		'app_id' => $onesignal_app_id,
		'include_external_user_ids' => array( $user->user_login ),
		'headings' => array( 'en' => $title ),
		'contents' => array( 'en' => $message ),
		'url' => get_permalink( $task_id ),
	);

	$fields = json_encode( $fields );

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications' );
	curl_setopt(
		$ch,
		CURLOPT_HTTPHEADER,
		array(
			'Content-Type: application/json; charset=utf-8',
			'Authorization: Basic ' . $onesignal_rest_api_key,
		)
	);
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_HEADER, false );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

	$response = curl_exec( $ch );
	curl_close( $ch );

	return $response;
}

/**
 * Notify user on task assignment.
 *
 * @param int $post_id The post ID.
 */
function notify_user_on_task_assignment( $post_id ) {
	if ( get_post_type( $post_id ) !== 'task' ) {
		return;
	}

	$user_id = get_post_meta( $post_id, 'task_user', true );
	if ( $user_id ) {
		send_onesignal_notification( $user_id, $post_id );
	}
}

add_action( 'save_post', 'notify_user_on_task_assignment', 10, 1 );
