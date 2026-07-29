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
