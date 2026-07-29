<?php
/**
 * Shared REST helpers for the task resource controllers.
 *
 * Builds the permission callback used by every task REST route and formats
 * a WP_Error as the WP_REST_Response shape the task endpoints return. Kept
 * static and stateless so each resource controller can call it without an
 * instance.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Support
 */
class Decker_Tasks_Rest_Support {

	/**
	 * Build the permission callback closure for a given permission type.
	 *
	 * @param string $permission The permission key ('minimum_role' or a capability slug).
	 * @return callable The permission callback.
	 */
	public static function permission_callback( string $permission ): callable {
		if ( 'minimum_role' === $permission ) {
			return function () {
				return Decker::current_user_has_at_least_minimum_role();
			};
		}

		// Object-level capability for a single task. A missing task passes the
		// authenticated check so the callback can return a 404 instead of a 403.
		if ( 'edit_task' === $permission ) {
			return function ( $request ) {
				$task_id = isset( $request['id'] ) ? (int) $request['id'] : 0;
				$post    = $task_id ? get_post( $task_id ) : null;

				if ( ! $post || 'decker_task' !== $post->post_type ) {
					return is_user_logged_in();
				}

				return current_user_can( 'edit_post', $task_id );
			};
		}

		return function () use ( $permission ) {
			return current_user_can( $permission );
		};
	}

	/**
	 * Convert a lock WP_Error into a REST response with the proper status code.
	 *
	 * @param WP_Error $error The lock error.
	 * @return WP_REST_Response The error response.
	 */
	public static function error_response( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
	}
}
