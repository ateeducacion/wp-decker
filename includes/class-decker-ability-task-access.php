<?php
/**
 * Authorization helpers for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Applies collection and task-level authorization rules.
 */
class Decker_Ability_Task_Access {

	/**
	 * Task store.
	 *
	 * @var Decker_Ability_Task_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Decker_Ability_Task_Store $store Task store.
	 */
	public function __construct( Decker_Ability_Task_Store $store ) {
		$this->store = $store;
	}

	/**
	 * Check permission for collection read operations.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	public function can_list_tasks( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return $this->permission_error( 'decker_authentication_required', 'Authentication is required.' );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->permission_error( 'decker_forbidden', 'You are not allowed to access Decker data.' );
		}

		if ( $this->requests_hidden_tasks( $input ) && ! current_user_can( 'manage_options' ) ) {
			return $this->permission_error( 'decker_hidden_tasks_forbidden', 'Only administrators may include hidden tasks.' );
		}

		return true;
	}

	/**
	 * Check permission for creating tasks.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	public function can_create_task( $input = null ) {
		return $this->can_list_tasks( $input );
	}

	/**
	 * Check permission for reading one task.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	public function can_read_task( $input ) {
		return $this->check_task_access( $input, false );
	}

	/**
	 * Check permission for editing one task.
	 *
	 * @param array $input Ability input.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	public function can_edit_task( $input ) {
		return $this->check_task_access( $input, true );
	}

	/**
	 * Check collection and task-level access.
	 *
	 * @param array $input        Ability input.
	 * @param bool  $require_edit Whether edit permission is required.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	private function check_task_access( $input, bool $require_edit ) {
		$collection_permission = $this->can_list_tasks();
		if ( is_wp_error( $collection_permission ) ) {
			return $collection_permission;
		}

		$task_id = isset( $input['task_id'] ) ? absint( $input['task_id'] ) : 0;
		$post    = $this->store->get_task_post( $task_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$capability = $require_edit ? 'edit_post' : 'read_post';
		if ( ! current_user_can( $capability, $task_id ) ) {
			return $this->permission_error( 'decker_task_forbidden', 'You are not allowed to access this task.' );
		}

		if ( $this->is_hidden_from_current_user( $post ) ) {
			return $this->permission_error( 'decker_task_hidden', 'You are not allowed to access this hidden task.' );
		}

		return true;
	}

	/**
	 * Determine whether hidden tasks were explicitly requested.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool True when hidden tasks were requested.
	 */
	private function requests_hidden_tasks( $input ): bool {
		return is_array( $input ) && ! empty( $input['include_hidden'] );
	}

	/**
	 * Determine whether a hidden task must be concealed.
	 *
	 * @param WP_Post $post Task post.
	 * @return bool True when access must be denied.
	 */
	private function is_hidden_from_current_user( WP_Post $post ): bool {
		if ( ! get_post_meta( $post->ID, 'hidden', true ) ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return false;
		}

		$user_id      = get_current_user_id();
		$responsible  = absint( get_post_meta( $post->ID, 'responsable', true ) );
		$assignee_ids = get_post_meta( $post->ID, 'assigned_users', true );
		$assignee_ids = is_array( $assignee_ids ) ? array_map( 'absint', $assignee_ids ) : array();

		if ( (int) $post->post_author === $user_id || $responsible === $user_id ) {
			return false;
		}

		return ! in_array( $user_id, $assignee_ids, true );
	}

	/**
	 * Build a permission error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error Permission error.
	 */
	private function permission_error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
