<?php
/**
 * Task Post Type and Metaboxes for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Tasks
 *
 * Handles the Custom Post Type and its metaboxes for tasks in the Decker plugin.
 */
class Decker_Tasks {

	/**
	 * Constructor
	 *
	 * Initializes the class by setting up the hooks.
	 */
	public function __construct() {
		$this->define_hooks();

	}

	/**
	 * Define Hooks
	 *
	 * Registers all the hooks related to the decker_task custom post type.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_archived_post_status' ) );
		add_action( 'admin_footer-post.php', array( $this, 'append_post_status_list' ) );
		add_action( 'before_delete_post', array( $this, 'handle_task_deletion' ) );
		add_action( 'transition_post_status', array( $this, 'handle_task_status_change' ), 10, 3 );

		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
		add_action( 'admin_head', array( $this, 'hide_permalink_and_slug' ) );
		add_action( 'admin_head', array( $this, 'change_publish_meta_box_title' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_status' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_taxonomies' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_taxonomy_filters' ) );
		add_action( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_save_decker_task', array( $this, 'handle_save_task' ) );
		add_filter( 'manage_decker_task_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_decker_task_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-decker_task_sortable_columns', array( $this, 'make_columns_sortable' ) );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

		add_filter( 'wp_insert_post_data', array( $this, 'modify_task_order_before_save' ), 10, 2 );

		add_action( 'pre_get_posts', array( $this, 'custom_order_by_stack' ) );

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
		unset( $columns['date'] ); // Remove the date column if needed
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
			return array(); // Remove all actions
		}
		return $actions;
	}

	/**
	 * Get the new order for a task in a specific stack.
	 *
	 * This function retrieves the maximum menu_order value for tasks in the specified board and stack
	 * and returns the next incremented value.
	 *
	 * @param string $board_term_id The board to calculate the order for.
	 * @param string $stack The stack to calculate the order for.
	 * @return int The new order value.
	 */
	private function get_new_task_order( $board_term_id, $stack ) {
	    // Query arguments to find posts in the specified stack
	    $args = array(
	        'post_type'      => 'decker_task',
	        'post_status'    => 'publish',
		    'tax_query'      => array(
		        array(
		            'taxonomy' => 'decker_board',
		            'field'    => 'term_id',
		            'terms'    => $board_term_id,
		        ),
		    ),
		    'meta_query'     => array(
		        array(
		            'key'     => 'stack',
		            'value'   => $stack,
		            'compare' => '='
		        ),
		    ),
	        'orderby'        => 'menu_order',
	        'order'          => 'DESC',
	        'posts_per_page' => 1,
	        'fields'         => 'ids',
	    );

	    // Get the posts
	    $posts = get_posts( $args );

	    // If a post exists, get its menu_order and increment it
	    if ( ! empty( $posts ) ) {
	        $max_order = get_post_field( 'menu_order', $posts[0] );
	        return $max_order + 1;
	    }

	    // If no posts exist, start with order 1
	    return 1;
	}

	/**
	 * Update the stack and order of a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function update_task_stack( $request ) {
		$task_id    = $request['id'];
		$new_stack  = $request->get_param( 'stack' );
		$new_order  = $request->get_param( 'order' );

		$valid_stacks = array( 'to-do', 'in-progress', 'done' );

		if ( ! in_array( $new_stack, $valid_stacks ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid stack value.' ), 400 );
		}

		if ( ! $task_id || ! $new_stack ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'message' => 'Task not found.' ), 404 );
		}

		// Update the stack
		update_post_meta( $task_id, 'stack', $new_stack );

		// Set default order if not provided
		if ( empty( $new_order ) ) {
			$new_order = 1;
		}

		// Reorder tasks in the new stack
		$result = $this->reorder_tasks( $new_stack, $task_id, $new_order );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'status'  => 'success',
				'message' => 'Task stack and order updated successfully.',
			),
			200
		);
	}


	/**
	 * Reorder tasks within a stack and board after a task is deleted.
	 *
	 * @param string $board_term_id The board term ID.
	 * @param string $stack The stack to reorder.
	 */
	private function reorder_tasks_in_stack( $board_term_id, $stack ) {

	    // Fetch all tasks in the current board and stack, ordered by menu_order.
	    $tasks_in_stack = get_posts(
	        array(
	            'post_type'      => 'decker_task',
	            'post_status'    => 'publish',
	            'tax_query'      => array(
	                array(
	                    'taxonomy' => 'decker_board',
	                    'field'    => 'term_id',
	                    'terms'    => $board_term_id,
	                ),
	            ),
				'meta_key'    => 'max_priority', // Definir el campo meta para ordenar
				'meta_type' => 'BOOL',
	            'meta_query'     => array(
	                array(
	                    'key'     => 'stack',
	                    'value'   => $stack,
	                    'compare' => '=',
	                ),
	            ),
				'orderby'     => array(
					'max_priority' => 'DESC',
					'menu_order'   => 'ASC',
				),
	            'numberposts'    => -1,
	            'fields'         => 'ids', // Fetch only the post IDs for better performance

	        )
	    );

	    // Reassign menu_order for each task.
	    foreach ( $tasks_in_stack as $index => $task ) {
	        wp_update_post(
	            array(
	                'ID'         => $task->ID,
	                'menu_order' => $index + 1,
	            )
	        );
	    }
	}




