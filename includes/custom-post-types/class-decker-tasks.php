<?php
/**
 * Task Post Type and Metaboxes for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Tasks.
 *
 * Handles the Custom Post Type and its metaboxes for tasks in the Decker plugin.
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

		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );
		add_action( 'admin_head', array( $this, 'disable_menu_order_field' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 3 );
		add_action( 'admin_head', array( $this, 'hide_permalink_and_slug' ) );
		add_action( 'admin_head', array( $this, 'change_publish_meta_box_title' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_status' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_taxonomies' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_taxonomy_filters' ) );
		add_action( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );

		add_filter( 'manage_decker_task_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_decker_task_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-decker_task_sortable_columns', array( $this, 'make_columns_sortable' ) );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

		add_action( 'pre_get_posts', array( $this, 'custom_order_by_stack' ) );

		add_action( 'wp_ajax_save_decker_task', array( $this, 'handle_save_decker_task' ) );
		add_action( 'wp_ajax_nopriv_save_decker_task', array( $this, 'handle_save_decker_task' ) );

		add_action( 'admin_menu', array( $this, 'remove_add_new_link' ) );

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
	 * Make custom columns sortable.
	 *
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function make_columns_sortable( $columns ) {
		$columns['stack'] = 'stack';
		return $columns;
	}

	/**
	 * Modify the order of the 'decker_task' post type in the admin when sorting by 'stack'.
	 *
	 * @param WP_Query $query The current query object.
	 */
	public function custom_order_by_stack( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'decker_task' === $query->get( 'post_type' ) && 'stack' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', 'stack' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Add custom columns to the task list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_custom_columns( $columns ) {
		unset( $columns['date'] ); // Remove the date column if needed.
		$columns['stack'] = __( 'Stack', 'decker' );
		return $columns;
	}

	/**
	 * Render custom columns in the task list table.
	 *
	 * @param string $column  The name of the column.
	 * @param int    $post_id The ID of the post.
	 */
	public function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'stack':
				echo esc_html( get_post_meta( $post_id, 'stack', true ) );
				break;
		}
	}

	/**
	 * Remove row actions from the task list table.
	 *
	 * @param array  $actions Existing actions.
	 * @param object $post    The current post object.
	 * @return array Modified actions.
	 */
	public function remove_row_actions( $actions, $post ) {
		if ( 'decker_task' === $post->post_type ) {
			return array(); // Remove all actions.
		}
		return $actions;
	}

	/**
	 * Remove 'Add New' button for decker_task post type.
	 */
	public function remove_add_new_link() {
		global $submenu;
		// Remove the "Add New" submenu link.
		if ( isset( $submenu['edit.php?post_type=decker_task'] ) ) {
			foreach ( $submenu['edit.php?post_type=decker_task'] as $key => $item ) {
				// Searches for the "Add New Entry" item.
				if ( 'post-new.php?post_type=decker_task' === $item[2] ) {
					unset( $submenu['edit.php?post_type=decker_task'][ $key ] );
				}
			}
		}
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
	 * Add a user-date relation for a task.
	 *
	 * @param int $task_id The task ID.
	 * @param int $user_id The user ID.
	 */
	public function add_user_date_relation( int $task_id, int $user_id ) {
		$this->get_today_manager()->mark_for_today( $task_id, $user_id );
	}

	/**
	 * Remove today's user-date relation for a task.
	 *
	 * @param int $task_id The task ID.
	 * @param int $user_id The user ID.
	 */
	public function remove_user_date_relation( int $task_id, int $user_id ) {
		$this->get_today_manager()->unmark_for_today( $task_id, $user_id );
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
	 * Filter tasks to show only published by default in the admin list.
	 *
	 * @param WP_Query $query The current query object.
	 */
	public function filter_tasks_by_status( $query ) {
		global $pagenow;
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( 'edit.php' === $pagenow && 'decker_task' === $post_type && ! isset( $_GET['post_status'] ) ) {
			$query->set( 'post_status', 'publish' );
		}
	}

	/**
	 * Add metaboxes for the decker_task post type.
	 */
	public function add_meta_boxes() {

		// Remove default taxonomy metaboxes.
		remove_meta_box( 'tagsdiv-decker_board', 'decker_task', 'side' );
		remove_meta_box( 'tagsdiv-decker_label', 'decker_task', 'side' );

		add_meta_box(
			'decker_task_meta_box',
			__( 'Task Details', 'decker' ),
			array( $this, 'display_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_users_meta_box',
			__( 'Assigned Users', 'decker' ),
			array( $this, 'display_users_meta_box' ),
			'decker_task',
			'side',
			'default'
		);

		add_meta_box(
			'user_date_meta_box',
			__( 'Task User and Date', 'decker' ),
			array( $this, 'display_user_date_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'attachment_meta_box',
			__( 'Attachments', 'decker' ),
			array( $this, 'display_attachment_meta_box' ),
			'decker_task',
			'normal',
			'high'
		);

		add_meta_box(
			'decker_labels_meta_box',
			__( 'Labels', 'decker' ),
			array( $this, 'display_labels_meta_box' ),
			'decker_task',
			'side',
			'default'
		);

		add_meta_box(
			'decker_board_meta_box',
			__( 'Board', 'decker' ),
			array( $this, 'display_board_meta_box' ),
			'decker_task',
			'side',
			'default'
		);
	}

	/**
	 * Display the task details meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_meta_box( $post ) {
		$duedate           = get_post_meta( $post->ID, 'duedate', true );
		$max_priority      = get_post_meta( $post->ID, 'max_priority', true );
		$stack             = get_post_meta( $post->ID, 'stack', true );
		$id_nextcloud_card = get_post_meta( $post->ID, 'id_nextcloud_card', true );
		$responsable       = get_post_meta( $post->ID, 'responsable', true );
		$hidden            = get_post_meta( $post->ID, 'hidden', true );

		wp_nonce_field( 'save_decker_task', 'decker_task_nonce' );

		?>
		<p>
			<label for="duedate"><?php esc_html_e( 'Due Date', 'decker' ); ?></label>
			<input type="date" name="duedate" value="<?php echo esc_attr( $duedate ); ?>" class="widefat">
		</p>
		<p>
			<label for="max_priority"><?php esc_html_e( 'Max Priority', 'decker' ); ?></label>
			<input type="checkbox" name="max_priority" value="1" <?php checked( '1', $max_priority ); ?> class="widefat">
		</p>
		<p>
			<label for="stack"><?php esc_html_e( 'Stack', 'decker' ); ?></label>
			<select name="stack" class="widefat">
				<option value="to-do" <?php selected( 'to-do', $stack ); ?>><?php esc_html_e( 'To-Do', 'decker' ); ?></option>
				<option value="in-progress" <?php selected( 'in-progress', $stack ); ?>><?php esc_html_e( 'In Progress', 'decker' ); ?></option>
				<option value="done" <?php selected( 'done', $stack ); ?>><?php esc_html_e( 'Done', 'decker' ); ?></option>
			</select>
		</p>
		<p>
			<label for="id_nextcloud_card"><?php esc_html_e( 'Nextcloud Card ID', 'decker' ); ?></label>
			<input type="number" name="id_nextcloud_card" value="<?php echo esc_attr( $id_nextcloud_card ); ?>" class="widefat">
		</p>

		<p>
			<label for="responsable"><?php esc_html_e( 'Responsable', 'decker' ); ?></label>
			<input type="number" name="responsable" value="<?php echo esc_attr( $responsable ); ?>" class="widefat">
		</p>

		<p>
			<label for="hidden"><?php esc_html_e( 'Hidden', 'decker' ); ?></label>
			<input type="checkbox" name="hidden" value="1" <?php checked( '1', $hidden ); ?> class="widefat">
		</p>

		<?php
	}

	/**
	 * Display the Labels meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_labels_meta_box( $post ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);
		$assigned_labels = wp_get_post_terms( $post->ID, 'decker_label', array( 'fields' => 'ids' ) );
		$assigned_labels = is_array( $assigned_labels ) ? $assigned_labels : array();
		?>
		<div id="decker-labels" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $terms as $term ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="decker_labels[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( is_array( $assigned_labels ) && in_array( $term->term_id, $assigned_labels ) ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Display the Board meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_board_meta_box( $post ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		$assigned_board = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
		$assigned_board = ! empty( $assigned_board ) ? $assigned_board[0] : '';
		?>
		<select name="decker_board" id="decker_board" class="widefat">
			<option value=""><?php esc_html_e( 'Select Board', 'decker' ); ?></option>
			<?php foreach ( $terms as $term ) { ?>
				<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $assigned_board, $term->term_id ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php } ?>
		</select>
		<?php
	}


	/**
	 * Display the users meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_users_meta_box( $post ) {
		$users          = get_users( array( 'orderby' => 'display_name' ) );
		$assigned_users = get_post_meta( $post->ID, 'assigned_users', true );
		?>
		<div id="assigned-users" class="categorydiv">
			<ul class="categorychecklist form-no-clear">
				<?php foreach ( $users as $user ) { ?>
					<li>
						<label class="selectit">
							<input type="checkbox" name="assigned_users[]" value="<?php echo esc_attr( $user->ID ); ?>" <?php checked( is_array( $assigned_users ) && in_array( $user->ID, $assigned_users ) ); ?>>
							<?php echo esc_html( $user->display_name ); ?>
						</label>
					</li>
				<?php } ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Display the user and date meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_user_date_meta_box( $post ) {
		// Retrieve existing relations from post meta; initialize as empty array if none exist.
		$relations = get_post_meta( $post->ID, '_user_date_relations', true );
		$relations = is_array( $relations ) ? $relations : array();

		// Retrieve all users to populate the select dropdown.
		$users = get_users();
		?>
		<div id="user-date-meta-box">
			<!-- User Selection -->
			<p>
				<label for="assigned_user"><?php esc_html_e( 'Assign User:', 'decker' ); ?></label>
				<select id="assigned_user" class="widefat">
					<option value=""><?php esc_html_e( '-- Select User --', 'decker' ); ?></option>
					<?php foreach ( $users as $user ) { ?>
						<option value="<?php echo esc_attr( $user->ID ); ?>">
							<?php echo esc_html( $user->display_name ); ?>
						</option>
					<?php } ?>
				</select>
			</p>
			
			<!-- Date Selection -->
			<p>
				<label for="assigned_date"><?php esc_html_e( 'Assign Date:', 'decker' ); ?></label>
				<input type="date" id="assigned_date" class="widefat" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
			</p>
			
			<!-- Add Relation Button -->
			<p>
				<button type="button" class="button" id="add-user-date-relation"><?php esc_html_e( 'Add Relation', 'decker' ); ?></button>
			</p>
			
			<!-- Relations List -->
			<ul id="user-date-relations-list">
				<?php $this->render_user_date_relations_list( $relations ); ?>
			</ul>
		</div>

		<!-- Inline JavaScript for Meta Box Functionality -->
		<?php
		$this->render_user_date_meta_box_script();
	}

	/**
	 * Render the existing user-date relations as list items.
	 *
	 * @param array $relations The list of user-date relations.
	 */
	private function render_user_date_relations_list( array $relations ) {
		foreach ( $relations as $relation ) {
			// Safely retrieve user data.
			$user         = get_userdata( $relation['user_id'] );
			$display_name = $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown User', 'decker' );
			$date         = esc_html( $relation['date'] );
			?>
					<li data-user-id="<?php echo esc_attr( $relation['user_id'] ); ?>" data-date="<?php echo esc_attr( $relation['date'] ); ?>">
						<?php echo esc_html( $display_name ) . ' - ' . esc_html( $date ); ?>
						<button type="button" class="button remove-relation"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
					</li>
				<?php
		}
	}

	/**
	 * Render the inline JavaScript for the user-date meta box.
	 */
	private function render_user_date_meta_box_script() {
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			const addBtn = document.getElementById('add-user-date-relation');
			const userSelect = document.getElementById('assigned_user');
			const dateInput = document.getElementById('assigned_date');
			const relationsList = document.getElementById('user-date-relations-list');

			// Add Relation Button Click Event
			addBtn.addEventListener('click', function () {
				const userId = userSelect.value;
				const userName = userSelect.options[userSelect.selectedIndex].text;
				const date = dateInput.value;

				// Validate user selection and date input.
				if (!userId || !date) {
					alert('<?php echo esc_js( __( 'Please select a user and date.', 'decker' ) ); ?>');
					return;
				}

				// Check if the user is already added with the same date.
				const existing = Array.from(relationsList.children).some(item =>
					item.getAttribute('data-user-id') === userId && item.getAttribute('data-date') === date
				);
				if (existing) {
					alert('<?php echo esc_js( __( 'This user and date combination already exists.', 'decker' ) ); ?>');
					return;
				}

				// Create a new list item for the relation.
				const listItem = document.createElement('li');
				listItem.setAttribute('data-user-id', userId);
				listItem.setAttribute('data-date', date);
				listItem.innerHTML = `
					${userName} - ${date}
					<button type="button" class="button remove-relation"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>
				`;
				relationsList.appendChild(listItem);

				// Add event listener to the remove button.
				listItem.querySelector('.remove-relation').addEventListener('click', function () {
					listItem.remove();
				});

				// Reset the select and date input.
				userSelect.value = '';
				dateInput.value = '';
			});

			// Add event listeners to existing remove buttons
			document.querySelectorAll('.remove-relation').forEach(button => {
				button.addEventListener('click', function () {
					button.parentElement.remove();
				});
			});

			// Add hidden fields to the form when saving the post.
			document.getElementById('post').addEventListener('submit', function () {
				// Remove any existing hidden inputs to prevent duplicates.
				const existingInput = document.querySelector('input[name="user_date_relations"]');
				if (existingInput) {
					existingInput.remove();
				}

				const relations = [];
				document.querySelectorAll('#user-date-relations-list li').forEach(item => {
					relations.push({
						user_id: item.getAttribute('data-user-id'),
						date: item.getAttribute('data-date')
					});
				});

				const hiddenInput = document.createElement('input');
				hiddenInput.type = 'hidden';
				hiddenInput.name = 'user_date_relations';
				hiddenInput.value = JSON.stringify(relations);
				this.appendChild(hiddenInput);
			});
		});
		</script>
		<?php
	}

	/**
	 * Displays the attachment meta box for the 'decker_task' post type.
	 *
	 * This meta box allows users to view, add, and remove attachments for a specific task.
	 * The attachments are displayed as a list with options to remove them, and users can
	 * add new attachments via the WordPress media library modal.
	 *
	 * @param WP_Post $post The current post object for which the meta box is displayed.
	 *
	 * @return void Outputs the HTML and JavaScript for managing attachments.
	 */
	public function display_attachment_meta_box( $post ) {
		// Retrieve existing attachments linked to post.
		$attachments = get_attached_media( '', $post->ID );

		// Include the nonce field for security.
		wp_nonce_field( 'save_decker_task', 'decker_task_nonce' );
		?>
	<div id="attachments-meta-box">
		<!-- Button to open the media library modal -->
		<p>
			<button type="button" class="button" id="add-attachments"><?php esc_html_e( 'Add Attachments', 'decker' ); ?></button>
		</p>
		
		<!-- List of attached media -->
		<ul id="attachments-list">
			<?php
			foreach ( $attachments as $attachment ) :
				$attachment_url   = $attachment->guid;
				$attachment_title = $attachment->post_title;
				$file_extension   = pathinfo( $attachment_url, PATHINFO_EXTENSION );
				$file_name        = $attachment->post_title . '.' . $file_extension;

				?>
				<li data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
					<a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank"><?php echo esc_html( $file_name ); ?></a>
 
					<button type="button" class="button remove-attachment"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
					<!-- Hidden input to store attachment IDs -->
					<input type="hidden" name="attachments[]" value="<?php echo esc_attr( $attachment->ID ); ?>">
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<!-- JavaScript to handle the media library modal -->
	<script>
	jQuery(document).ready(function($){
		var frame;
		jQuery('#add-attachments').on('click', function(e){
			e.preventDefault();
			// If the media frame already exists, reopen it.
			if ( frame ) {
				frame.open();
				return;
			}
			// Create a new media frame.
			frame = wp.media({
				title: '<?php echo esc_js( __( 'Select Attachments', 'decker' ) ); ?>',
				button: {
					text: '<?php echo esc_js( __( 'Add Attachments', 'decker' ) ); ?>',
				},
				multiple: true // Set to true to allow multiple files to be selected.
			});
			// When an attachment is selected, run a callback.
			frame.on( 'select', function() {
				var attachments = frame.state().get('selection').toJSON();
				attachments.forEach(function(attachment){
					// Append the selected attachments to the list.
					jQuery('#attachments-list').append(
						'<li data-attachment-id="' + attachment.id + '">' +
							'<a href="' + attachment.url + '" target="_blank">' + attachment.title + '</a> ' +
							'<button type="button" class="button remove-attachment"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>' +
							'<input type="hidden" name="attachments[]" value="' + attachment.id + '">' +
						'</li>'
					);
				});
			});
			// Finally, open the modal.
			frame.open();
		});
		// Handle removal of attachments.
		jQuery('#attachments-list').on('click', '.remove-attachment', function(){
			jQuery(this).closest('li').remove();
		});
	});
	</script>
		<?php
	}

	/**
	 * Save the custom meta fields.
	 *
	 * @param int     $post_id The current post ID.
	 * @param WP_Post $post The current post.
	 * @param bool    $update If we are updating.
	 */
	public function save_meta( $post_id, $post, $update ) {

		// Bail out early when the request must not modify task meta.
		if ( ! $this->can_save_task_meta( $post_id ) ) {
			return $post_id;
		}

		// Enforce the edit lock so a stale admin session cannot overwrite newer
		// changes after another user has taken over editing.
		if ( is_wp_error( $this->get_task_locks()->assert_user_can_save( $post_id, get_current_user_id() ) ) ) {
			return $post_id;
		}

		// The order of these calls is load-bearing: writing the 'stack' meta and the
		// 'decker_board' term trigger reorder hooks mid-save, so details must run
		// before taxonomies, and both before users and relations.
		$this->save_task_detail_fields( $post_id );
		$this->save_task_taxonomies( $post_id );
		$this->save_task_assigned_users( $post_id );
		$this->save_task_user_date_relations( $post_id );
	}

	/**
	 * Determine whether the current request is allowed to save task meta.
	 *
	 * @param int $post_id The current post ID.
	 * @return bool True when the meta may be saved, false otherwise.
	 */
	private function can_save_task_meta( int $post_id ): bool {
		// Check if nonce is set and verified.
		if ( ! isset( $_POST['decker_task_nonce'] ) ) {
			return false; // Exit if the nonce is not set.
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['decker_task_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'save_decker_task' ) ) {
			return false; // Exit if the nonce verification fails.
		}

		// Check autosave and post type.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( ! isset( $_POST['post_type'] ) || 'decker_task' !== $_POST['post_type'] ) {
			return false;
		}

		// Check the user's permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// Prevent changes if the task is archived.
		if ( 'archived' === get_post_status( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Save the task detail meta fields (duedate, max_priority, stack, nextcloud card).
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_detail_fields( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['duedate'] ) ) {
			$duedate = sanitize_text_field( wp_unslash( $_POST['duedate'] ) );
			update_post_meta( $post_id, 'duedate', $duedate );
		}
		$max_priority = isset( $_POST['max_priority'] ) ? '1' : '';
		update_post_meta( $post_id, 'max_priority', $max_priority );
		if ( isset( $_POST['stack'] ) ) {
			$stack = sanitize_text_field( wp_unslash( $_POST['stack'] ) );
			update_post_meta( $post_id, 'stack', $stack );
		}
		if ( isset( $_POST['id_nextcloud_card'] ) ) {
			update_post_meta( $post_id, 'id_nextcloud_card', sanitize_text_field( wp_unslash( $_POST['id_nextcloud_card'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save the task taxonomies (labels first, then board), both as slugs.
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_taxonomies( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['decker_labels'] ) ) {
			$labels      = array_map( 'sanitize_text_field', wp_unslash( $_POST['decker_labels'] ) );
			$label_slugs = array();
			foreach ( $labels as $label_id ) {
				$term = get_term( $label_id, 'decker_label' );
				if ( $term && ! is_wp_error( $term ) ) {
					$label_slugs[] = $term->slug;
				}
			}
			wp_set_post_terms( $post_id, $label_slugs, 'decker_label' );
		}
		if ( isset( $_POST['decker_board'] ) ) {
			$board_id   = sanitize_text_field( wp_unslash( $_POST['decker_board'] ) );
			$board_term = get_term( $board_id, 'decker_board' );
			if ( $board_term && ! is_wp_error( $board_term ) ) {
				wp_set_post_terms( $post_id, array( $board_term->slug ), 'decker_board' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save the assigned users meta when posted.
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_assigned_users( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['assigned_users'] ) ) {
			$assigned_users = array_map( 'intval', wp_unslash( $_POST['assigned_users'] ) );
			update_post_meta( $post_id, 'assigned_users', $assigned_users );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Decode and unconditionally save the user-date relations meta.
	 *
	 * The relations meta is always written (an empty array when the field is
	 * absent) so previous relations are cleared. The nonce has already been
	 * verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_user_date_relations( int $post_id ) {
		// Save user date relations.
		$relations = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['user_date_relations'] ) ) {
			// Remove slashes added by WordPress.
			$relations_json = sanitize_text_field( wp_unslash( $_POST['user_date_relations'] ) );

			// Decode the JSON using PHP's json_decode function.
			$decoded_relations = json_decode( $relations_json, true );

			// Verify that the decoding returned a valid array.
			if ( is_array( $decoded_relations ) ) {
				$relations = $decoded_relations;
			} else {
				// Handle JSON decoding errors if necessary.
				// You can log the error or display a message.
				error_log( 'JSON decoding failed: ' . json_last_error_msg() );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_post_meta( $post_id, '_user_date_relations', $relations );
	}

	/**
	 * Hide the permalink and slug for decker_task.
	 */
	public function hide_permalink_and_slug() {
		$screen = get_current_screen();
		if ( $screen && 'decker_task' == $screen->post_type && 'post' == $screen->base ) {
			echo '<style type="text/css">
				#edit-slug-box, #post-name { display: none; }
			</style>';
		}
	}

	/**
	 * Disables the menu_order field in the admin interface for decker_task.
	 */
	public function disable_menu_order_field() {
		$screen = get_current_screen();
		if ( $screen && 'decker_task' === $screen->post_type && 'post' === $screen->base ) {
			?>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					// Disable the menu_order field.
					var menuOrderField = document.getElementById('menu_order');
					if (menuOrderField) {
						menuOrderField.disabled = true;
					}
				});
			</script>
			<?php
		}
	}

	/**
	 * Filter tasks by taxonomies.
	 *
	 * @param WP_Query $query The current query object.
	 */
	public function filter_tasks_by_taxonomies( $query ) {
		global $pagenow;
		$qv = &$query->query_vars;
		if ( 'edit.php' == $pagenow && isset( $qv['post_type'] ) && 'decker_task' == $qv['post_type'] ) {
			$this->map_taxonomy_filter_to_slug( $qv, 'decker_board' );
			$this->map_taxonomy_filter_to_slug( $qv, 'decker_label' );
		}
	}

	/**
	 * Replace a numeric taxonomy query var with the matching term slug in place.
	 *
	 * @param array  $qv       The query vars array, passed by reference for in-place mutation.
	 * @param string $taxonomy The taxonomy name.
	 */
	private function map_taxonomy_filter_to_slug( array &$qv, string $taxonomy ) {
		if ( isset( $qv[ $taxonomy ] ) && is_numeric( $qv[ $taxonomy ] ) && 0 != $qv[ $taxonomy ] ) {
			$term = get_term_by( 'id', $qv[ $taxonomy ], $taxonomy );
			if ( $term ) {
				$qv[ $taxonomy ] = $term->slug;
			}
		}
	}

	/**
	 * Add taxonomy filters to the admin posts list.
	 */
	public function add_taxonomy_filters() {
		global $typenow;
		if ( 'decker_task' == $typenow ) {
			$this->add_taxonomy_filter( 'decker_board', __( 'Show All Boards', 'decker' ) );
			$this->add_taxonomy_filter( 'decker_label', __( 'Show All Labels', 'decker' ) );
		}
	}

	/**
	 * Add a taxonomy filter to the admin posts list.
	 *
	 * @param string $taxonomy The taxonomy name.
	 * @param string $label    The label for the dropdown.
	 */
	private function add_taxonomy_filter( $taxonomy, $label ) {
		$selected      = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
		$info_taxonomy = get_taxonomy( $taxonomy );
		wp_dropdown_categories(
			array(
				'show_option_all' => $label . $info_taxonomy->label,
				'taxonomy'        => $taxonomy,
				'name'            => $taxonomy,
				'orderby'         => 'name',
				'selected'        => $selected,
				'show_count'      => true,
				'hide_empty'      => true,
			)
		);
	}

	/**
	 * Disable Gutenberg editor for decker_task.
	 *
	 * @param bool   $current_status The current status.
	 * @param string $post_type      The post type.
	 * @return bool The modified status.
	 */
	public function disable_gutenberg( $current_status, $post_type ) {
		if ( 'decker_task' === $post_type ) {
			return false;
		}
		return $current_status;
	}

	/**
	 * Changes the title of the publish meta box for the 'decker_task' post type.
	 *
	 * Updates the title of the publish meta box to "Status" using JavaScript
	 * when editing or creating a task of the 'decker_task' post type.
	 *
	 * @return void Outputs a script to modify the meta box title dynamically.
	 */
	public function change_publish_meta_box_title() {
		global $post_type;
		if ( 'decker_task' === $post_type ) {
			echo '<script>
	            jQuery(document).ready(function($) {
	                jQuery("#submitdiv .hndle").text("' . esc_html__( 'Status', 'decker' ) . '");
	            });
	        </script>';
		}
	}

	/**
	 * Hide visibility options for decker_task post type.
	 */
	public function hide_visibility_options() {
		global $post_type;
		if ( 'decker_task' == $post_type ) {

			echo '<style type="text/css">
	            .misc-pub-section.misc-pub-visibility {
	                display: none;
	            }
		        /* hide the parent of the group of password and private */
		        .inline-edit-group.wp-clearfix .inline-edit-password-input,
		        .inline-edit-group.wp-clearfix .inline-edit-private,
		        .inline-edit-group.wp-clearfix .inline-edit-or,
		        .inline-edit-group.wp-clearfix {
		            display: none;
		        }
		        .page-title-action {
	               	display: none !important;
	            }
			</style>';
		}
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
		$core    = $this->read_task_core_fields();
		$options = $this->read_task_option_fields();

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

		$duedate = $this->parse_task_due_date( $options['duedate_raw'] );

		$mark_for_today = $options['mark_for_today'];

		// Handle assignees.
		$assigned_users = $this->read_id_list_field( 'assignees' );

		// Handle labels.
		$labels = $this->read_id_list_field( 'labels' );

		// Call the common function to create or update the task.
		$result = self::create_or_update_task(
			$core['id'],
			$core['title'],
			$core['description'],
			$core['stack'],
			$core['board'],
			$options['max_priority'],
			$duedate,
			$options['author'],
			$options['responsable'],
			$options['hidden'],
			$assigned_users,
			$labels
		);

		if ( is_wp_error( $result ) ) {
			return $this->fail_save( array( 'message' => $result->get_error_message() ), $send_response );
		}

		// Set today.
		if ( $mark_for_today ) {
			$this->add_user_date_relation( $result, get_current_user_id() );
		} else {
			$this->remove_user_date_relation( $result, get_current_user_id() );
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
		$lock_check = $this->get_task_locks()->assert_user_can_save(
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

		$rotated = $this->get_task_locks()->rotate_generation( $task_id, get_current_user_id(), $lock_generation );

		return false !== $rotated ? $rotated : '';
	}

	/**
	 * Read and sanitize the core task fields from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @return array{id:int,title:string,description:string,stack:string,board:int}
	 */
	private function read_task_core_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'id'          => isset( $_POST['task_id'] ) ? intval( wp_unslash( $_POST['task_id'] ) ) : 0,
			'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? wp_kses( wp_unslash( $_POST['description'] ), Decker::get_allowed_tags() ) : '',
			'stack'       => isset( $_POST['stack'] ) ? sanitize_text_field( wp_unslash( $_POST['stack'] ) ) : '',
			'board'       => isset( $_POST['board'] ) ? intval( wp_unslash( $_POST['board'] ) ) : 0,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read and sanitize the optional task fields from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @return array{max_priority:bool,mark_for_today:bool,author:int,responsable:int,hidden:bool,duedate_raw:string,lock_generation:string|null}
	 */
	private function read_task_option_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$max_priority = isset( $_POST['max_priority'] ) ? boolval( wp_unslash( $_POST['max_priority'] ) ) : false;

		$duedate_raw = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';

		$mark_for_today = isset( $_POST['mark_for_today'] ) ? boolval( wp_unslash( $_POST['mark_for_today'] ) ) : false;

		$author = isset( $_POST['author'] ) ? intval( wp_unslash( $_POST['author'] ) ) : get_current_user_id();
		$responsable = isset( $_POST['responsable'] ) ? intval( wp_unslash( $_POST['responsable'] ) ) : $author;

		$hidden = isset( $_POST['hidden'] ) ? boolval( wp_unslash( $_POST['hidden'] ) ) : false;

		// Session generation token from the editor form; null when the client did not send it.
		$lock_generation = null;
		if ( isset( $_POST['lock_generation'] ) ) {
			$lock_generation_raw = sanitize_text_field( wp_unslash( $_POST['lock_generation'] ) );
			if ( '' !== $lock_generation_raw ) {
				$lock_generation = $lock_generation_raw;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'max_priority'    => $max_priority,
			'mark_for_today'  => $mark_for_today,
			'author'          => $author,
			'responsable'     => $responsable,
			'hidden'          => $hidden,
			'duedate_raw'     => $duedate_raw,
			'lock_generation' => $lock_generation,
		);
	}

	/**
	 * Parse a raw due-date string into a DateTime, falling back to today on failure.
	 *
	 * @param string $duedate_raw The raw due-date string.
	 * @return DateTime The parsed date, or today when the value is invalid.
	 */
	private function parse_task_due_date( string $duedate_raw ): DateTime {
		try {
			return new DateTime( $duedate_raw );
		} catch ( Exception $e ) {
			return new DateTime(); // Default value if conversion fails.
		}
	}

	/**
	 * Read a comma-separated or array ID list field from the AJAX request.
	 *
	 * The nonce is verified by the caller when the response filter is enabled.
	 *
	 * @param string $field The $_POST field name.
	 * @return array<int> The list of absint IDs, or an empty array when absent.
	 */
	private function read_id_list_field( string $field ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $field ] ) ) {
			return array();
		}

		// Remove backslashes added by WordPress.
		$raw = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_string( $raw ) ) {
			return array_map( 'absint', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			return array_map( 'absint', $raw );
		}

		return array();
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
