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

		// A route that asks for manage_options is already stricter than the
		// gate, and the gate only knows the five core role slugs: wrapping it
		// would refuse a site administrator whose capability comes from a
		// custom role, or a network administrator with no role on the subsite,
		// on the board repair route they are the only ones allowed to call.
		if ( 'manage_options' === $permission ) {
			return $specific;
		}

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
	/**
	 * Restrict REST access to tasks, in both the generic and the Decker namespace.
	 *
	 * @param mixed           $result The pre-calculated result to return.
	 * @param WP_REST_Server  $rest_server The REST server instance.
	 * @param WP_REST_Request $request The current REST request.
	 * @return mixed WP_Error if unauthorized, otherwise the original result.
	 */
	public static function restrict_rest_access( $result, $rest_server, $request ) {
		$route   = $request->get_route();
		$generic = Decker::rest_route_matches( $route, '/wp/v2/tasks' );

		if ( ! $generic && ! Decker::rest_route_matches( $route, '/decker/v1/tasks' ) ) {
			return $result;
		}

		// Use the specific capability of the CPT. The decker/v1 routes carry
		// their own permission callbacks, which answer to the configured
		// minimum role rather than to edit_posts, so this one stays where it
		// has always applied.
		if ( $generic && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		// A hidden task is reachable only by the people it is not hidden from,
		// whatever the capability. The route carries the task being addressed.
		//
		// Both namespaces are covered here rather than in each permission
		// callback: edit_task resolves to edit_post, which an editor holds for
		// other people's posts, so the per-task routes would otherwise let
		// somebody who cannot see a hidden card still act on it by id.
		if ( preg_match( '#^/(?:wp/v2|decker/v1)/tasks/(?P<id>\d+)#i', $route, $matches )
			&& self::is_hidden_from_current_user( (int) $matches['id'] ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this resource.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}

	/**
	 * Keep hidden tasks out of the REST collection.
	 *
	 * The per-task rule depends on the author, the responsible user and the
	 * assignees, which cannot be expressed as a single SQL condition, so the
	 * listing simply drops hidden tasks for everyone but administrators. The
	 * people a hidden task belongs to still reach it through its own route, and
	 * the Decker screens build their listings with their own queries rather than
	 * this endpoint.
	 *
	 * The restriction is applied to the query arguments, which WP_Query includes
	 * in its cache key, so a listing cached for one user is never replayed to
	 * another with different visibility.
	 *
	 * @param array           $args    Query arguments for the collection.
	 * @param WP_REST_Request $request The current REST request (unused).
	 * @return array The possibly restricted arguments.
	 */
	public static function exclude_hidden_tasks_from_rest_collection( $args, $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return $args;
		}

		$meta_query   = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => 'hidden',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => 'hidden',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $args;
	}

	/**
	 * Determine whether a hidden task must be concealed from the current user.
	 *
	 * A task flagged `hidden` stays visible to the people it concerns: its
	 * author, the responsible user, its assignees and administrators. Everyone
	 * else is refused, whatever capabilities they hold. A task that is not
	 * flagged hidden is never concealed.
	 *
	 * @param int $task_id Task to check.
	 * @return bool True when the task must be concealed.
	 */
	public static function is_hidden_from_current_user( $task_id ) {
		$task_id = (int) $task_id;

		if ( ! $task_id || 'decker_task' !== get_post_type( $task_id ) ) {
			return false;
		}

		if ( ! get_post_meta( $task_id, 'hidden', true ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return true;
		}

		if ( (int) get_post_field( 'post_author', $task_id ) === $user_id ) {
			return false;
		}

		if ( absint( get_post_meta( $task_id, 'responsable', true ) ) === $user_id ) {
			return false;
		}

		$assignee_ids = get_post_meta( $task_id, 'assigned_users', true );
		$assignee_ids = is_array( $assignee_ids ) ? array_map( 'absint', $assignee_ids ) : array();

		return ! in_array( $user_id, $assignee_ids, true );
	}
}
