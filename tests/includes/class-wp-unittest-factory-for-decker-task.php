<?php
/**
 * Custom factory for Decker Task custom post type.
 *
 * @package Decker
 */

/**
 * Class WP_UnitTest_Factory_For_Decker_Task
 *
 * A factory that uses Decker_Tasks::create_or_update_task() for creating and updating decker_task posts.
 * It integrates with the WordPress Core unit test factories.
 */
class WP_UnitTest_Factory_For_Decker_Task extends WP_UnitTest_Factory_For_Thing {

	/**
	 * Constructor
	 *
	 * Initializes default generation definitions for decker_task creation.
	 *
	 * @param object|null $factory The global factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Define default generation.
		$this->default_generation_definitions = array(
			// Title and description use sequences to avoid collisions.
			'post_title'   => new WP_UnitTest_Generator_Sequence( 'Task title %s' ),
			'post_content' => new WP_UnitTest_Generator_Sequence( 'Task description %s' ),
			// Default stack and board can be set here, or passed in tests.
			'stack'        => 'to-do',
			'board'        => 0, // You can set a default board, or set it in your tests.
			'max_priority' => false,
			'duedate'      => null,
			'author'       => 1, // default to user ID 1 (admin)
			'assigned_users' => array(),
			'labels'       => array(),
		);
	}

	/**
	 * Retrieve a Decker Task post by ID.
	 *
	 * @param int $object_id The post ID.
	 * @return WP_Post|false WP_Post object on success, or false if not found.
	 */
	public function get_object_by_id( $object_id ) {
		$post = get_post( $object_id );
		if ( $post && 'decker_task' === $post->post_type ) {
			return $post;
		}
		return false;
	}


	/**
	 * Creates a decker_task using Decker_Tasks::create_or_update_task().
	 *
	 * @param array $args Arguments to override defaults.
	 * @return int|WP_Error The created task ID or WP_Error on failure.
	 */
	public function create_object( $args ) {
		// Extract required arguments. Make sure they're sanitized or handled properly.
		$title          = isset( $args['post_title'] ) ? $args['post_title'] : 'Default Task Title';
		$description    = isset( $args['post_content'] ) ? $args['post_content'] : 'Default description';
		$stack          = isset( $args['stack'] ) ? $args['stack'] : 'to-do';
		$board          = isset( $args['board'] ) ? (int) $args['board'] : 0;
		$max_priority   = isset( $args['max_priority'] ) ? (bool) $args['max_priority'] : false;
		$duedate        = isset( $args['duedate'] ) ? $args['duedate'] : null;
		$author         = isset( $args['author'] ) ? (int) $args['author'] : 1;
		$assigned_users = isset( $args['assigned_users'] ) && is_array( $args['assigned_users'] ) ? $args['assigned_users'] : array();
		$labels         = isset( $args['labels'] ) && is_array( $args['labels'] ) ? $args['labels'] : array();

		// Use the method from the plugin.
		$task_id = Decker_Tasks::create_or_update_task(
			0,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		return $task_id;
	}

	/**
	 * Updates a decker_task using Decker_Tasks::create_or_update_task().
	 *
	 * @param int   $task_id Task ID to update.
	 * @param array $fields  Fields to update.
	 * @return int|WP_Error Updated task ID or WP_Error on failure.
	 */
	public function update_object( $task_id, $fields ) {
		// Retrieve existing values.
		$existing_post = get_post( $task_id );

		if ( ! $existing_post || 'decker_task' !== $existing_post->post_type ) {
			return new WP_Error( 'invalid_task', 'Invalid decker_task ID provided.' );
		}

		// Fill arguments from existing post meta if not provided.
		$title          = isset( $fields['post_title'] ) ? $fields['post_title'] : $existing_post->post_title;
		$description    = isset( $fields['post_content'] ) ? $fields['post_content'] : $existing_post->post_content;
		$stack          = isset( $fields['stack'] ) ? $fields['stack'] : get_post_meta( $task_id, 'stack', true );
		$board_terms    = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		$board          = isset( $fields['board'] ) ? (int) $fields['board'] : ( ( ! empty( $board_terms ) ) ? (int) $board_terms[0] : 0 );
		$max_priority   = isset( $fields['max_priority'] ) ? (bool) $fields['max_priority'] : (bool) get_post_meta( $task_id, 'max_priority', true );
		$existing_duedate = get_post_meta( $task_id, 'duedate', true );
		$duedate        = array_key_exists( 'duedate', $fields ) ? $fields['duedate'] : ( ! empty( $existing_duedate ) ? new DateTime( $existing_duedate ) : null );
		$author         = isset( $fields['author'] ) ? (int) $fields['author'] : (int) $existing_post->post_author;
		$assigned_users = isset( $fields['assigned_users'] ) && is_array( $fields['assigned_users'] ) ? $fields['assigned_users'] : get_post_meta( $task_id, 'assigned_users', true );
		$assigned_users = is_array( $assigned_users ) ? $assigned_users : array();

		$existing_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$labels          = isset( $fields['labels'] ) && is_array( $fields['labels'] ) ? $fields['labels'] : $existing_labels;

		// Update task using the plugin's method.
		$updated_id = Decker_Tasks::create_or_update_task(
			$task_id,
			$title,
			$description,
			$stack,
			$board,
			$max_priority,
			$duedate,
			$author,
			$assigned_users,
			$labels
		);

		return $updated_id;
	}
}
