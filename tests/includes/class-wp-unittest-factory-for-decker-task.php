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
	 * Constructor.
	 *
	 * Initializes default generation definitions for decker_task creation.
	 *
	 * @param object|null $factory The global factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Extend parent's default generation definitions.
        $this->default_generation_definitions = array_merge(
            $this->default_generation_definitions,
            array(
				// Custom definitions for decker_task.
				'post_title'   => new WP_UnitTest_Generator_Sequence( 'Task title %s' ),
				'post_content' => new WP_UnitTest_Generator_Sequence( 'Task description %s' ),
                // Default to current user; fallback to 1 if no user is set later in create_object.
                'post_author'  => 0,
				'stack'        => 'to-do',
				'board'        => 0,
				'max_priority' => false,
                'author'       => 0,
                'responsable'  => 0,
				'hidden'       => false,
				'post_type'    => 'decker_task',
				// 'assigned_users' => array(),
				// 'labels'         => array(),
				// 'duedate'        => null,
			)
		);
	}

	/**
	 * Retrieves a Decker Task post by ID.
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
	 * This method receives the already merged arguments (defaults + input) from the parent's
	 * generate_args() call. We handle final normalization here (e.g., parse duedate, ensure board is valid).
	 *
	 * @param array $args Arguments for creating the task.
	 * @return int|WP_Error The created task ID or WP_Error on failure.
	 */
    public function create_object( $args ) {
        // Final adjustments and normalization.

		// Parse duedate if provided.
		if ( isset( $args['duedate'] ) ) {
			if ( is_string( $args['duedate'] ) && ! empty( $args['duedate'] ) ) {
				try {
					$args['duedate'] = new DateTime( $args['duedate'] );
				} catch ( Exception $e ) {
					$args['duedate'] = null;
				}
			} elseif ( ! ( $args['duedate'] instanceof DateTime ) ) {
				$args['duedate'] = null;
			}
		} else {
			$args['duedate'] = null;
		}

		// Ensure assigned_users is an array.
		if ( isset( $args['assigned_users'] ) && is_array( $args['assigned_users'] ) ) {
			$args['assigned_users'] = $args['assigned_users'];
		} else {
			$args['assigned_users'] = array();
		}

		// Ensure labels is an array.
		if ( isset( $args['labels'] ) && is_array( $args['labels'] ) ) {
			$args['labels'] = $args['labels'];
		} else {
			$args['labels'] = array();
		}

		// If board is empty or zero, try to create one using the board factory if available.
		if ( empty( $args['board'] ) ) {
			if ( ! isset( $this->factory->board ) ) {
				return new WP_Error( 'missing_factory', 'The "board" factory is not registered.' );
			}
			$args['board'] = $this->factory->board->create();
		}

		// Validate board ID.
		if ( ! is_int( $args['board'] ) || $args['board'] <= 0 ) {
			return new WP_Error( 'invalid_board', 'The provided board ID is not valid.' );
		}

		// Convert max_priority to boolean if needed.
		$args['max_priority'] = (bool) $args['max_priority'];

        // Default missing user-related fields to current user if not set.
        if ( empty( $args['author'] ) ) {
            $args['author'] = get_current_user_id();
        }
        if ( empty( $args['responsable'] ) ) {
            $args['responsable'] = get_current_user_id();
        }

        // Use the method from the plugin.
        $task_id = Decker_Tasks::create_or_update_task(
			0, // 0 indicates a new task.
			$args['post_title'],
			$args['post_content'],
			$args['stack'],
			$args['board'],
			$args['max_priority'],
			$args['duedate'],
			$args['author'],
			$args['responsable'],
			$args['hidden'],
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
		// Retrieve existing post and basic checks.
		$existing_post = get_post( $task_id );
		if ( ! $existing_post || 'decker_task' !== $existing_post->post_type ) {
			return new WP_Error( 'invalid_task', 'Invalid decker_task ID provided.' );
		}

		// Collect current meta.
		$current_meta = array(
			'post_title'     => $existing_post->post_title,
			'post_content'   => $existing_post->post_content,
			'stack'          => get_post_meta( $task_id, 'stack', true ),
			'board'          => 0,
			'max_priority'   => (bool) get_post_meta( $task_id, 'max_priority', true ),
			'duedate'        => get_post_meta( $task_id, 'duedate', true ),
			'author'         => (int) $existing_post->post_author,
			'responsable'    => (int) get_post_meta( $task_id, 'responsable', true ),
			'hidden'         => (bool) get_post_meta( $task_id, 'hidden', true ),
			'assigned_users' => get_post_meta( $task_id, 'assigned_users', true ),
			'labels'         => wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) ),
		);

		// Fetch current board if any.
		$board_terms = wp_get_post_terms( $task_id, 'decker_board', array( 'fields' => 'ids' ) );
		if ( ! empty( $board_terms ) ) {
			$current_meta['board'] = (int) $board_terms[0];
		}

		// Merge $fields over $current_meta so that user-provided data takes priority.
		$args = array_merge( $current_meta, $fields );

		// Now we handle transformations similar to what we did in create_object.

		// Parse duedate if set.
		if ( isset( $args['duedate'] ) ) {
			if ( is_string( $args['duedate'] ) && ! empty( $args['duedate'] ) ) {
				try {
					$args['duedate'] = new DateTime( $args['duedate'] );
				} catch ( Exception $e ) {
					$args['duedate'] = null;
				}
			} elseif ( ! ( $args['duedate'] instanceof DateTime ) ) {
				$args['duedate'] = null;
			}
		} else {
			$args['duedate'] = null;
		}

		// Ensure assigned_users is array.
		if ( isset( $args['assigned_users'] ) && is_array( $args['assigned_users'] ) ) {
			$args['assigned_users'] = $args['assigned_users'];
		} else {
			$args['assigned_users'] = array();
		}

		// Ensure labels is array.
		if ( isset( $args['labels'] ) && is_array( $args['labels'] ) ) {
			$args['labels'] = $args['labels'];
		} else {
			$args['labels'] = array();
		}

		// If board is 0 or empty, try to create a new board using the factory if available.
		if ( empty( $args['board'] ) ) {
			if ( ! isset( $this->factory->board ) ) {
				return new WP_Error( 'missing_factory', 'The "board" factory is not registered.' );
			}
			$args['board'] = $this->factory->board->create();
		}

		// Validate board.
		if ( ! is_int( $args['board'] ) || $args['board'] <= 0 ) {
			return new WP_Error( 'invalid_board', 'The provided board ID is not valid.' );
		}

		// Convert max_priority to bool.
		$args['max_priority'] = (bool) $args['max_priority'];

		// If board changed, we can update term relationship.
		if ( array_key_exists( 'board', $fields ) ) {
			wp_set_object_terms( $task_id, (int) $args['board'], 'decker_board', false );
		}

		// Use the plugin method to update the task.
		$updated_id = Decker_Tasks::create_or_update_task(
			$task_id,
			$args['post_title'],
			$args['post_content'],
			$args['stack'],
			$args['board'],
			$args['max_priority'],
			$args['duedate'],
			$args['author'],
			$args['responsable'],
			$args['hidden'],
			$args['assigned_users'],
			$args['labels']
		);

		return $updated_id;
	}
}
