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
	 * Each definition needs `route`, `methods`, `permission` and `callback`,
	 * and may include `args`. `callback` is normally a method name on the
	 * owning controller, resolved as `array( $handler, $definition['callback'] )`;
	 * a definition may instead give the resolved `array( $object, $method )`
	 * callable directly, for the rare route that dispatches elsewhere (the
	 * three Decker_Tasks_Rest_Ops rows that route to the order engine via
	 * Decker_Tasks::get_order_engine()).
	 *
	 * @param string            $namespace   The REST namespace, e.g. 'decker/v1'.
	 * @param array<int, array> $definitions The route definitions.
	 * @param object            $handler     The controller whose methods `callback` names resolve against.
	 * @return void
	 */
	public static function register_routes( string $namespace, array $definitions, $handler ) {
		foreach ( $definitions as $definition ) {
			$callback = is_array( $definition['callback'] )
				? $definition['callback']
				: array( $handler, $definition['callback'] );

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
	 * Every route registered here backs a Decker screen, so every one of them
	 * requires the configured minimum role as well as whatever else it asks
	 * for. A capability on its own is weaker than the interface: an author may
	 * edit their own published posts, so `edit_task` alone would let an
	 * account that cannot open Decker reorder a card of its own and shift
	 * everybody else's `menu_order` with it, and `edit_posts` alone would let
	 * it read through the search and lock routes. The abilities answer to the
	 * same gate, so the two surfaces an agent can reach cannot disagree about
	 * who is allowed in.
	 *
	 * @param string $permission The permission key ('minimum_role' or a capability slug).
	 * @return callable The permission callback.
	 */
	public static function permission_callback( string $permission ): callable {
		$specific = self::specific_permission_callback( $permission );

		return function ( $request ) use ( $specific ) {
			if ( ! Decker::current_user_has_at_least_minimum_role() ) {
				return false;
			}

			return $specific( $request );
		};
	}

	/**
	 * Build the route's own permission check, without the minimum-role gate.
	 *
	 * @param string $permission The permission key ('minimum_role' or a capability slug).
	 * @return callable The permission callback.
	 */
	private static function specific_permission_callback( string $permission ): callable {
		if ( 'minimum_role' === $permission ) {
			return function () {
				return true;
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
