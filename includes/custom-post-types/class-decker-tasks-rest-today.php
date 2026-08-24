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

		// Identity is derived from the session; a client-supplied user is refused.
		// This endpoint is the strict one: the older relation routes below still
		// accept (and ignore) the `user_id` their board-card client sends.
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

		$rejection = $this->reject_ineligible_task( $task_id );
		if ( $rejection instanceof WP_REST_Response ) {
			return $rejection;
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
		return $this->write_relation( (int) $request['id'], true, 'Relation marked successfully.' );
	}

	/**
	 * Unmark a user-date relation for a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function unmark_user_date_relation( $request ) {
		return $this->write_relation( (int) $request['id'], false, 'Relation unmarked successfully.' );
	}

	/**
	 * Apply the current user's "For today" relation through a relation route.
	 *
	 * The relation is personal: the authenticated user is always the subject and
	 * any client-supplied `user_id` is ignored. A failed write is reported as
	 * such — answering `success: true` while nothing was stored leaves the card
	 * showing a relation that disappears on the next reload.
	 *
	 * @param int    $task_id The task post ID.
	 * @param bool   $marked  The relation state to apply.
	 * @param string $message The success message for this route.
	 * @return WP_REST_Response The REST response.
	 */
	private function write_relation( int $task_id, bool $marked, string $message ): WP_REST_Response {
		$rejection = $this->reject_ineligible_task( $task_id );
		if ( $rejection instanceof WP_REST_Response ) {
			return $rejection;
		}

		$result = $this->tasks->get_today_manager()->set_today_state( $task_id, get_current_user_id(), $marked );

		if ( is_wp_error( $result ) ) {
			return Decker_Tasks_Rest_Support::error_response( $result );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
			),
			200
		);
	}

	/**
	 * Reject a task that cannot carry a "For today" relation.
	 *
	 * Shared by every quick-action route so the board card and the open card
	 * agree on which tasks qualify, instead of the older relation routes
	 * accepting archived and non-existent tasks.
	 *
	 * @param int $task_id The task post ID.
	 * @return WP_REST_Response|null The rejection, or null when the task qualifies.
	 */
	private function reject_ineligible_task( int $task_id ) {
		$post = $task_id > 0 ? get_post( $task_id ) : null;

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

		return null;
	}
}
