<?php
/**
 * Task Post Type for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks.
 *
 * Handles the Custom Post Type for tasks in the Decker plugin.
 */
class Decker_Tasks {

	/**
	 * Lazily-instantiated edit-lock manager.
	 *
	 * @var Decker_Task_Locks|null
	 */
	private $task_locks = null;

	/**
	 * Constructor
	 *
	 * Initializes the class by setting up the hooks.
	 */
	public function __construct() {
		$this->define_hooks();

		// The REST transport owns its own hooks, one controller per resource.
		new Decker_Tasks_Rest_Locks( $this );
		new Decker_Tasks_Rest_Today( $this );
		new Decker_Tasks_Rest_Ops( $this );
		new Decker_Tasks_Rest_Tools( $this );

		// Ordering reactions to WordPress events own their own hooks.
		$this->order_engine = new Decker_Task_Order( $this );
		new Decker_Task_Order_Hooks( $this->order_engine );

		// The save paths own their own hooks, one collaborator per path.
		new Decker_Task_Meta_Saver( $this );
		new Decker_Task_Ajax_Save( $this );

		// The edit-screen meta boxes own their own hook.
		new Decker_Task_Meta_Boxes();

		// The admin list table and the edit-screen chrome own their own hooks.
		new Decker_Task_Admin_List();
		new Decker_Task_Admin_Chrome();
	}

	/**
	 * Get the shared task edit-lock manager.
	 *
	 * @return Decker_Task_Locks The lock manager instance.
	 */
	public function get_task_locks(): Decker_Task_Locks {
		if ( ! $this->task_locks instanceof Decker_Task_Locks ) {
			$this->task_locks = new Decker_Task_Locks();
		}

		return $this->task_locks;
	}

	/**
	 * Lazily-instantiated "For today" relation service.
	 *
	 * @var Decker_Task_Today_Manager|null
	 */
	private $today_manager = null;

	/**
	 * Get the shared "For today" relation service.
	 *
	 * @return Decker_Task_Today_Manager The relation service instance.
	 */
	public function get_today_manager(): Decker_Task_Today_Manager {
		if ( ! $this->today_manager instanceof Decker_Task_Today_Manager ) {
			$this->today_manager = new Decker_Task_Today_Manager();
		}

		return $this->today_manager;
	}

	/**
	 * Stack/order engine, created in the constructor.
	 *
	 * @var Decker_Task_Order
	 */
	private $order_engine;

	/**
	 * The stack/order engine for tasks.
	 *
	 * @return Decker_Task_Order
	 */
	public function get_order_engine(): Decker_Task_Order {
		return $this->order_engine;
	}

