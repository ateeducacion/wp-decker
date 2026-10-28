<?php
/**
 * REST transport for the subtask list of a task.
 *
 * Two routes, both writes: one replaces the whole list (the Subtasks tab sends
 * the list it holds, so reordering and per-item edits share a single write and
 * a single sanitization path), and one promotes an item into a real task when
 * it outgrows the list.
 *
 * The list is a shared task field, so the replace route takes the editor
 * form's generation token and is rejected when the form is stale — the same
 * contract as the attachment-link route in Decker_Tasks_Rest_Ops.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks_Rest_Subtasks
 */
class Decker_Tasks_Rest_Subtasks {

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
	 * Register the subtask REST routes.
	 */
	public function register_routes() {
		$routes = array(
			array(
				'route'      => '/tasks/(?P<id>\d+)/subtasks',
				'methods'    => 'PUT',
				'callback'   => 'replace_subtasks',
				'permission' => 'edit_task',
				'args'       => array(
					'items'           => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'object' ),
					),
					'lock_generation' => array( 'type' => 'string' ),
				),
			),
			array(
				'route'      => '/tasks/(?P<id>\d+)/subtasks/(?P<item_id>\d+)/convert',
				'methods'    => 'POST',
				'callback'   => 'convert_subtask',
				'permission' => 'edit_task',
				'args'       => array(
					'lock_generation' => array( 'type' => 'string' ),
				),
			),
		);

		Decker_Tasks_Rest_Support::register_routes( 'decker/v1', $routes, $this );
	}

	/**
	 * Replace the whole subtask list of a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The result or validation error.
	 */
	public function replace_subtasks( WP_REST_Request $request ) {
		$task_id   = (int) $request['id'];
		$rejection = $this->reject_ineligible_task( $task_id, $request );
		if ( $rejection instanceof WP_REST_Response ) {
			return $rejection;
		}

		$submitted = (array) $request->get_param( 'items' );

		if ( count( $submitted ) > Decker_Subtasks::MAX_ITEMS ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'decker_subtasks_too_many',
					'message' => sprintf(
						/* translators: %d is the maximum number of subtasks allowed on a task. */
						__( 'A task cannot hold more than %d subtasks.', 'decker' ),
						Decker_Subtasks::MAX_ITEMS
					),
				),
				400
			);
		}

		// Promotion links are assigned by convert_subtask() and are not part of
		// what the editor may set, so they are carried over from the stored list
		// by item id instead of being trusted from the request.
		$items = $this->restore_promotion_links( $task_id, $submitted );
		$items = Decker_Subtasks::set( $task_id, $items );

		return $this->list_response( $items );
	}

	/**
	 * Promote a subtask into a task of its own.
	 *
	 * The new task inherits the parent's board (a task cannot exist without
	 * one) and falls back to the parent's responsable and due date when the
	 * item carries none. The item stays in the list, linked to the task it
	 * became, so the parent keeps showing the whole breakdown.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The result or validation error.
	 */
	public function convert_subtask( WP_REST_Request $request ) {
		$task_id   = (int) $request['id'];
		$rejection = $this->reject_ineligible_task( $task_id, $request );
		if ( $rejection instanceof WP_REST_Response ) {
			return $rejection;
		}

		$item_id = (int) $request['item_id'];
		$items   = Decker_Subtasks::get( $task_id );
		$index   = Decker_Subtasks::find_index( $items, $item_id );

		if ( null === $index ) {
			return $this->error( 'decker_subtask_not_found', __( 'Subtask not found.', 'decker' ), 404 );
		}

		if ( $items[ $index ]['task_id'] > 0 ) {
			return $this->error( 'decker_subtask_already_converted', __( 'This subtask has already been converted into a task.', 'decker' ), 409 );
		}

		$new_task_id = $this->create_task_from_item( $items[ $index ], new Task( $task_id ) );

		if ( $new_task_id instanceof WP_REST_Response ) {
			return $new_task_id;
		}

		$items[ $index ]['task_id'] = $new_task_id;
		$items                      = Decker_Subtasks::set( $task_id, $items );

		$response             = $this->list_response( $items )->get_data();
		$response['task_id']  = (int) $new_task_id;
		$response['task_url'] = add_query_arg(
			array(
				'decker_page' => 'task',
				'id'          => (int) $new_task_id,
			),
			home_url( '/' )
		);

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Create the task a promoted subtask becomes.
	 *
	 * @param array $item   The subtask item being promoted.
	 * @param Task  $parent The task it belongs to.
	 * @return int|WP_REST_Response The new task ID, or the response to return.
	 */
	private function create_task_from_item( array $item, Task $parent ) {
		if ( ! $parent->board instanceof Board ) {
			return $this->error( 'decker_subtask_no_board', __( 'The parent task has no board, so the subtask cannot become a task.', 'decker' ), 409 );
		}

		$responsable = $item['assignee'];
		if ( $responsable <= 0 && $parent->responsable instanceof WP_User ) {
			$responsable = $parent->responsable->ID;
		}

		$new_task_id = Decker_Task_Writer::create_or_update_task(
			array(
				'title'          => $item['title'],
				'stack'          => $item['status'],
				'board'          => $parent->board->id,
				'duedate'        => $this->resolve_promoted_duedate( $item['duedate'], $parent->duedate ),
				'author'         => get_current_user_id(),
				'responsable'    => $responsable,
				'assigned_users' => $item['assignee'] > 0 ? array( $item['assignee'] ) : array(),
			)
		);

		if ( is_wp_error( $new_task_id ) ) {
			return $this->error( 'decker_subtask_convert_failed', $new_task_id->get_error_message(), 400 );
		}

		return (int) $new_task_id;
	}

	/**
	 * The due date a promoted subtask starts with.
	 *
	 * @param string        $item_duedate   The item due date in Y-m-d form, or empty.
	 * @param DateTime|null $parent_duedate The parent task due date.
	 * @return DateTime|null The due date for the new task.
	 */
	private function resolve_promoted_duedate( string $item_duedate, ?DateTime $parent_duedate ): ?DateTime {
		if ( '' !== $item_duedate ) {
			$date = DateTime::createFromFormat( 'Y-m-d', $item_duedate );
			if ( $date instanceof DateTime ) {
				return $date;
			}
		}

		return $parent_duedate;
	}

	/**
	 * Carry the stored promotion links over to the submitted items.
	 *
	 * @param int   $task_id   The parent task ID.
	 * @param array $submitted The submitted items.
	 * @return array<int, array> The submitted items with their stored task links.
	 */
	private function restore_promotion_links( int $task_id, array $submitted ): array {
		$stored = Decker_Subtasks::get( $task_id );
		$links  = array();

		foreach ( $stored as $item ) {
			if ( $item['task_id'] > 0 ) {
				$links[ (int) $item['id'] ] = (int) $item['task_id'];
			}
		}

		foreach ( $submitted as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;

			$submitted[ $index ]['task_id'] = isset( $links[ $item_id ] ) ? $links[ $item_id ] : 0;
		}

		return $submitted;
	}

	/**
	 * Reject a request that cannot write the subtasks of the given task.
	 *
	 * @param int             $task_id The task ID.
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|null A response when the request is rejected, null otherwise.
	 */
	private function reject_ineligible_task( int $task_id, WP_REST_Request $request ) {
		if ( ! Decker_Subtasks::is_enabled() ) {
			return $this->error( 'decker_subtasks_disabled', __( 'Subtasks are disabled on this site.', 'decker' ), 403 );
		}

		$post = $task_id > 0 ? get_post( $task_id ) : null;

		if ( ! $post || 'decker_task' !== $post->post_type ) {
			return $this->error( 'decker_invalid_task', __( 'Task not found.', 'decker' ), 404 );
		}

		if ( 'archived' === $post->post_status ) {
			return $this->error( 'decker_task_archived', __( 'You are not allowed to edit this card.', 'decker' ), 403 );
		}

		$check = $this->tasks->get_task_locks()->assert_user_can_save(
			$task_id,
			get_current_user_id(),
			$request->get_param( 'lock_generation' ),
			true
		);

		if ( is_wp_error( $check ) ) {
			return Decker_Tasks_Rest_Support::error_response( $check );
		}

		return null;
	}

	/**
	 * Build the success response for a stored list.
	 *
	 * @param array $items The stored subtask items.
	 * @return WP_REST_Response The response.
	 */
	private function list_response( array $items ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success'  => true,
				'items'    => $items,
				'progress' => Decker_Subtasks::progress( $items ),
			),
			200
		);
	}

	/**
	 * Build an error response in the shape the task endpoints return.
	 *
	 * @param string $code    The error code.
	 * @param string $message The human-readable message.
	 * @param int    $status  The HTTP status.
	 * @return WP_REST_Response The error response.
	 */
	private function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
