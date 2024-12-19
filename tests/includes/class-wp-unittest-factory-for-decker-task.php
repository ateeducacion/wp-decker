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
class WP_UnitTest_Factory_For_Decker_Task extends WP_UnitTest_Factory_For_Post {

	/**
	 * Constructor
	 *
	 * Initializes default generation definitions for decker_task creation.
	 *
	 * @param object|null $factory The global factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Extend parent's default generation definitions.
		$this->default_generation_definitions = array_merge(
			$this->default_generation_definitions, // Inherit parent definitions.
			array(
				// Custom definitions for decker_task.
				// Title and description use sequences to avoid collisions.
				'post_title'   => new WP_UnitTest_Generator_Sequence( 'Task title %s' ),
				'post_content' => new WP_UnitTest_Generator_Sequence( 'Task description %s' ),
				'post_author'  => 1, // default to user ID 1 (admin)
				// Default stack and board can be set here, or passed in tests.
				'stack'        => 'to-do',
				'board'        => 0, // You can set a default board, or set it in your tests.
				'max_priority' => false,
				// 'duedate'      => null,
				'author'       => 1, // default to user ID 1 (admin)
				// 'assigned_users' => array(),
				// 'labels'       => array(),
				'post_type'    => 'decker_task',
			)
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
	 * Merges provided args with defaults and resolves generator sequences.
	 *
	 * @param array $args Provided arguments.
	 * @return array Merged and resolved arguments.
	 */
	protected function merge_args( $args ) {
		// Merge defaults with provided args.

		$merged = array_merge( $this->default_generation_definitions, $args );

		// Resolve generator sequences into actual values.
		foreach ( $merged as $key => $value ) {
			if ( $value instanceof WP_UnitTest_Generator ) {
				$merged[ $key ] = $value->generate();
			}
		}

		return $merged;
	}

	/**
	 * Creates a decker_task using Decker_Tasks::create_or_update_task().
	 *
	 * @param array $args Arguments to override defaults.
	 * @return int|WP_Error The created task ID or WP_Error on failure.
	 */
	public function create_object( $args ) {

		// Merge defaults with provided args using the merge_args method.
		$args = $this->merge_args( $args );

		// Extract required arguments. Make sure they're sanitized or handled properly.

		$args['duedate']        = isset( $args['duedate'] ) ? $args['duedate'] : null;
		$args['assigned_users'] = isset( $args['assigned_users'] ) && is_array( $args['assigned_users'] ) ? $args['assigned_users'] : array();
		$args['labels']         = isset( $args['labels'] ) && is_array( $args['labels'] ) ? $args['labels'] : array();

		// Si 'board' no está definido o es 0, crear uno nuevo usando la factoría.
		if ( empty( $args['board'] ) ) {
			if ( ! isset( $this->factory->board ) ) {
				return new WP_Error( 'missing_factory', 'La factoría para "board" no está registrada.' );
			}
			$args['board'] = $this->factory->board->create(); // Crear un nuevo board.
		}

		// Asegurarse de que 'board' es un ID válido.
		if ( ! is_int( $args['board'] ) || $args['board'] <= 0 ) {
			return new WP_Error( 'invalid_board', 'El ID del board proporcionado no es válido.' );
		}

		// Use the method from the plugin.
		$task_id = Decker_Tasks::create_or_update_task(
			0, // 0 indica que es una creación nueva.
			$args['post_title'],
			$args['post_content'],
			$args['stack'],
			$args['board'],
			$args['max_priority'],
			$args['duedate'],
			$args['author'],
			$args['assigned_users'],
			$args['labels']
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

		// Merge existing meta with provided fields.
		$current_meta = array(
			'stack'          => get_post_meta( $task_id, 'stack', true ),
			'board'          => 0,
			'max_priority'   => get_post_meta( $task_id, 'max_priority', true ),
			'duedate'        => get_post_meta( $task_id, 'duedate', true ),
			'assigned_users' => get_post_meta( $task_id, 'assigned_users', true ),
			'labels'         => wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) ),
		);

		// Obtener el board actual si existe.
		$board_terms = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		if ( ! empty( $board_terms ) ) {
			$current_meta['board'] = (int) $board_terms[0];
		}

		// Fusionar los campos proporcionados con los metadatos actuales usando merge_args.
		$args = $this->merge_args( array_merge( $current_meta, $fields ) );

		// Si 'board' está presente y es 0, crear uno nuevo usando la factoría.
		if ( isset( $args['board'] ) && empty( $args['board'] ) ) {
			if ( ! isset( $this->factory->board ) ) {
				return new WP_Error( 'missing_factory', 'La factoría para "board" no está registrada.' );
			}
			$args['board'] = $this->factory->board->create(); // Crear un nuevo board.
		}

		// Asegurarse de que 'board' es un ID válido.
		if ( ! is_int( $args['board'] ) || $args['board'] <= 0 ) {
			return new WP_Error( 'invalid_board', 'El ID del board proporcionado no es válido.' );
		}

		// Si 'board' ha cambiado, actualizar la relación de términos.
		if ( isset( $fields['board'] ) ) {
			wp_set_object_terms( $task_id, (int) $args['board'], 'decker_board', false );
		}

		// Usar el método del plugin para actualizar la tarea.
		$updated_id = Decker_Tasks::create_or_update_task(
			$task_id,
			$args['post_title'],
			$args['post_content'],
			$args['stack'],
			$args['board'],
			$args['max_priority'],
			$args['duedate'],
			$args['author'],
			$args['assigned_users'],
			$args['labels']
		);

		return $updated_id;

		// // Fill arguments from existing post meta if not provided.
		// $title          = isset( $fields['post_title'] ) ? $fields['post_title'] : $existing_post->post_title;
		// $description    = isset( $fields['post_content'] ) ? $fields['post_content'] : $existing_post->post_content;
		// $stack          = isset( $fields['stack'] ) ? $fields['stack'] : get_post_meta( $task_id, 'stack', true );
		// $board_terms    = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		// $board          = isset( $fields['board'] ) ? (int) $fields['board'] : ( ( ! empty( $board_terms ) ) ? (int) $board_terms[0] : 0 );
		// $max_priority   = isset( $fields['max_priority'] ) ? (bool) $fields['max_priority'] : (bool) get_post_meta( $task_id, 'max_priority', true );
		// $existing_duedate = get_post_meta( $task_id, 'duedate', true );
		// $duedate        = array_key_exists( 'duedate', $fields ) ? $fields['duedate'] : ( ! empty( $existing_duedate ) ? new DateTime( $existing_duedate ) : null );
		// $author         = isset( $fields['author'] ) ? (int) $fields['author'] : (int) $existing_post->post_author;
		// $assigned_users = isset( $fields['assigned_users'] ) && is_array( $fields['assigned_users'] ) ? $fields['assigned_users'] : get_post_meta( $task_id, 'assigned_users', true );
		// $assigned_users = is_array( $assigned_users ) ? $assigned_users : array();

		// $existing_labels = wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) );
		// $labels          = isset( $fields['labels'] ) && is_array( $fields['labels'] ) ? $fields['labels'] : $existing_labels;

		// // Update task using the plugin's method.
		// $updated_id = Decker_Tasks::create_or_update_task(
		// $task_id,
		// $title,
		// $description,
		// $stack,
		// $board,
		// $max_priority,
		// $duedate,
		// $author,
		// $assigned_users,
		// $labels
		// );

		// return $updated_id;
	}
}