	/**
	 * Define Hooks.
	 *
	 * Registers all the hooks related to the decker_task custom post type.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 10, 3 );

		add_action( 'init', array( $this, 'register_archived_post_status' ) );
		add_action( 'admin_footer-post.php', array( $this, 'append_post_status_list' ) );
		add_action( 'before_delete_post', array( $this, 'handle_task_deletion' ) );
		add_action( 'transition_post_status', array( $this, 'handle_task_status_change' ), 10, 3 );

		add_filter( 'wp_unique_filename', array( $this, 'custom_unique_filename' ), 10, 4 );

		add_filter( 'post_type_link', array( $this, 'custom_task_permalink' ), 10, 2 );
	}

	/**
	 * Custom function to generate a unique filename.
	 *
	 * This function renames the file if it's attached to a 'decker_task' post.
	 *
	 * @param string   $filename The original filename.
	 * @param string   $ext      The file extension.
	 * @param string   $dir      The directory where the file is being uploaded.
	 * @param callable $unique_filename_callback Callback for unique filename generation.
	 *
	 * @return string The sanitized and unique filename.
	 */
	public function custom_unique_filename( $filename, $ext, $dir, $unique_filename_callback ) {
		if ( ! empty( $_POST['post'] ) ) {
			$post_id = intval( sanitize_text_field( wp_unslash( $_POST['post'] ) ) );

			// If not a REST request, verify the nonce.
			if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				if ( ! isset( $_REQUEST['decker_task_nonce'] ) ||
				 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['decker_task_nonce'] ) ), 'save_decker_task' ) ) {
					return $filename;
				}
			}

			$post_type = get_post_type( $post_id );
			if ( 'decker_task' === $post_type ) {
				return wp_generate_uuid4() . $ext;
			}
		}
		return $filename;
	}

	/**
	 * Handle task deletion to reorder tasks.
	 *
	 * @param int $post_id The ID of the post being deleted.
	 */
	public function handle_task_deletion( $post_id ) {
		if ( 'decker_task' !== get_post_type( $post_id ) ) {
			return;
		}

		$board_term_id = (int) get_post_meta( $post_id, 'decker_board', true );
		$stack         = get_post_meta( $post_id, 'stack', true );
		if ( $board_term_id > 0 && $stack ) {
			$this->get_order_engine()->reorder_tasks_in_stack( $board_term_id, $stack, $post_id );
		}

		do_action( 'decker_task_updated', $post_id ); // Invalidates .ics “all”.
	}

	/**
	 * Handle task status change to reorder tasks.
	 *
	 * @param string  $new_status The new status of the post.
	 * @param string  $old_status The old status of the post.
	 * @param WP_Post $post The post object.
	 */
	public function handle_task_status_change( $new_status, $old_status, $post ) {
		if ( 'decker_task' !== $post->post_type ) {
			return;
		}

		if ( 'archived' === $new_status && 'publish' === $old_status ) {

			$board_term_id = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
			$board_term_id = ! empty( $board_term_id ) ? $board_term_id[0] : 0;

			$stack = get_post_meta( $post->ID, 'stack', true );

			if ( $board_term_id > 0 && $stack ) {
				$this->get_order_engine()->reorder_tasks_in_stack( $board_term_id, $stack, $post->ID );
			}
		}
	}

	/**
	 * Register the decker_task post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'          => _x( 'Tasks', 'post type general name', 'decker' ),
			'singular_name' => _x( 'Task', 'post type singular name', 'decker' ),
			'menu_name'     => _x( 'Decker', 'admin menu', 'decker' ),
			'edit_item'          => __( 'Edit Task', 'decker' ),
			'view_item'          => __( 'View Task', 'decker' ),
			'all_items'          => __( 'All Tasks', 'decker' ),
			'search_items'       => __( 'Search Tasks', 'decker' ),
			'parent_item_colon'  => __( 'Parent Tasks:', 'decker' ),
			'not_found'          => __( 'No tasks found.', 'decker' ),
			'not_found_in_trash' => __( 'No tasks found in Trash.', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-welcome-widgets-menus',
			'supports'           => array(
				'title',
				'editor',
				'revisions',
				'author',
				'custom-fields',
				'comments',
				'excerpt',
				'page-attributes',
			),
			'taxonomies'   => array( 'decker_board', 'decker_label' ),
			'show_in_rest' => true,
			'rest_base'    => 'tasks',
			'can_export'   => true,
		);

		register_post_type( 'decker_task', $args );

		$this->register_task_meta();
	}

	/**
	 * Register the task detail meta fields for the REST API.
	 *
	 * The `decker_task` post type is exposed over REST, but its detail meta was
	 * not registered, so the generic `/wp/v2/tasks` endpoint silently dropped
	 * `stack`, `max_priority` and `duedate` on write and never returned them on
	 * read. Registering them here makes those fields round-trip through REST.
	 *
	 * Writing the `stack` meta stays ordering-consistent because the existing
	 * `added_post_meta` / `updated_post_meta` hooks recompute the task order.
	 */
	private function register_task_meta() {
		$auth_callback = function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		// `stack` is validated with a REST enum schema rather than a
		// sanitize_callback so that only REST writes are constrained; internal
		// meta writes (form save, drag-and-drop reorder) keep their raw values.
		register_post_meta(
			'decker_task',
			'stack',
			array(
				'type'          => 'string',
				'single'        => true,
				'auth_callback' => $auth_callback,
				'show_in_rest'  => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( 'to-do', 'in-progress', 'done' ),
					),
				),
			)
		);

		// Stored internally as '0'/'1' (and '' for legacy rows); the model reads
		// it as `'1' === value`. Exposed as a boolean over REST without a
		// value-altering sanitize_callback so internal writes are untouched.
		register_post_meta(
			'decker_task',
			'max_priority',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => $auth_callback,
			)
		);

		register_post_meta(
			'decker_task',
			'duedate',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'auth_callback'     => $auth_callback,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Restricts REST API access for decker_event post type.
	 *
	 * @param mixed           $result The pre-calculated result to return.
	 * @param WP_REST_Server  $rest_server The REST server instance.
	 * @param WP_REST_Request $request The current REST request.
	 * @return mixed WP_Error if unauthorized, otherwise the original result.
	 */
	public function restrict_rest_access( $result, $rest_server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wp/v2/tasks' ) === 0 ) {
			// Use the specific capability of the CPT.
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to access this resource.', 'decker' ),
					array( 'status' => 403 )
				);
			}
		}

		return $result;
	}

	/**
	 * Register the custom post status "archived".
	 */
	public function register_archived_post_status() {
		register_post_status(
			'archived',
			array(
				'label'                     => _x( 'Archived', 'post status', 'decker' ),
				'public'                    => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of items */
				'label_count' => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'decker' ),
			)
		);
	}

	/**
	 * Append the custom post status "archived" to the post status list.
	 */
	public function append_post_status_list() {
		global $post;
		$complete = '';
		$label    = '';
		if ( 'decker_task' === $post->post_type ) {
			if ( 'archived' === $post->post_status ) {
				$complete = ' selected="selected"';
				$label    = '<span id="post-status-display"> ' . esc_html__( 'Archived', 'decker' ) . '</span>';
			}
			echo '<script>
			jQuery(document).ready(function($){
				jQuery("select#post_status").append("<option value=\"archived\" ' . esc_attr( $complete ) . '>' . esc_html__( 'Archived', 'decker' ) . '</option>");
				if (jQuery("#post_status").val() === "archived") {
			        jQuery("#post-status-display").text("' . esc_html__( 'Archivado', 'decker' ) . '");
			    }


			});
			</script>';
		}
	}

	/**
	 * Creates or updates a task in the Decker system.
	 *
	 * This method handles validation, taxonomy assignments, and metadata management.
	 * for tasks. It can either create a new task or update an existing one.
	 *
	 * @param int           $id                 The ID of the task to update, or 0 to create a new task.
	 * @param string        $title              The title of the task.
	 * @param string        $description        The description of the task.
	 * @param string        $stack              The stack name (e.g., 'to-do', 'in-progress').
	 * @param int           $board              The ID of the board associated with the task.
	 * @param bool          $max_priority       Whether the task has maximum priority.
	 * @param DateTime|null $duedate            The due date of the task, or null if not set.
	 * @param int           $author             The ID of the author of the task.
	 * @param int           $responsable        The ID of the responsable of the task.
	 * @param bool          $hidden             Whether the task is hidden in listings.
	 * @param array         $assigned_users     An array of user IDs assigned to the task.
	 * @param array         $labels             An array of label IDs associated with the task.
	 * @param DateTime      $creation_date      The creation date of the task. Default is null.
	 * @param bool          $archived           Whether the task is archived. Default is false.
	 * @param int           $id_nextcloud_card  The NextCloud card ID associated with the task. Default is 0.
	 *
	 * @return int|WP_Error The ID of the created or updated task on success, or a WP_Error object on failure.
	 */
	public static function create_or_update_task(
		int $id,
		string $title,
		string $description,
		string $stack,
		int $board,
		bool $max_priority,
		?DateTime $duedate,
		int $author,
		int $responsable,
		bool $hidden,
		array $assigned_users,
		array $labels,
		?DateTime $creation_date = null,
		bool $archived = false,
		int $id_nextcloud_card = 0
	) {

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

	/**
	 * Get localized label for a given stack.
	 *
	 * @param string $stack Stack slug.
	 * @return string Stack label.
	 */
	public static function get_stack_label( string $stack ): string {
		switch ( $stack ) {
			case 'to-do':
				return __( 'To-Do', 'decker' );
			case 'in-progress':
				return __( 'In Progress', 'decker' );
			case 'done':
				return __( 'Done', 'decker' );
			default:
				return $stack;
		}
	}

	/**
	 * Get icon classes for a given stack.
	 *
	 * @param string $stack Stack slug.
	 * @return string Icon class list.
	 */
	public static function get_stack_icon_classes( string $stack ): string {
		switch ( $stack ) {
			case 'to-do':
				return 'ri-checkbox-blank-circle-line text-secondary';
			case 'in-progress':
				return 'ri-progress-3-line text-warning';
			case 'done':
				return 'ri-checkbox-circle-line text-success';
			default:
				return '';
		}
	}

	/**
	 * Get HTML icon for a given stack.
	 *
	 * @param string $stack Stack slug.
	 * @return string HTML markup for the stack icon with tooltip.
	 */
	public static function get_stack_icon_html( string $stack ): string {
		$label         = self::get_stack_label( $stack );
		$escaped_label = esc_attr( $label );
		$icon_template = '<i class="%1$s me-2" role="img" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="%2$s" data-bs-original-title="%2$s"></i>';
		$icon_classes  = self::get_stack_icon_classes( $stack );

		if ( '' === $icon_classes ) {
			return esc_html( $stack );
		}

		$icon = sprintf(
			$icon_template,
			$icon_classes,
			$escaped_label
		);

		return $icon . '<span class="visually-hidden">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Customize the permalink for the 'decker_task' post type.
	 *
	 * This function ensures the correct URL format is used whether pretty
	 * permalinks are enabled or not.
	 *
	 * @param string  $post_link The post's permalink.
	 * @param WP_Post $post      The post object.
	 * @return string The customized permalink.
	 */
	public function custom_task_permalink( $post_link, $post ) {
		if ( 'decker_task' === $post->post_type && 'publish' === $post->post_status ) {
			// Check if pretty permalinks are enabled.
			if ( get_option( 'permalink_structure' ) ) {
				// Pretty permalinks are enabled. Build the pretty URL.
				// This matches the ^decker/task/([^/]+)/?$ rewrite rule.
				return home_url( '/decker/task/' . $post->ID . '/' );
			} else {
				// Plain permalinks. Build the query arg URL.
				return home_url( '?decker_task=' . $post->ID );
			}
		}
		return $post_link;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Tasks' ) ) {
	new Decker_Tasks();
}
