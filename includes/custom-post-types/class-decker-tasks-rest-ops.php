<?php
/**
 * REST transport for task field operations.
 *
 * Registers the assignment, due-date, stack/order and fix-order routes, and
 * guards generic /wp/v2/tasks writes with the task edit lock. The order
 * routes give the resolved callable on the order engine reached via the
 * injected Decker_Tasks instance's get_order_engine() accessor.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Ops
 */
class Decker_Tasks_Rest_Ops {

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
		// Enforce the edit lock (detect-and-reject) on generic /wp/v2/tasks
		// updates, which bypass the save_decker_task guard.
		//
		// Deletions are deliberately NOT covered. `rest_pre_insert_decker_task`
		// does not run for DELETE, and that is the behaviour we want: removing a
		// card is allowed even while other people have it open. Deleting is a
		// decision about whether the card should exist at all, not an edit that
		// could silently overwrite someone's newer text, so it is not the edit
		// lock's business. Do not "close this gap" by guarding delete as well.
		add_filter( 'rest_pre_insert_decker_task', array( $this, 'guard_rest_task_update' ), 10, 2 );
		// A generic REST update that writes content supersedes every open form, so
		// rotate the generation afterwards exactly like an AJAX save does.
		add_action( 'rest_after_insert_decker_task', array( $this, 'rotate_generation_after_rest_update' ), 10, 3 );
	}

	/**
	 * Register the task field-operation REST routes.
	 */
	public function register_routes() {
		$order_args = array(
			'board_id'      => array( 'required' => true ),
			'source_stack'  => array( 'required' => true ),
			'target_stack'  => array( 'required' => true ),
			'source_order'  => array( 'required' => true ),
			'target_order'  => array( 'required' => true ),
		);

		// The order and fix-order rows give the resolved callable on the order
		// engine reached via $this->tasks->get_order_engine(), instead of a
		// method name on this controller.
		$order_engine = $this->tasks->get_order_engine();
		$routes       = array(
			array(
				'route'      => '/tasks/(?P<id>\d+)/order',
				'methods'    => 'PUT',
				'callback'   => array( $order_engine, 'update_task_stack_and_order' ),
				'permission' => 'minimum_role',
				'args'       => $order_args,
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/stack',
				'methods'    => 'PUT',
				'callback'   => array( $order_engine, 'update_task_stack_and_order' ),
				'permission' => 'minimum_role',
				'args'       => $order_args,
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/leave',
				'methods'    => 'POST',
				'callback'   => 'remove_user_from_task',
				'permission' => 'minimum_role',
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/assign',
				'methods'    => 'POST',
				'callback'   => 'assign_user_to_task',
				'permission' => 'minimum_role',
			),
			array(
				'route'      => '/fix-order/(?P<board_id>\d+)',
				'methods'    => 'POST',
				'callback'   => array( $order_engine, 'handle_fix_order' ),
				'permission' => 'manage_options',
			),
			// Dragging a task in the calendar view calls this; it is an ordinary
			// per-task edit, so it takes the same object-level capability as the
			// other task routes rather than an administrator-only one.
			array(
				'route'      => '/tasks/(?P<id>\d+)/update_due_date',
				'methods'    => 'POST',
				'callback'   => 'update_task_due_date',
				'permission' => 'edit_task',
			),
		);

		Decker_Tasks_Rest_Support::register_routes( 'decker/v1', $routes, $this );
	}

	/**
	 * Assign a user to a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function assign_user_to_task( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );

		if ( ! $task_id || ! $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid parameters.',
				),
				400
			);
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Task not found.',
				),
				404
			);
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( ! is_array( $assigned_users ) ) {
			if ( is_scalar( $assigned_users ) ) { // If it's a unique value (integer, string, etc.).
				$assigned_users = array( $assigned_users );
			} else {
				$assigned_users = array(); // If it's another type (null, invalid object, etc.).
			}
		}

		if ( ! in_array( $user_id, $assigned_users ) ) {
			$assigned_users[] = $user_id;
			update_post_meta( $task_id, 'assigned_users', $assigned_users );

			// Trigger a hook after a user has been assigned.
			do_action( 'decker_user_assigned', $task_id, $user_id );

			// Assignees changed: invalidate any open editor form.
			$this->tasks->get_task_locks()->invalidate_sessions( $task_id );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'User assigned successfully.',
			),
			200
		);
	}

	/**
	 * Remove a user from a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function remove_user_from_task( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );

		if ( ! $task_id || ! $user_id ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Invalid parameters.',
				),
				400
			);
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Task not found.',
				),
				404
			);
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( is_array( $assigned_users ) && in_array( $user_id, $assigned_users ) ) {
			$assigned_users = array_diff( $assigned_users, array( $user_id ) );
			update_post_meta( $task_id, 'assigned_users', $assigned_users );

			// Assignees changed: invalidate any open editor form.
			$this->tasks->get_task_locks()->invalidate_sessions( $task_id );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'User removed successfully.',
			),
			200
		);
	}

	/**
	 * Handle update the due date of task using REST API.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function update_task_due_date( WP_REST_Request $request ) {

		$task_id = $request->get_param( 'id' );

		// Check if the task exists, if not return error response.

		if ( ! get_post( $task_id ) || get_post_type( $task_id ) !== 'decker_task' ) {
			return new WP_REST_Response(
				array(
					'error' => 'Invalid event ID',
				),
				404
			);
		}

		$meta_fields = array(
			'duedate' => 'sanitize_text_field',
		);

		 // Update event in WP.
		$updated_meta = array();

		 // Loop through meta fields and update if present.
		foreach ( $meta_fields as $key => $sanitize_callback ) {
			if ( $request->has_param( $key ) ) {
				 $value = call_user_func( $sanitize_callback, $request->get_param( $key ) );
				 update_post_meta( $task_id, $key, $value );
				 $updated_meta[ $key ] = $value;
			}
		}

		do_action( 'decker_task_updated', $task_id ); // Invalidates .ics “all”.

		if ( ! empty( $updated_meta ) ) {
			// The due date changed: invalidate any open editor form.
			$this->tasks->get_task_locks()->invalidate_sessions( $task_id );
		}

		 // Step 4: Return response.
		return new WP_REST_Response(
			array(
				'message' => 'Event meta updated successfully',
				'updated_meta' => $updated_meta,
			),
			200
		);
	}

	/**
	 * Request parameters that cannot carry task content.
	 *
	 * `status` is the payload of the archive/unarchive quick action; the rest is
	 * REST plumbing. The list is deliberately closed: any other parameter (title,
	 * content, excerpt, meta, taxonomies) could have come from a stale form, so
	 * an unrecognised one keeps the generation requirement rather than skipping
	 * it. Parameters prefixed with `_` are core's own plumbing and are ignored
	 * separately.
	 *
	 * @var string[]
	 */
	private const CONTENTLESS_PARAMS = array( 'id', 'status', 'context', 'lock_generation' );

	/**
	 * Enforce the edit lock on generic REST updates of an existing task.
	 *
	 * `decker_task` is writable through `/wp/v2/tasks/{id}` (title, content and
	 * registered meta), which bypasses the lock guard in save_decker_task. This
	 * rejects an update while another user owns the active lock and requires a
	 * valid `lock_generation` once the task carries one (detect-and-reject).
	 * Creates (no existing id) and never-locked tasks remain updatable over REST.
	 *
	 * A content-less update is exempt from the generation requirement: archiving
	 * posts a bare status transition from a card that has no form open, so it can
	 * never present a token, and having none is not evidence of staleness when
	 * there is no content to overwrite. An active lock held by someone else still
	 * blocks it — archiving a card out from under its editor is a real conflict.
	 *
	 * @param stdClass        $prepared_post The prepared post for insertion.
	 * @param WP_REST_Request $request       The REST request.
	 * @return stdClass|WP_Error The prepared post, or a 409 error when locked.
	 */
	public function guard_rest_task_update( $prepared_post, $request ) {
		if ( empty( $prepared_post->ID ) ) {
			return $prepared_post;
		}

		$generation = $request->get_param( 'lock_generation' );
		$check      = $this->tasks->get_task_locks()->assert_user_can_save(
			(int) $prepared_post->ID,
			get_current_user_id(),
			is_string( $generation ) ? $generation : null,
			$this->writes_task_content( $request )
		);

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return $prepared_post;
	}

	/**
	 * Determine whether a REST update submits content a stale form could clobber.
	 *
	 * Query parameters count too: `POST /wp/v2/tasks/{id}?title=...` reaches
	 * `prepare_item_for_database()` exactly like a body parameter would.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return bool True when the request carries anything beyond a status transition.
	 */
	private function writes_task_content( $request ): bool {
		foreach ( array_keys( $request->get_params() ) as $param ) {
			if ( str_starts_with( (string) $param, '_' ) ) {
				continue;
			}

			if ( ! in_array( $param, self::CONTENTLESS_PARAMS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Invalidate open editing sessions after a generic REST update.
	 *
	 * `guard_rest_task_update()` only validates the submitted generation before
	 * the write. Without this the token stays reusable, so a stale form (or a
	 * second REST client of the same user) could still overwrite the update.
	 *
	 * @param WP_Post         $post     The task that was written.
	 * @param WP_REST_Request $request  The REST request.
	 * @param bool            $creating Whether the post was just created.
	 * @return void
	 */
	public function rotate_generation_after_rest_update( $post, $request, $creating ) {
		if ( $creating || ! $post instanceof WP_Post ) {
			return;
		}

		// A content-less update (the archive quick action) supersedes nothing an
		// open form holds, so the editor keeps their session instead of being
		// forced to reload by a passing archive.
		if ( ! $this->writes_task_content( $request ) ) {
			return;
		}

		$this->tasks->get_task_locks()->invalidate_sessions( (int) $post->ID );
	}
}
