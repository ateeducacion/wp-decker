<?php
/**
 * Shared REST helpers for the task resource controllers.
 *
 * Builds the permission callback used by every task REST route, formats a
 * WP_Error as the WP_REST_Response shape the task endpoints return, and
 * registers a controller's route definitions against the REST server. Kept
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
	 * Register a controller's REST route definitions against a namespace.
	 *
	 * Each definition needs `route`, `methods`, `callback` (a method name)
	 * and `permission`, and may include `args`. The callback is resolved
	 * against $handler_resolver: an object binds `array( $handler_resolver,
	 * $definition['callback'] )` directly; a Closure instead receives the
	 * callback name and returns the resolved callable, for the rare route
	 * that can dispatch to more than one class (Decker_Tasks_Rest_Ops still
	 * routes two callbacks to Decker_Tasks until the order engine moves).
	 *
	 * @param string            $namespace        The REST namespace, e.g. 'decker/v1'.
	 * @param array<int, array> $definitions      The route definitions.
	 * @param object|Closure    $handler_resolver The controller object, or a Closure( string $callback ): callable.
	 * @return void
	 */
	public static function register_routes( string $namespace, array $definitions, $handler_resolver ) {
		foreach ( $definitions as $definition ) {
			$callback = ( $handler_resolver instanceof Closure )
				? $handler_resolver( $definition['callback'] )
				: array( $handler_resolver, $definition['callback'] );

			$route_args = array(
				'methods'             => $definition['methods'],
				'callback'            => $callback,
				'permission_callback' => self::permission_callback( $definition['permission'] ),
			);

			if ( isset( $definition['args'] ) ) {
				$route_args['args'] = $definition['args'];
			}

			register_rest_route( $namespace, $definition['route'], $route_args );
		}
	}

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
