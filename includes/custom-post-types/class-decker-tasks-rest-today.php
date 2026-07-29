<?php
/**
 * REST transport for the "For today" task relation.
 *
 * Registers the mark/unmark quick-action routes and the today toggle route,
 * and reaches the shared relation service through the injected Decker_Tasks
 * instance.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Today
 */
class Decker_Tasks_Rest_Today {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * Constructor.
	 *
	 * @param Decker_Tasks $tasks The task post type coordinator.
	 */
	public function __construct( Decker_Tasks $tasks ) {
		$this->tasks = $tasks;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the "For today" REST routes.
	 */
	public function register_routes() {
		$routes = array(
			array(
				'route'      => '/tasks/(?P<id>\d+)/mark_relation',
				'methods'    => 'POST',
				'callback'   => 'mark_user_date_relation',
				'permission' => 'minimum_role',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/unmark_relation',
				'methods'    => 'POST',
				'callback'   => 'unmark_user_date_relation',
				'permission' => 'minimum_role',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/today',
				'methods'    => 'PUT',
				'callback'   => 'handle_task_today',
				'permission' => 'edit_task',
				'args'       => array(
					'marked' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			),
		);

		Decker_Tasks_Rest_Support::register_routes( 'decker/v1', $routes, $this );
	}

	/**
	 * Handle the "For today" quick action for the current user.
	 *
	 * Changes only the authenticated user's current-day relation. It never
	 * touches shared task fields, the task edit lock, or another user's data.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_task_today( $request ) {
		$task_id = (int) $request['id'];
		$post    = get_post( $task_id );

		if ( ! $post || 'decker_task' !== $post->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'decker_invalid_task',
					'message' => __( 'Task not found.', 'decker' ),
				),
				404
			);
		}

		// Identity is derived from the session; a client-supplied user is refused.
		if ( null !== $request->get_param( 'user_id' ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'decker_unexpected_identity',
					'message' => __( 'The user is derived from the session and cannot be provided.', 'decker' ),
				),
				400
			);
		}

		if ( 'archived' === $post->post_status ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'decker_task_archived',
					'message' => __( 'This task is archived and cannot be marked for today.', 'decker' ),
				),
				409
			);
		}

		$marked = (bool) $request->get_param( 'marked' );
		$result = $this->tasks->get_today_manager()->set_today_state( $task_id, get_current_user_id(), $marked );

		if ( is_wp_error( $result ) ) {
			return Decker_Tasks_Rest_Support::error_response( $result );
		}

		return new WP_REST_Response(
			array_merge(
				array(
					'success' => true,
					'message' => $this->today_result_message( $result['marked'], $result['changed'] ),
				),
				$result
			),
			200
		);
	}

	/**
	 * Build the human-readable message for a today quick-action result.
	 *
	 * @param bool $marked  The resulting marked state.
	 * @param bool $changed Whether the state actually changed.
	 * @return string The translated message.
	 */
	private function today_result_message( bool $marked, bool $changed ): string {
		if ( $marked ) {
			return $changed
				? __( 'Task added to today.', 'decker' )
				: __( 'Task is already marked for today.', 'decker' );
		}

		return $changed
			? __( 'Task removed from today.', 'decker' )
			: __( 'Task is not marked for today.', 'decker' );
	}

	/**
	 * Mark a user-date relation for a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function mark_user_date_relation( $request ) {
		$task_id = (int) $request['id'];

		if ( ! $task_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid parameters.',
				),
				400
			);
		}

		// The relation is personal: always use the authenticated current user
		// and ignore any client-supplied user_id.
		$this->tasks->get_today_manager()->mark_for_today( $task_id, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Relation marked successfully.',
			),
			200
		);
	}

	/**
	 * Unmark a user-date relation for a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function unmark_user_date_relation( $request ) {
		$task_id = (int) $request['id'];

		if ( ! $task_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid parameters.',
				),
				400
			);
		}

		// The relation is personal: always use the authenticated current user
		// and ignore any client-supplied user_id.
		$this->tasks->get_today_manager()->unmark_for_today( $task_id, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Relation unmarked successfully.',
			),
			200
		);
	}
}
