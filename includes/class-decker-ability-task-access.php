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
	 * Every ability reaches this check, directly or through
	 * check_task_access(), so it is where Decker's access policy is applied.
	 *
	 * Two conditions, not one. `edit_posts` is the capability Decker's data
	 * surfaces have always required, and the configured minimum role is the
	 * gate on Decker's own screens: an ability is one of those screens'
	 * operations performed somewhere else, so it answers to the same gate. On
	 * its own, `edit_posts` would be weaker than the interface, because a
	 * contributor holds it without holding `publish_posts` and without being
	 * allowed into Decker at the default minimum of editor — and a created
	 * task is published, so that account could publish through an ability what
	 * it cannot publish anywhere else. On its own, the minimum role would be
	 * weaker too, since it can be configured down to a role that holds no post
	 * capability at all.
	 *
	 * Deliberately not a `publish_posts` check: whoever the minimum role lets
	 * into Decker creates published tasks in the interface as well, so
	 * requiring it here would make the abilities stricter than the screens
	 * they stand in for, which is a second policy rather than one applied
	 * consistently.
	 *
	 * @param array|null $input Optional ability input.
	 * @return bool|WP_Error True when permitted, otherwise a structured error.
	 */
	public function can_list_tasks( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return $this->authentication_error();
		}

		if ( ! current_user_can( 'edit_posts' ) || ! Decker::current_user_has_at_least_minimum_role() ) {
			return $this->permission_error( 'decker_forbidden', __( 'You are not allowed to access Decker data.', 'decker' ) );
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
			return $this->permission_error( 'decker_task_forbidden', __( 'You are not allowed to access this task.', 'decker' ) );
		}

		if ( $this->is_hidden_from_current_user( $post ) ) {
			return $this->permission_error( 'decker_task_hidden', __( 'You are not allowed to access this hidden task.', 'decker' ) );
		}

		return true;
	}

	/**
	 * Determine whether a task may appear in a listing for the current user.
	 *
	 * Applies the same hidden-task rule enforced by get-task so listings and
	 * single reads stay consistent. Hidden tasks are excluded from the default
	 * listing and only surface when explicitly requested by a user allowed to
	 * see them (their author, responsible user, assignee, or an administrator).
	 *
	 * @param WP_Post $post           Task post.
	 * @param bool    $include_hidden Whether hidden tasks were requested.
	 * @return bool True when the task is visible to the current user.
	 */
	public function is_visible_in_list( WP_Post $post, bool $include_hidden ): bool {
		$is_hidden = (bool) get_post_meta( $post->ID, 'hidden', true );

		if ( $is_hidden && ! $include_hidden ) {
			return false;
		}

		return ! $this->is_hidden_from_current_user( $post );
	}

	/**
	 * Determine whether a hidden task must be concealed.
	 *
	 * Delegates to the post type, which applies the same rule to the REST
	 * endpoints, so both paths cannot drift apart.
	 *
	 * @param WP_Post $post Task post.
	 * @return bool True when access must be denied.
	 */
	private function is_hidden_from_current_user( WP_Post $post ): bool {
		return Decker_Tasks::is_hidden_from_current_user( $post->ID );
	}

	/**
	 * Build an authentication error.
	 *
	 * Missing authentication is 401, distinct from an authenticated-but-forbidden
	 * 403, so agents can tell "log in" apart from "give up".
	 *
	 * @return WP_Error Authentication error.
	 */
	private function authentication_error(): WP_Error {
		return new WP_Error(
			'decker_authentication_required',
			__( 'Authentication is required.', 'decker' ),
			array( 'status' => 401 )
		);
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
