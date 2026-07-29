<?php
/**
 * Task write core for the Decker Plugin.
 *
 * Owns create_or_update_task() and the private helpers it delegates to:
 * field validation, taxonomy/meta assembly, assigned-user resolution, and
 * the create/update split with its hook firing order. Stateless static
 * class, no hooks of its own.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Writer
 */
class Decker_Task_Writer {

	/**
	 * Creates or updates a task in the Decker system.
	 *
	 * This method handles validation, taxonomy assignments, and metadata management.
	 * for tasks. It can either create a new task or update an existing one.
	 *
	 * @param array $args {
	 *     The task fields, merged over the defaults via wp_parse_args().
	 *
	 *     @type int           $id                 The ID of the task to update, or 0 to create a new task.
	 *     @type string        $title              The title of the task.
	 *     @type string        $description        The description of the task.
	 *     @type string        $stack              The stack name (e.g., 'to-do', 'in-progress').
	 *     @type int           $board              The ID of the board associated with the task.
	 *     @type bool          $max_priority       Whether the task has maximum priority.
	 *     @type DateTime|null $duedate            The due date of the task, or null if not set.
	 *     @type int           $author             The ID of the author of the task.
	 *     @type int           $responsable        The ID of the responsable of the task.
	 *     @type bool          $hidden             Whether the task is hidden in listings.
	 *     @type array         $assigned_users     An array of user IDs assigned to the task.
	 *     @type array         $labels             An array of label IDs associated with the task.
	 *     @type DateTime|null $creation_date      The creation date of the task. Default is null.
	 *     @type bool          $archived           Whether the task is archived. Default is false.
	 *     @type int           $id_nextcloud_card  The NextCloud card ID associated with the task. Default is 0.
	 * }
	 * @return int|WP_Error The ID of the created or updated task on success, or a WP_Error object on failure.
	 */
	public static function create_or_update_task( array $args ) {
		$defaults = array(
			'id'                => 0,
			'title'             => '',
			'description'       => '',
			'stack'             => '',
			'board'             => 0,
			'max_priority'      => false,
			'duedate'           => null,
			'author'            => 0,
			'responsable'       => 0,
			'hidden'            => false,
			'assigned_users'    => array(),
			'labels'            => array(),
			'creation_date'     => null,
			'archived'          => false,
			'id_nextcloud_card' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$id                = (int) $args['id'];
		$title             = (string) $args['title'];
		$description       = (string) $args['description'];
		$stack             = (string) $args['stack'];
		$board             = (int) $args['board'];
		$max_priority      = (bool) $args['max_priority'];
		$duedate           = $args['duedate'];
		$author            = (int) $args['author'];
		$responsable       = (int) $args['responsable'];
		$hidden            = (bool) $args['hidden'];
		$assigned_users    = (array) $args['assigned_users'];
		$labels            = (array) $args['labels'];
		$creation_date     = $args['creation_date'];
		$archived          = (bool) $args['archived'];
		$id_nextcloud_card = (int) $args['id_nextcloud_card'];

		// Validate required fields.
		$validation = self::validate_task_fields( $title, $stack, $board );
		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		// Convert DateTime objects to string format (otherwise pass null to undefined).
		$duedate_str = $duedate ? $duedate->format( 'Y-m-d' ) : null;

		// Prepare the terms for tax_input.
		$tax_input = self::build_task_tax_input( $board, $labels );

		// Normalize assigned users (pluck WP_User IDs) and compute the newly added ones.
		list( $assigned_users, $new_users ) = self::resolve_assigned_users_and_new( $id, $assigned_users );

				// Prepare custom metadata.
		$meta_input = array(
			'stack'          => sanitize_text_field( $stack ),
			'duedate'        => $duedate_str,
			'max_priority'   => $max_priority ? '1' : '0',
			'assigned_users' => $assigned_users,
			'responsable'    => $responsable,
			'hidden'         => $hidden,
		);

		// Preserve an existing Nextcloud link on update: callers that do not manage
		// the link (the frontend form, the abilities adapter) pass 0 and must not
		// wipe a card ID synced from elsewhere. On create there is nothing to
		// preserve, so the supplied value (including 0) is written as-is.
		if ( $id_nextcloud_card > 0 || $id <= 0 ) {
			$meta_input['id_nextcloud_card'] = $id_nextcloud_card;
		}

		// Prepare the post data.
		$post_data = self::build_task_post_data(
			$title,
			$description,
			$archived,
			$author,
			$responsable,
			$hidden,
			$meta_input,
			$tax_input,
			$creation_date
		);

		// Determine if it's an update or creation.
		if ( $id > 0 ) {
			$task_id = self::update_existing_task( $id, $post_data, $stack, $responsable, $new_users );
		} else {
			$task_id = self::insert_new_task( $post_data );
		}

		if ( is_wp_error( $task_id ) ) {
			return $task_id; // Return the error to handle it externally.
		}

			   // Return the ID of the created or updated task.
		return $task_id;
	}

	/**
	 * Validate the required fields for creating or updating a task.
	 *
	 * @param string $title The task title.
	 * @param string $stack The task stack.
	 * @param int    $board The board term ID.
	 * @return WP_Error|null A WP_Error on failure, or null when valid.
	 */
	private static function validate_task_fields( string $title, string $stack, int $board ) {
		if ( empty( $title ) ) {
			return new WP_Error( 'missing_field', __( 'The title is required.', 'decker' ) );
		}
		if ( empty( $stack ) ) {
			return new WP_Error( 'missing_field', __( 'The stack is required.', 'decker' ) );
		}

		// Validate allowed values for stack.
		$allowed_stacks = array( 'to-do', 'in-progress', 'done' );
		if ( ! in_array( $stack, $allowed_stacks, true ) ) {
			return new WP_Error( 'invalid_field', __( 'The stack is invalid. Allowed values: to-do, in-progress, done.', 'decker' ) );
		}

		if ( $board <= 0 ) {
			return new WP_Error( 'missing_field', __( 'The board is required and must be a positive integer.', 'decker' ) );
		}

		if ( ! term_exists( $board, 'decker_board' ) ) {

			error_log( 'Invalid default board: "' . esc_html( $board ) . '" does not exist in the decker_board taxonomy.' );
			return new WP_Error( 'invalid', __( 'The board does not exist in the decker_board taxonomy.', 'decker' ) );
		}

		return null;
	}

	/**
	 * Build the tax_input array for a task from its board and labels.
	 *
	 * @param int   $board  The board term ID.
	 * @param array $labels The label term IDs.
	 * @return array The tax_input array.
	 */
	private static function build_task_tax_input( int $board, array $labels ): array {
		$tax_input = array();

		// Assign the 'decker_board' taxonomy with the board ID.
		if ( $board > 0 ) {
			$tax_input['decker_board'] = array( $board );
		}

		// Always set labels — even an empty set — so a save replaces the full label
		// list and can clear every label. Omitting the key would leave the existing
		// labels untouched (a silent merge), breaking "complete state" writes and
		// the ability to deselect all labels in the UI.
		$tax_input['decker_label'] = array_map( 'intval', $labels );

		return $tax_input;
	}

	/**
	 * Build the wp_insert_post/wp_update_post data array for a task.
	 *
	 * @param string        $title         The task title.
	 * @param string        $description   The task description.
	 * @param bool          $archived      Whether the task is archived.
	 * @param int           $author        The author user ID.
	 * @param int           $responsable   The responsable user ID.
	 * @param bool          $hidden        Whether the task is hidden.
	 * @param array         $meta_input    The meta_input array.
	 * @param array         $tax_input     The tax_input array.
	 * @param DateTime|null $creation_date The creation date, or null.
	 * @return array The post data array.
	 */
	private static function build_task_post_data( string $title, string $description, bool $archived, int $author, int $responsable, bool $hidden, array $meta_input, array $tax_input, ?DateTime $creation_date ): array {
		$post_data = array(
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => wp_kses( $description, Decker::get_allowed_tags() ),
			'post_status'  => $archived ? 'archived' : 'publish',
			'post_type'    => 'decker_task',
			'post_author'  => $author,
			'meta_input'   => $meta_input,
			'tax_input'    => $tax_input,
		);

		// Only set `post_date` if `creation_date` is provided.
		if ( $creation_date ) {
			$post_data['post_date'] = $creation_date->format( 'Y-m-d H:i:s' );
		}

		// Only set `responsable` if provided.
		if ( $responsable > 0 ) {
			$post_data['responsable'] = $responsable;
		}

		$post_data['hidden'] = $hidden;

		return $post_data;
	}

	/**
	 * Normalize the assigned users list and compute the newly added users.
	 *
	 * Plucks IDs when WP_User objects are passed so the IDs flow into meta_input.
	 * The newly added users are only computed when the list is non-empty,
	 * preserving the original "no users -> zero decker_user_assigned actions".
	 *
	 * @param int   $id             The task ID, or 0 when creating.
	 * @param array $assigned_users The assigned users (IDs or WP_User objects).
	 * @return array{0:array,1:array} The normalized user IDs and the new users.
	 */
	private static function resolve_assigned_users_and_new( int $id, array $assigned_users ): array {
		$new_users = array();

		if ( ! empty( $assigned_users ) && is_array( $assigned_users ) ) {
			if ( isset( $assigned_users[0] ) && $assigned_users[0] instanceof WP_User ) {
				$assigned_users = wp_list_pluck( $assigned_users, 'ID' );
			}

			// Store previously assigned users before the update.
			$previous_assigned_users = array();
			if ( $id > 0 ) { // Only if updating.
				$previous_assigned_users = get_post_meta( $id, 'assigned_users', true );
				$previous_assigned_users = is_array( $previous_assigned_users ) ? $previous_assigned_users : array();
			}

			// Compare new users with previously assigned ones.
			$new_users = array_diff( $assigned_users, $previous_assigned_users );

		}

		return array( $assigned_users, $new_users );
	}

	/**
	 * Update an existing task and fire the related hooks in order.
	 *
	 * Fires decker_task_updated, decker_task_responsable_changed and
	 * decker_user_assigned even when wp_update_post returns a WP_Error/0,
	 * matching the original behavior (only the stack hooks are guarded).
	 *
	 * @param int    $id          The task ID.
	 * @param array  $post_data   The post data to update.
	 * @param string $stack       The new stack value.
	 * @param int    $responsable The new responsable user ID.
	 * @param array  $new_users   The newly added user IDs.
	 * @return int|WP_Error The wp_update_post result.
	 */
	private static function update_existing_task( int $id, array $post_data, string $stack, int $responsable, array $new_users ) {
		$old_responsable = get_post_meta( $id, 'responsable', true );

		// Retrieve the current stack value as a string.
		$source_stack = get_post_meta( $id, 'stack', true );

				   // Update the existing post.
		$post_data['ID'] = $id;
		$task_id         = wp_update_post( $post_data );

		// Check if the stack value has changed.
		if ( ! is_wp_error( $task_id ) && $source_stack != $stack ) {

			// Trigger general stack transition hook.
			do_action( 'decker_stack_transition', $id, $source_stack, $stack );

			// If the target stack is "done", trigger a specific hook for task completion.
			if ( 'done' === $stack ) {
				do_action( 'decker_task_completed', $id, $stack, get_current_user_id() );
			}
		}

		// Trigger a hook after a task has been updated.
		do_action( 'decker_task_updated', $task_id );

		if ( $old_responsable != $responsable ) {
			do_action( 'decker_task_responsable_changed', $id, (int) $old_responsable, (int) $responsable );
		}

				   // Trigger the event for each new user.
		foreach ( $new_users as $new_user_id ) {
			do_action( 'decker_user_assigned', $task_id, $new_user_id );
		}

		return $task_id;
	}

	/**
	 * Insert a new task and fire the creation hook.
	 *
	 * @param array $post_data The post data to insert.
	 * @return int|WP_Error The wp_insert_post result.
	 */
	private static function insert_new_task( array $post_data ) {
		// Create a new post.
		$task_id = wp_insert_post( $post_data );

		// Trigger a hook after a new task has been created.
		do_action( 'decker_task_created', $task_id );

		return $task_id;
	}
}
