<?php
/**
 * Shared task visibility and REST access policy.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Keep object visibility consistent across REST, comments and Decker views. */
class Decker_Task_Visibility {
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