	private function reorder_tasks_via_sql( $board_term_id, $stack, $exclude_post_id = null ) {
	    global $wpdb;

	    // Consulta para obtener los IDs de los posts ordenados por `menu_order`.
	    $query = $wpdb->prepare(
	        "
	        SELECT p.ID
	        FROM $wpdb->posts p
	        INNER JOIN $wpdb->term_relationships tr ON p.ID = tr.object_id
	        INNER JOIN $wpdb->term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
	        WHERE p.post_type = 'decker_task'
	          AND p.post_status = 'publish'
	          AND tt.term_id = %d
	          AND EXISTS (
	              SELECT 1 FROM $wpdb->postmeta pm
	              WHERE pm.post_id = p.ID
	                AND pm.meta_key = 'stack'
	                AND pm.meta_value = %s
	          )
	        ORDER BY p.menu_order ASC
	        ",
	        $board_term_id,
	        $stack
	    );

	    $task_ids = $wpdb->get_col( $query );

	    // Excluir el post, si es necesario.
	    if ( $exclude_post_id !== null ) {
	        $task_ids = array_filter( $task_ids, function( $id ) use ( $exclude_post_id ) {
	            return $id != $exclude_post_id;
	        });
	    }

	    // Actualizar `menu_order` en una sola consulta.
	    foreach ( $task_ids as $index => $task_id ) {
	        $wpdb->update(
	            $wpdb->posts,
	            array( 'menu_order' => $index + 1 ),
	            array( 'ID' => $task_id ),
	            array( '%d' ),
	            array( '%d' )
	        );
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

	    $board_term_id = get_post_meta( $post_id, 'decker_board', true );
	    $stack = get_post_meta( $post_id, 'stack', true );
	    if ( $stack ) {
	        $this->reorder_tasks_in_stack( $board_term_id, $stack );
	    }
	}

	/**
	 * Handle task status change to reorder tasks.
	 *
	 * @param string  $new_status The new status of the post.
	 * @param string  $old_status The old status of the post.
	 * @param WP_Post $post The post object.
	 */
	public function handle_task_status_change( $new_status, $old_status, $post ) {
		if ( $post->post_type !== 'decker_task' ) {
			return;
		}

		if ( $new_status === 'archived' || $old_status === 'archived' ) {
		    $board_term_id = get_post_meta( $post->ID, 'decker_board', true );
			$stack = get_post_meta( $post->ID, 'stack', true );
			$this->reorder_tasks_in_stack( $board_term_id, $stack );
		}
	}

	/**
	 * Update the order of a task within its stack.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function update_task_order( $request ) {
		$task_id   = intval( $request['id'] );
		$new_order = intval( $request->get_param( 'order' ) );

		if ( ! $task_id || ! is_numeric( $new_order ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'message' => 'Task not found.' ), 404 );
		}

		$current_stack = get_post_meta( $task_id, 'stack', true );

		$result = $this->reorder_tasks( $current_stack, $task_id, $new_order );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'message' => 'Task order updated successfully.' ), 200 );
	}




	/**
	 * Mark a user-date relation for a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function mark_user_date_relation( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );
		$date = $request->get_param( 'date' );

		if ( ! $task_id || ! $user_id || ! $date ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$relations = $relations ? $relations : array();

		$this->add_user_date_relation( $task_id, $user_id, $date );

		return new WP_REST_Response( array( 'message' => 'Relation marked successfully.' ), 200 );
	}

	/**
	 * Unmark a user-date relation for a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function unmark_user_date_relation( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );
		$date = $request->get_param( 'date' );

		if ( ! $task_id || ! $user_id || ! $date ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$relations = $relations ? $relations : array();

		foreach ( $relations as $key => $relation ) {
			if ( $relation['user_id'] == $user_id && $relation['date'] == $date ) {
				unset( $relations[ $key ] );
				break;
			}
		}

		update_post_meta( $task_id, '_user_date_relations', $relations );

		return new WP_REST_Response( array( 'message' => 'Relation unmarked successfully.' ), 200 );
	}

	/**
	 * Register REST API routes for decker_task.
	 */
	public function register_rest_routes() {

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/mark_relation',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'mark_user_date_relation' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/unmark_relation',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'unmark_user_date_relation' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/order',
			array(
				'methods'  => 'PUT',
				'callback' => array( $this, 'update_task_order' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/stack',
			array(
				'methods'  => 'PUT',
				'callback' => array( $this, 'update_task_stack' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/leave',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'remove_user_from_task' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/assign',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'assign_user_to_task' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

	}

	/**
	 * Assign a user to a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function assign_user_to_task( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );

		if ( ! $task_id || ! $user_id ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'message' => 'Task not found.' ), 404 );
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( ! is_array( $assigned_users ) ) {
			$assigned_users = array();
		}

		if ( ! in_array( $user_id, $assigned_users ) ) {
			$assigned_users[] = $user_id;
			update_post_meta( $task_id, 'assigned_users', $assigned_users );
		}

		return new WP_REST_Response( array( 'message' => 'User assigned successfully.' ), 200 );
	}

	/**
	 * Remove a user from a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function remove_user_from_task( $request ) {
		$task_id = $request['id'];
		$user_id = $request->get_param( 'user_id' );

		if ( ! $task_id || ! $user_id ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'message' => 'Task not found.' ), 404 );
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( is_array( $assigned_users ) && in_array( $user_id, $assigned_users ) ) {
			$assigned_users = array_diff( $assigned_users, array( $user_id ) );
			update_post_meta( $task_id, 'assigned_users', $assigned_users );
		}

		return new WP_REST_Response( array( 'message' => 'User removed successfully.' ), 200 );
	}
	/**
	 * Add a user-date relation for a task.
	 *
	 * @param int    $task_id The task ID.
	 * @param int    $user_id The user ID.
	 * @param string $date The date to mark.
	 */
	public function add_user_date_relation( $task_id, $user_id, $date ) {
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$relations = $relations ? $relations : array();

		$relations[] = array(
			'user_id' => $user_id,
			'date' => $date,
		);
		update_post_meta( $task_id, '_user_date_relations', $relations );
	}

	public function update_task( $request ) {
		$task_id = $request['id'];
		$status = $request->get_param( 'status' );
		$stack = $request->get_param( 'stack' );

		if ( ! $task_id || ( ! $status && ! $stack ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'message' => 'Task not found.' ), 404 );
		}

		// Update the task status or stack
		$update_data = array( 'ID' => $task_id );
		if ( $status ) {
			$update_data['post_status'] = $status;
		}
		if ( $stack ) {
			update_post_meta( $task_id, 'stack', $stack );
		}
		$updated = wp_update_post( $update_data, true );

		if ( is_wp_error( $updated ) ) {
			return new WP_REST_Response( array( 'message' => 'Failed to update task status.' ), 500 );
		}

		return new WP_REST_Response( array( 'message' => 'Task status updated successfully.' ), 200 );
	}

	/**
	 * Register the decker_task post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Tasks', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Task', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Decker', 'admin menu', 'decker' ),
			'name_admin_bar'     => _x( 'Task', 'add new on admin bar', 'decker' ),
			'add_new'            => _x( 'Add New', 'task', 'decker' ),
			'add_new_item'       => __( 'Add New Task', 'decker' ),
			'new_item'           => __( 'New Task', 'decker' ),
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
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'decker_task' ),
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-welcome-widgets-menus',
			'supports'           => array(
				'title',
				'editor',
				'author',
				'custom-fields',
				'comments',
				'excerpt',
				'page-attributes',
			),
			'taxonomies'         => array( 'decker_board', 'decker_label' ),
			'show_in_rest'       => true,
			'rest_base'          => 'tasks',
			'can_export'         => true,
		);

		register_post_type( 'decker_task', $args );
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
				'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>', 'decker' ),
			)
		);
	}

	/**
	 * Append the custom post status "archived" to the post status list.
	 */
	public function append_post_status_list() {
		global $post;
		$complete = '';
		$label = '';
		if ( 'decker_task' === $post->post_type ) {
			if ( 'archived' === $post->post_status ) {
				$complete = ' selected="selected"';
				$label = '<span id="post-status-display"> ' . esc_html__( 'Archived', 'decker' ) . '</span>';
			}
			echo '<script>
			jQuery(document).ready(function($){
				$("select#post_status").append("<option value=\"archived\" ' . esc_attr( $complete ) . '>' . esc_html__( 'Archived', 'decker' ) . '</option>");
				if ($("#post_status").val() === "archived") {
			        $("#post-status-display").text("' . esc_html__( 'Archivado', 'decker' ) . '");
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
		$post_type = isset( $_GET['post_type'] ) ? $_GET['post_type'] : '';

		if ( 'edit.php' === $pagenow && 'decker_task' === $post_type && ! isset( $_GET['post_status'] ) ) {
			$query->set( 'post_status', 'publish' );
		}
	}

	/**
	 * Display custom post states.
	 *
	 * @param array $statuses The current post states.
	 * @return array The modified post states.
	 */
	public function display_post_states( $statuses ) {
		global $post;

		if ( 'decker_task' == $post->post_type ) {
			if ( 'archived' == $post->post_status ) {
				$statuses['archived'] = __( 'Archived', 'decker' );
			}

			if ( 'draft' == $post->post_status ) {
				$statuses['draft'] = __( 'Custom Draft State', 'decker' );
			}
		}

		return $statuses;
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
		$duedate = get_post_meta( $post->ID, 'duedate', true );
		$max_priority = get_post_meta( $post->ID, 'max_priority', true );
		$stack = get_post_meta( $post->ID, 'stack', true );
		$id_nextcloud_card = get_post_meta( $post->ID, 'id_nextcloud_card', true );
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
				'taxonomy' => 'decker_label',
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
				'taxonomy' => 'decker_board',
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
		$users = get_users( array( 'orderby' => 'display_name' ) );
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
	    // Retrieve existing relations from post meta; initialize as empty array if none exist
	    $relations = get_post_meta( $post->ID, '_user_date_relations', true );
	    $relations = is_array( $relations ) ? $relations : array();

	    // Retrieve all users to populate the select dropdown
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
	            <input type="date" id="assigned_date" class="widefat" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>">
	        </p>
	        
	        <!-- Add Relation Button -->
	        <p>
	            <button type="button" class="button" id="add-user-date-relation"><?php esc_html_e( 'Add Relation', 'decker' ); ?></button>
	        </p>
	        
	        <!-- Relations List -->
	        <ul id="user-date-relations-list">
	            <?php foreach ( $relations as $relation ) { 
	                // Safely retrieve user data
	                $user = get_userdata( $relation['user_id'] );
	                $display_name = $user ? esc_html( $user->display_name ) : esc_html__( 'Unknown User', 'decker' );
	                $date = esc_html( $relation['date'] );
	                ?>
	                <li data-user-id="<?php echo esc_attr( $relation['user_id'] ); ?>" data-date="<?php echo esc_attr( $relation['date'] ); ?>">
	                    <?php echo $display_name . ' - ' . $date; ?>
	                    <button type="button" class="button remove-relation"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
	                </li>
	            <?php } ?>
	        </ul>
	    </div>
	    
	    <!-- Inline JavaScript for Meta Box Functionality -->
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

	            // Validate user selection and date input
	            if (!userId || !date) {
	                alert('<?php echo esc_js( __( "Please select a user and date.", "decker" ) ); ?>');
	                return;
	            }

	            // Check if the user is already added with the same date
	            const existing = Array.from(relationsList.children).some(item => 
	                item.getAttribute('data-user-id') === userId && item.getAttribute('data-date') === date
	            );
	            if (existing) {
	                alert('<?php echo esc_js( __( "This user and date combination already exists.", "decker" ) ); ?>');
	                return;
	            }

	            // Create a new list item for the relation
	            const listItem = document.createElement('li');
	            listItem.setAttribute('data-user-id', userId);
	            listItem.setAttribute('data-date', date);
	            listItem.innerHTML = `
	                ${userName} - ${date} 
	                <button type="button" class="button remove-relation"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>
	            `;
	            relationsList.appendChild(listItem);

	            // Add event listener to the remove button
	            listItem.querySelector('.remove-relation').addEventListener('click', function () {
	                listItem.remove();
	            });

	            // Reset the select and date input
	            userSelect.value = '';
	            dateInput.value = '';
	        });

	        // Add event listeners to existing remove buttons
	        document.querySelectorAll('.remove-relation').forEach(button => {
	            button.addEventListener('click', function () {
	                button.parentElement.remove();
	            });
	        });

	        // Add hidden fields to the form when saving the post
	        document.getElementById('post').addEventListener('submit', function () {
	            // Remove any existing hidden inputs to prevent duplicates
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
	 * Display the attachments meta box.
	 *
	 * @param WP_Post $post The current post object.
	 */
	public function display_attachment_meta_box( $post ) {
		$attachments = get_post_meta( $post->ID, 'attachments', true );
		?>
		<div id="attachments-meta-box">
			<input type="file" id="attachment_file" name="attachment_file[]" multiple>
			<ul id="attachments-list">
				<?php if ( ! empty( $attachments ) ) : ?>
					<?php foreach ( $attachments as $attachment ) : ?>
						<li>
							<a href="<?php echo esc_url( $attachment ); ?>" target="_blank"><?php echo esc_html( basename( $attachment ) ); ?></a>
							<button type="button" class="button remove-attachment"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			const attachmentsList = document.getElementById('attachments-list');
			
			document.getElementById('attachment_file').addEventListener('change', function (event) {
				const files = event.target.files;
				const formData = new FormData();
				Array.from(files).forEach((file, index) => {
					formData.append(`attachment_file_${index}`, file);
				});

				// Here you can add an AJAX request to upload the files

			});

			attachmentsList.addEventListener('click', function (event) {
				if (event.target.classList.contains('remove-attachment')) {
					event.target.closest('li').remove();
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Modify the menu_order before the post is saved.
	 *
	 * @param array $data The data to be saved for the post.
	 * @param array $postarr The post array containing data input.
	 * @return array The modified data array.
	 */
	public function modify_task_order_before_save( $data, $postarr ) {
	    // Ensure we're working with the right post type.
	    if ( 'decker_task' === $data['post_type'] ) {
	        // Check if both 'board' and 'stack' are provided.
	        if ( isset( $postarr['decker_board'], $postarr['stack'] ) ) {
	            $board = sanitize_text_field( $postarr['decker_board'] );
	            $stack = sanitize_text_field( $postarr['stack'] );

	            // Ensure $board and $stack are valid.
	            if ( ! empty( $board ) && ! empty( $stack ) ) {
	                // Get new order value based on board and stack.
	                $new_order = $this->get_new_task_order( $board, $stack );
	                $data['menu_order'] = $new_order;
	            }
	        }
	    }
	    return $data;
	}


	/**
	 * Save the custom meta fields.
	 *
	 * @param int $post_id The current post ID.
	 */
	public function save_meta( $post_id ) {

		// Check autosave and post type.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		if ( ! isset( $_POST['post_type'] ) || 'decker_task' !== $_POST['post_type'] ) {
			return $post_id;
		}

		// Prevent changes if the task is archived
		if ( 'archived' === get_post_status( $post_id ) ) {
			return $post_id;
		}

		// Save task details.
		if ( isset( $_POST['duedate'] ) ) {
			update_post_meta( $post_id, 'duedate', sanitize_text_field( wp_unslash( $_POST['duedate'] ) ) );
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
		if ( isset( $_POST['decker_labels'] ) ) {
			$labels = array_map( 'sanitize_text_field', wp_unslash( $_POST['decker_labels'] ) );
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
			$board_id = sanitize_text_field( wp_unslash( $_POST['decker_board'] ) );
			$board_term = get_term( $board_id, 'decker_board' );
			if ( $board_term && ! is_wp_error( $board_term ) ) {
				wp_set_post_terms( $post_id, array( $board_term->slug ), 'decker_board' );
			}
		}

		$attachments = array();
		if ( ! empty( $_FILES['attachment_file']['name'][0] ) ) {
			foreach ( $_FILES['attachment_file']['name'] as $key => $value ) {
				if ( isset( $_FILES['attachment_file']['name'][ $key ] ) && isset( $_FILES['attachment_file']['type'][ $key ] ) && isset( $_FILES['attachment_file']['tmp_name'][ $key ] ) && isset( $_FILES['attachment_file']['error'][ $key ] ) && isset( $_FILES['attachment_file']['size'][ $key ] ) ) {
					$file = array(
						'name'     => sanitize_file_name( wp_unslash( $_FILES['attachment_file']['name'][ $key ] ) ),
						'type'     => sanitize_mime_type( wp_unslash( $_FILES['attachment_file']['type'][ $key ] ) ),
						'tmp_name' => sanitize_text_field( wp_unslash( $_FILES['attachment_file']['tmp_name'][ $key ] ) ),
						'error'    => intval( $_FILES['attachment_file']['error'][ $key ] ),
						'size'     => intval( $_FILES['attachment_file']['size'][ $key ] ),
					);
					$_FILES = array( 'upload_attachment' => $file );
					$newupload = $this->insert_attachment( 'upload_attachment', $post_id );
					if ( $newupload ) {
						$attachments[] = $newupload;
					}
				}
			}
			update_post_meta( $post_id, 'attachments', $attachments );
		}

		// Save assigned users
		if ( isset( $_POST['assigned_users'] ) ) {
		    $assigned_users = array_map( 'intval', wp_unslash( $_POST['assigned_users'] ) );
		    update_post_meta( $post_id, 'assigned_users', $assigned_users );
		}

		// Save user date relations.

    	$relations = isset( $_POST['user_date_relations'] ) ? json_decode( stripslashes( wp_unslash( $_POST['user_date_relations'] ) ), true ) : array();
	    update_post_meta( $post_id, '_user_date_relations', $relations );

	}

	/**
	 * Insert the attachment.
	 *
	 * @param string $file_handler The file handler.
	 * @param int    $post_id      The current post ID.
	 * @return string|false The URL of the attachment or false on failure.
	 */
	private function insert_attachment( $file_handler, $post_id ) {

		if ( ! isset( $_FILES[ $file_handler ]['error'] ) || UPLOAD_ERR_OK !== $_FILES[ $file_handler ]['error'] ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$attach_id = media_handle_upload( $file_handler, $post_id );
		return wp_get_attachment_url( $attach_id );
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
	 * Filter tasks by taxonomies.
	 *
	 * @param WP_Query $query The current query object.
	 */
	public function filter_tasks_by_taxonomies( $query ) {
		global $pagenow;
		$qv = &$query->query_vars;
		if ( 'edit.php' == $pagenow && isset( $qv['post_type'] ) && 'decker_task' == $qv['post_type'] ) {
			if ( isset( $qv['decker_board'] ) && is_numeric( $qv['decker_board'] ) && 0 != $qv['decker_board'] ) {
				$term = get_term_by( 'id', $qv['decker_board'], 'decker_board' );
				if ( $term ) {
					$qv['decker_board'] = $term->slug;
				}
			}
			if ( isset( $qv['decker_label'] ) && is_numeric( $qv['decker_label'] ) && 0 != $qv['decker_label'] ) {
				$term = get_term_by( 'id', $qv['decker_label'], 'decker_label' );
				if ( $term ) {
					$qv['decker_label'] = $term->slug;
				}
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
		$selected = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
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

	function change_publish_meta_box_title() {
		global $post_type;
		if ( $post_type === 'decker_task' ) {
			echo '<script>
	            jQuery(document).ready(function($) {
	                $("#submitdiv .hndle").text("' . esc_html__( 'Status', 'decker' ) . '");
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
			</style>';
		}
	}



	private function reorder_tasks( $stack, $task_id, $new_order ) {
		global $wpdb;

		// Fetch all tasks in the current stack
		$tasks_in_stack = $wpdb->get_results(
			$wpdb->prepare(
				"
	        SELECT p.ID as post_id, pm_order.meta_value as order_value
	        FROM $wpdb->posts p
	        INNER JOIN $wpdb->postmeta pm_stack ON p.ID = pm_stack.post_id AND pm_stack.meta_key = 'stack'
	        LEFT JOIN $wpdb->postmeta pm_order ON p.ID = pm_order.post_id AND pm_order.meta_key = 'order'
	        WHERE p.post_type = 'decker_task'
	          AND p.post_status = 'publish'
	          AND pm_stack.meta_value = %s
	        ",
				$stack
			)
		);

		if ( ! $tasks_in_stack ) {
			return new WP_REST_Response( array( 'message' => 'No tasks found in the stack.' ), 404 );
		}

		// Remove the current task from the list
		foreach ( $tasks_in_stack as $key => $task_in_stack ) {
			if ( intval( $task_in_stack->post_id ) === intval( $task_id ) ) {
				unset( $tasks_in_stack[ $key ] );
				break;
			}
		}

		// Assign default order values to tasks without an 'order' meta value
		foreach ( $tasks_in_stack as $task_in_stack ) {
			if ( is_null( $task_in_stack->order_value ) ) {
				$task_in_stack->order_value = PHP_INT_MAX; // Place tasks without 'order' at the end
			} else {
				$task_in_stack->order_value = intval( $task_in_stack->order_value );
			}
		}

		// Sort tasks by 'order_value'
		usort(
			$tasks_in_stack,
			function ( $a, $b ) {
				return $a->order_value - $b->order_value;
			}
		);

		// Re-index the array
		$tasks_in_stack = array_values( $tasks_in_stack );

		// Adjust the new order
		$tasks_count = count( $tasks_in_stack );
		$new_order = max( 1, min( $new_order, $tasks_count + 1 ) );

		// Insert the current task at the new position
		array_splice( $tasks_in_stack, $new_order - 1, 0, array( (object) array( 'post_id' => $task_id ) ) );

		// Begin transaction for atomicity
		$wpdb->query( 'START TRANSACTION' );

		// Prepare data for bulk update
		$placeholders = array();
		$values = array();
		$post_ids = array();

		foreach ( $tasks_in_stack as $index => $task_in_stack ) {
			$post_id = intval( $task_in_stack->post_id );
			$order_value = $index + 1;

			$placeholders[] = '(%d, %s, %s)';
			$values[] = $post_id;
			$values[] = 'order';
			$values[] = $order_value;

			$post_ids[] = $post_id;
		}

		// Delete existing 'order' meta keys for the affected posts
		$delete_result = $wpdb->query(
			$wpdb->prepare(
				"
	        DELETE FROM $wpdb->postmeta
	        WHERE post_id IN (" . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ')
	          AND meta_key = %s
	        ',
				array_merge( $post_ids, array( 'order' ) )
			)
		);

		if ( false === $delete_result ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_REST_Response( array( 'message' => 'Failed to update task order.' ), 500 );
		}

		// Insert new 'order' meta values
		$insert_query = "
	        INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value)
	        VALUES " . implode( ', ', $placeholders );

		$insert_result = $wpdb->query( $wpdb->prepare( $insert_query, $values ) );

		if ( false === $insert_result ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_REST_Response( array( 'message' => 'Failed to update task order.' ), 500 );
		}

		// Commit transaction
		$wpdb->query( 'COMMIT' );

		// Clear the cache for affected posts
		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		return true;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Tasks' ) ) {
	new Decker_Tasks();
}
