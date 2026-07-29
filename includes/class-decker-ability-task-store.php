<?php
/**
 * Task persistence and formatting helpers for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reads and persists task state through existing Decker domain methods.
 */
class Decker_Ability_Task_Store {

	/**
	 * Supported task stacks.
	 *
	 * @var string[]
	 */
	private const STACKS = array( 'to-do', 'in-progress', 'done' );

	/**
	 * Return a task post or a structured error.
	 *
	 * @param int $task_id Task ID.
	 * @return WP_Post|WP_Error Task post or error.
	 */
	public function get_task_post( int $task_id ) {
		$post = $task_id > 0 ? get_post( $task_id ) : null;

		if ( ! $post || 'decker_task' !== $post->post_type ) {
			return new WP_Error(
				'decker_task_not_found',
				__( 'The requested task was not found.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Format a task as a stable machine-readable object.
	 *
	 * @param WP_Post $post Task post.
	 * @return array<string, mixed> Formatted task.
	 */
	public function format_task( WP_Post $post ): array {
		$stack       = $this->normalize_stack( get_post_meta( $post->ID, 'stack', true ) );
		$board_ids   = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
		$label_ids   = wp_get_post_terms( $post->ID, 'decker_label', array( 'fields' => 'ids' ) );
		$assignees   = get_post_meta( $post->ID, 'assigned_users', true );
		$responsible = absint( get_post_meta( $post->ID, 'responsable', true ) );

		return array(
			'id'                  => (int) $post->ID,
			'title'               => (string) $post->post_title,
			'description'         => (string) $post->post_content,
			'status'              => (string) $post->post_status,
			'stack'               => $stack,
			'board_id'            => $this->first_term_id( $board_ids ),
			'label_ids'           => $this->term_ids( $label_ids ),
			'assignee_ids'        => $this->integer_ids( $assignees ),
			'author_id'           => (int) $post->post_author,
			'responsible_user_id' => $responsible > 0 ? $responsible : (int) $post->post_author,
			'due_date'            => (string) get_post_meta( $post->ID, 'duedate', true ),
			'max_priority'        => '1' === (string) get_post_meta( $post->ID, 'max_priority', true ),
			'hidden'              => '1' === (string) get_post_meta( $post->ID, 'hidden', true ),
			'order'               => (int) $post->menu_order,
			'modified'            => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * Read the complete writable task state.
	 *
	 * @param WP_Post $post Task post.
	 * @return array<string, mixed>|WP_Error Task state or error.
	 */
	public function get_write_state( WP_Post $post ) {
		$board_ids = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $board_ids ) || empty( $board_ids ) ) {
			return new WP_Error(
				'decker_task_board_missing',
				__( 'The task is not assigned to a valid board.', 'decker' ),
				array( 'status' => 409 )
			);
		}

		$label_ids   = wp_get_post_terms( $post->ID, 'decker_label', array( 'fields' => 'ids' ) );
		$assignees   = get_post_meta( $post->ID, 'assigned_users', true );
		$responsible = absint( get_post_meta( $post->ID, 'responsable', true ) );

		return array(
			'title'               => (string) $post->post_title,
			'description'         => (string) $post->post_content,
			'stack'               => $this->normalize_stack( get_post_meta( $post->ID, 'stack', true ) ),
			'board_id'            => (int) $board_ids[0],
			'max_priority'        => '1' === (string) get_post_meta( $post->ID, 'max_priority', true ),
			'due_date'            => (string) get_post_meta( $post->ID, 'duedate', true ),
			'author_id'           => (int) $post->post_author,
			'responsible_user_id' => $responsible > 0 ? $responsible : (int) $post->post_author,
			'hidden'              => '1' === (string) get_post_meta( $post->ID, 'hidden', true ),
			'assignee_ids'        => $this->integer_ids( $assignees ),
			'label_ids'           => $this->term_ids( $label_ids ),
			'archived'            => 'archived' === $post->post_status,
		);
	}

	/**
	 * Enforce the existing edit-lock behavior.
	 *
	 * @param WP_Post $post Task post.
	 * @return true|WP_Error True when the task may be saved, otherwise an error.
	 */
	public function assert_unlocked( WP_Post $post ) {
		return ( new Decker_Task_Locks() )->assert_user_can_save(
			$post->ID,
			get_current_user_id()
		);
	}

	/**
	 * Persist complete task state through the existing domain method.
	 *
	 * @param int   $task_id Task ID, or zero when creating.
	 * @param array $state   Validated task state.
	 * @return array<string, mixed>|WP_Error Formatted task or error.
	 */
	public function persist( int $task_id, array $state ) {
		// Labels and the Nextcloud link are handled by the domain method:
		// create_or_update_task() now replaces the full label set (clearing when
		// empty) and preserves an existing id_nextcloud_card on update.
		$result = Decker_Task_Writer::create_or_update_task(
			array(
				'id'             => $task_id,
				'title'          => (string) $state['title'],
				'description'    => (string) $state['description'],
				'stack'          => (string) $state['stack'],
				'board'          => absint( $state['board_id'] ),
				'max_priority'   => (bool) $state['max_priority'],
				'duedate'        => $state['due_date_object'],
				'author'         => absint( $state['author_id'] ),
				'responsable'    => absint( $state['responsible_user_id'] ),
				'hidden'         => (bool) $state['hidden'],
				'assigned_users' => (array) $state['assignee_ids'],
				'labels'         => $this->term_ids( $state['label_ids'] ),
				'creation_date'  => null,
				'archived'       => (bool) $state['archived'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = $this->get_task_post( absint( $result ) );

		return is_wp_error( $post ) ? $post : $this->format_task( $post );
	}

	/**
	 * Normalize a stored stack value.
	 *
	 * @param mixed $stack Stored stack.
	 * @return string Supported stack.
	 */
	private function normalize_stack( $stack ): string {
		$stack = (string) $stack;

		return in_array( $stack, self::STACKS, true ) ? $stack : 'to-do';
	}

	/**
	 * Get the first term ID from a taxonomy result.
	 *
	 * @param int[]|WP_Error $term_ids Term IDs or an error.
	 * @return int First term ID or zero.
	 */
	private function first_term_id( $term_ids ): int {
		if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
			return 0;
		}

		return (int) $term_ids[0];
	}

	/**
	 * Normalize taxonomy IDs.
	 *
	 * @param int[]|WP_Error $term_ids Term IDs or an error.
	 * @return int[] Normalized IDs.
	 */
	private function term_ids( $term_ids ): array {
		if ( is_wp_error( $term_ids ) ) {
			return array();
		}

		return array_values( array_map( 'intval', (array) $term_ids ) );
	}

	/**
	 * Normalize a stored ID list.
	 *
	 * @param mixed $ids Stored IDs.
	 * @return int[] Normalized IDs.
	 */
	private function integer_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $ids ) );
	}
}
