<?php
/**
 * AJAX save path for tasks.
 *
 * Owns the wp_ajax_save_decker_task / wp_ajax_nopriv_save_decker_task
 * handler: reading and validating the posted form data, enforcing the
 * edit-lock guards against a stale or out-of-band save, creating or
 * updating the task, and rotating the session lock generation. Kept apart
 * from the admin save_post path (Decker_Task_Meta_Saver) and the shared
 * post type registration in Decker_Tasks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Ajax_Save
 */
class Decker_Task_Ajax_Save {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * The AJAX request field reader.
	 *
	 * @var Decker_Task_Request_Reader
	 */
	private $reader;

	/**
	 * Hook the AJAX save handler.
	 *
	 * @param Decker_Tasks $tasks The task post type coordinator.
	 */
	public function __construct( Decker_Tasks $tasks ) {
		$this->tasks  = $tasks;
		$this->reader = new Decker_Task_Request_Reader();

		add_action( 'wp_ajax_save_decker_task', array( $this, 'handle_save_decker_task' ) );
		add_action( 'wp_ajax_nopriv_save_decker_task', array( $this, 'handle_save_decker_task' ) );
	}

	/**
	 * Handles the creation or update of a Decker task via AJAX.
	 *
	 * This method processes form data sent via an AJAX request, validates and sanitizes
	 * the input, and either creates a new task or updates an existing one.
	 *
	 * When the decker_save_task_send_response filter is left at its default the
	 * outcome is sent straight back as JSON and nothing is returned; callers that
	 * disable it (the tests, and internal callers) get the payload instead.
	 *
	 * @return array|null The result payload, or null once a JSON response has been sent.
	 *
	 * @throws WP_Error If any validation or task creation/updating fails, an error is logged or returned.
	 */
	public function handle_save_decker_task() {

		$send_response = apply_filters( 'decker_save_task_send_response', true );

		// Security nonce check.
		if ( $send_response ) {
			check_ajax_referer( 'save_decker_task_nonce', 'nonce' );
		}

		// Retrieve and sanitize form data.
		$core    = $this->reader->read_task_core_fields();
		$options = $this->reader->read_task_option_fields();

		// Enforce the edit lock server-side before applying changes to an
		// existing task. A stale editing session (for example after another user
		// took over the lock) must never overwrite newer changes, even when the
		// active lock was released after the takeover (modal close / pagehide).
		if ( $core['id'] > 0 ) {
			$rejection = $this->guard_existing_task_save( $core['id'], $options['lock_generation'] );

			if ( null !== $rejection ) {
				return $this->fail_save( $rejection['data'], $send_response, $rejection['status'] );
			}
		}

		$duedate = $this->reader->parse_task_due_date( $options['duedate_raw'] );

		$mark_for_today = $options['mark_for_today'];

		// Handle assignees.
		$assigned_users = $this->reader->read_id_list_field( 'assignees' );

		// Handle labels.
		$labels = $this->reader->read_id_list_field( 'labels' );

		// Call the common function to create or update the task.
		$result = Decker_Task_Writer::create_or_update_task(
			array(
				'id'             => $core['id'],
				'title'          => $core['title'],
				'description'    => $core['description'],
				'stack'          => $core['stack'],
				'board'          => $core['board'],
				'max_priority'   => $options['max_priority'],
				'duedate'        => $duedate,
				'author'         => $options['author'],
				'responsable'    => $options['responsable'],
				'hidden'         => $options['hidden'],
				'assigned_users' => $assigned_users,
				'labels'         => $labels,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->fail_save( array( 'message' => $result->get_error_message() ), $send_response );
		}

		// Set today.
		if ( $mark_for_today ) {
			$this->tasks->get_today_manager()->mark_for_today( $result, get_current_user_id() );
		} else {
			$this->tasks->get_today_manager()->unmark_for_today( $result, get_current_user_id() );
		}

		// The save committed: rotate the generation so any other stale form (for
		// example a second tab of the same user) is rejected on its next save, and
		// hand the new token back so this form adopts it.
		$new_generation = $this->rotate_lock_generation( $core['id'], $options['lock_generation'] );

		$result_data = array(
			'success'    => true,
			'message'    => __( 'Task saved successfully.', 'decker' ),
			'task_id'    => $result,
			'generation' => $new_generation,
		);

		if ( $send_response ) {
			wp_send_json_success( $result_data );
		}

		return $result_data;
	}

	/**
	 * Check whether an existing task may be overwritten by the current request.
	 *
	 * Archived tasks are read-only, and a save carrying a stale session
	 * generation must not overwrite changes made after a lock takeover.
	 *
	 * @param int   $task_id         Task being saved.
	 * @param mixed $lock_generation Session generation supplied by the client.
	 * @return array{status:int,data:array}|null Rejection describing the refusal, or null when the save may proceed.
	 */
	private function guard_existing_task_save( $task_id, $lock_generation ) {
		// Archived tasks are read-only: reject direct saves regardless of
		// which editor the client used.
		if ( 'archived' === get_post_status( $task_id ) ) {
			return array(
				'status' => 403,
				'data'   => array(
					'message' => __( 'This task is archived and cannot be edited.', 'decker' ),
					'code'    => 'decker_task_archived',
				),
			);
		}

		// Public AJAX saves of an existing task must carry a session
		// generation while locking is enabled; a missing token cannot be
		// validated against a takeover and must not overwrite newer changes.
		$lock_check = $this->tasks->get_task_locks()->assert_user_can_save(
			$task_id,
			get_current_user_id(),
			$lock_generation,
			true
		);

		if ( is_wp_error( $lock_check ) ) {
			return array(
				'status' => 409,
				'data'   => $this->build_lock_error_data( $lock_check ),
			);
		}

		return null;
	}

	/**
	 * Describe a lock rejection for the client.
	 *
	 * @param WP_Error $lock_check Error returned by the lock check.
	 * @return array Error payload including the owner and generation when known.
	 */
	private function build_lock_error_data( WP_Error $lock_check ) {
		$error_data = array(
			'message' => $lock_check->get_error_message(),
			'code'    => $lock_check->get_error_code(),
			'locked'  => true,
		);

		$extra = $lock_check->get_error_data();

		if ( is_array( $extra ) ) {
			foreach ( array( 'owner', 'generation' ) as $key ) {
				if ( isset( $extra[ $key ] ) ) {
					$error_data[ $key ] = $extra[ $key ];
				}
			}
		}

		return $error_data;
	}

	/**
	 * Report a failed save through the channel the caller expects.
	 *
	 * @param array    $error_data    Payload describing the failure.
	 * @param bool     $send_response Whether to answer the AJAX request directly.
	 * @param int|null $status        HTTP status code, when the failure has one.
	 * @return array|null The failure payload, or null once the JSON response has been sent.
	 */
	private function fail_save( array $error_data, $send_response, $status = null ) {
		if ( $send_response ) {
			wp_send_json_error( $error_data, $status );
			return null;
		}

		return array_merge( array( 'success' => false ), $error_data );
	}

	/**
	 * Rotate the session generation after a committed save.
	 *
	 * Any other stale form (for example a second tab of the same user) is
	 * rejected on its next save once the generation moves on.
	 *
	 * @param int   $task_id         Task that was saved.
	 * @param mixed $lock_generation Session generation supplied by the client.
	 * @return string The new generation, or an empty string when there is nothing to rotate.
	 */
	private function rotate_lock_generation( $task_id, $lock_generation ) {
		$lock_generation = is_string( $lock_generation ) ? $lock_generation : '';

		if ( $task_id <= 0 || '' === $lock_generation ) {
			return '';
		}

		$rotated = $this->tasks->get_task_locks()->rotate_generation( $task_id, get_current_user_id(), $lock_generation );

		return false !== $rotated ? $rotated : '';
	}
}
