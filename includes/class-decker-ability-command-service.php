<?php
/**
 * Write operations for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Executes validated task mutations through existing Decker domain methods.
 */
class Decker_Ability_Command_Service {

	/**
	 * Task store.
	 *
	 * @var Decker_Ability_Task_Store
	 */
	private $store;

	/**
	 * Input validator.
	 *
	 * @var Decker_Ability_Input_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Decker_Ability_Task_Store      $store     Task store.
	 * @param Decker_Ability_Input_Validator $validator Input validator.
	 */
	public function __construct( Decker_Ability_Task_Store $store, Decker_Ability_Input_Validator $validator ) {
		$this->store     = $store;
		$this->validator = $validator;
	}

	/**
	 * Create a task through the existing domain method.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Created task or error.
	 */
	public function create_task( $input ) {
		$state = $this->validator->prepare_create_state( is_array( $input ) ? $input : array() );

		return is_wp_error( $state ) ? $state : $this->store->persist( 0, $state );
	}

	/**
	 * Update selected fields on a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function update_task( $input ) {
		$loaded = $this->load_writable_task( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$state = $this->validator->merge_task_state( $loaded['state'], (array) $input, $loaded['post']->ID );

		return is_wp_error( $state ) ? $state : $this->store->persist( $loaded['post']->ID, $state );
	}

	/**
	 * Move a task to another stack or board.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function move_task( $input ) {
		$changes = array_intersect_key(
			(array) $input,
			array(
				'stack'    => true,
				'board_id' => true,
			)
		);

		return $this->update_with_changes( $input, $changes );
	}

	/**
	 * Archive or restore a task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	public function archive_task( $input ) {
		return $this->update_with_changes(
			$input,
			array( 'archived' => ! empty( $input['archived'] ) )
		);
	}

	/**
	 * Update a writable task with a constrained change set.
	 *
	 * @param array $input   Ability input.
	 * @param array $changes Allowed changes.
	 * @return array<string, mixed>|WP_Error Updated task or error.
	 */
	private function update_with_changes( $input, array $changes ) {
		$loaded = $this->load_writable_task( $input );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$state = $this->validator->merge_task_state( $loaded['state'], $changes, $loaded['post']->ID );

		return is_wp_error( $state ) ? $state : $this->store->persist( $loaded['post']->ID, $state );
	}

	/**
	 * Load task state and enforce the existing edit lock.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Writable task data or error.
	 */
	private function load_writable_task( $input ) {
		$task_id = isset( $input['task_id'] ) ? absint( $input['task_id'] ) : 0;
		$post    = $this->store->get_task_post( $task_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$lock_check = $this->store->assert_unlocked( $post );
		if ( is_wp_error( $lock_check ) ) {
			return $lock_check;
		}

		$stale_check = $this->assert_not_stale( $post, $input );
		if ( is_wp_error( $stale_check ) ) {
			return $stale_check;
		}

		$state = $this->store->get_write_state( $post );

		return is_wp_error( $state ) ? $state : array(
			'post'  => $post,
			'state' => $state,
		);
	}

	/**
	 * Reject a write built from a stale read.
	 *
	 * The edit lock is advisory and only active when Decker's post-locking
	 * feature is on, so on its own it does not stop an agent from overwriting a
	 * change made between its read and its write. That matters most for the
	 * read-modify-write operations: adding one label or one assignee means
	 * sending the whole collection back, so a lost update silently drops
	 * someone else's label or assignee.
	 *
	 * `expected_modified` is the task's `modified` value as the read returned
	 * it. When it no longer matches, the write is refused with 409 and nothing
	 * is persisted. It is optional, so existing callers are unaffected, and it
	 * introduces no new storage and no second locking system: the comparison is
	 * against post_modified_gmt, which WordPress already maintains.
	 *
	 * @param WP_Post $post  Task post.
	 * @param array   $input Ability input.
	 * @return true|WP_Error True when the write may proceed.
	 */
	private function assert_not_stale( WP_Post $post, $input ) {
		$input = is_array( $input ) ? $input : array();

		if ( ! isset( $input['expected_modified'] ) || '' === $input['expected_modified'] ) {
			return true;
		}

		$current = mysql_to_rfc3339( $post->post_modified_gmt );

		// Compared as instants, so an equivalent timestamp written with a
		// different offset is not reported as a conflict.
		$expected_time = strtotime( (string) $input['expected_modified'] );
		$current_time  = strtotime( (string) $current );

		if ( false === $expected_time ) {
			return new WP_Error(
				'decker_invalid_expected_modified',
				__( 'The supplied expected_modified value is not a valid date.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		if ( $expected_time !== $current_time ) {
			return new WP_Error(
				'decker_task_stale',
				__( 'The task changed after it was read, so the update was not applied. Read the task again, rebuild the change and retry.', 'decker' ),
				array(
					'status'            => 409,
					'expected_modified' => (string) $input['expected_modified'],
					'current_modified'  => $current,
				)
			);
		}

		return true;
	}
}
