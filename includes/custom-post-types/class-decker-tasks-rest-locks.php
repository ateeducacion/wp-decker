<?php
/**
 * REST transport for task edit locks.
 *
 * Registers the lock endpoints (read, acquire, takeover, release) and keeps
 * a lock alive through the WordPress heartbeat. Reaches the shared lock
 * manager through the injected Decker_Tasks instance.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Locks
 */
class Decker_Tasks_Rest_Locks {

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
		// Keep the editor's task lock alive through the WordPress heartbeat.
		add_filter( 'heartbeat_received', array( $this, 'refresh_task_lock_heartbeat' ), 10, 2 );
	}

	/**
	 * Register the task edit-lock REST routes.
	 */
	public function register_routes() {
		Decker_Tasks_Rest_Support::register_routes( 'decker/v1', $this->get_task_lock_route_definitions(), $this );
	}

	/**
	 * Get the REST route definitions for the task edit-lock endpoints.
	 *
	 * @return array<int, array<string, mixed>> The lock route definitions.
	 */
	private function get_task_lock_route_definitions(): array {
		return array(
			array(
				'route'      => '/tasks/(?P<id>\d+)/lock',
				'methods'    => 'GET',
				'callback'   => 'handle_get_task_lock',
				'permission' => 'edit_posts',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/lock',
				'methods'    => 'POST',
				'callback'   => 'handle_acquire_task_lock',
				'permission' => 'edit_posts',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/lock',
				'methods'    => 'DELETE',
				'callback'   => 'handle_release_task_lock',
				'permission' => 'edit_posts',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/lock/takeover',
				'methods'    => 'POST',
				'callback'   => 'handle_takeover_task_lock',
				'permission' => 'edit_posts',
			),
		);
	}

	/**
	 * Validate a task lock REST request (task exists and user may edit it).
	 *
	 * @param int $task_id The task post ID.
	 * @return WP_REST_Response|null An error response, or null when the request is valid.
	 */
	private function validate_task_lock_request( int $task_id ) {
		$post = get_post( $task_id );

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

		if ( ! current_user_can( 'edit_post', $task_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'decker_task_cannot_edit',
					'message' => __( 'You are not allowed to edit this card.', 'decker' ),
				),
				403
			);
		}

		return null;
	}

	/**
	 * Handle the REST request that returns the current lock state of a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_get_task_lock( $request ) {
		$task_id = (int) $request['id'];

		$error = $this->validate_task_lock_request( $task_id );
		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		$info = $this->tasks->get_task_locks()->get_lock_info( $task_id, get_current_user_id() );

		return new WP_REST_Response( $info, 200 );
	}

	/**
	 * Handle the REST request that acquires or refreshes a task lock.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_acquire_task_lock( $request ) {
		$task_id = (int) $request['id'];

		$error = $this->validate_task_lock_request( $task_id );
		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		$info = $this->tasks->get_task_locks()->acquire_lock( $task_id, get_current_user_id() );
		if ( is_wp_error( $info ) ) {
			return Decker_Tasks_Rest_Support::error_response( $info );
		}

		// The task is held by another active user; report the conflict.
		$status = $info['locked'] ? 409 : 200;

		return new WP_REST_Response( $info, $status );
	}

	/**
	 * Handle the REST request that explicitly takes over a task lock.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_takeover_task_lock( $request ) {
		$task_id = (int) $request['id'];

		$error = $this->validate_task_lock_request( $task_id );
		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		$info = $this->tasks->get_task_locks()->take_over_lock( $task_id, get_current_user_id() );
		if ( is_wp_error( $info ) ) {
			return Decker_Tasks_Rest_Support::error_response( $info );
		}

		return new WP_REST_Response( $info, 200 );
	}

	/**
	 * Handle the REST request that releases a task lock owned by the current user.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_release_task_lock( $request ) {
		$task_id = (int) $request['id'];

		$error = $this->validate_task_lock_request( $task_id );
		if ( $error instanceof WP_REST_Response ) {
			return $error;
		}

		$generation = $request->get_param( 'lock_generation' );
		$released   = $this->tasks->get_task_locks()->release_lock(
			$task_id,
			get_current_user_id(),
			is_string( $generation ) ? $generation : ''
		);

		return new WP_REST_Response( array( 'released' => $released ), 200 );
	}

	/**
	 * Refresh the current user's task lock during a WordPress heartbeat.
	 *
	 * The front-end sends the id of the task open in edit mode plus the session
	 * generation embedded in its form. The lock is only refreshed when the user
	 * still owns that exact session; a heartbeat never re-acquires a released
	 * lock, so a previous editor cannot be re-authorized after a takeover. When
	 * the session no longer matches, the payload reports the loss so the editor
	 * can block further saves.
	 *
	 * @param array $response The heartbeat response.
	 * @param array $data     The data received from the client.
	 * @return array The augmented heartbeat response.
	 */
	public function refresh_task_lock_heartbeat( $response, $data ) {
		if ( empty( $data['decker_task_lock']['post_id'] ) ) {
			return $response;
		}

		$task_id = absint( $data['decker_task_lock']['post_id'] );
		if ( ! $task_id ) {
			return $response;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'edit_post', $task_id ) ) {
			return $response;
		}

		$session_generation = isset( $data['decker_task_lock']['generation'] )
			? sanitize_text_field( wp_unslash( $data['decker_task_lock']['generation'] ) )
			: '';

		$info = $this->tasks->get_task_locks()->refresh_lock( $task_id, $user_id, $session_generation );
		if ( is_wp_error( $info ) ) {
			return $response;
		}
		$info['request_generation'] = $session_generation;

		$response['decker_task_lock'] = $info;

		return $response;
	}
}
