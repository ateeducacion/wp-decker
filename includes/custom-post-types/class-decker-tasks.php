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
        add_action( 'admin_head', array( $this, 'disable_menu_order_field' ) );


		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta' ) );
		add_action( 'admin_head', array( $this, 'hide_permalink_and_slug' ) );
		add_action( 'admin_head', array( $this, 'change_publish_meta_box_title' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_status' ) );
		add_filter( 'parse_query', array( $this, 'filter_tasks_by_taxonomies' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_taxonomy_filters' ) );
		add_action( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		// add_action( 'admin_post_save_decker_task', array( $this, 'handle_save_task' ) );
		add_filter( 'manage_decker_task_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_decker_task_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
		add_filter( 'manage_edit-decker_task_sortable_columns', array( $this, 'make_columns_sortable' ) );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

		add_filter( 'wp_insert_post_data', array( $this, 'modify_task_order_before_save' ), 10, 4 );

		add_action( 'pre_get_posts', array( $this, 'custom_order_by_stack' ) );


		add_action( 'wp_ajax_save_decker_task', array( $this, 'handle_save_decker_task' ) );
		add_action( 'wp_ajax_nopriv_save_decker_task', array( $this, 'handle_save_decker_task' ) );


		add_action('admin_menu', array($this, 'remove_add_new_link'));

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
	private function get_new_task_order(int $board_term_id, string $stack ) {
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


	    // Decker_Utility_Functions::write_log( $posts , Decker_Utility_Functions::LOG_LEVEL_ERROR );
	    // Decker_Utility_Functions::write_log( "------------" , Decker_Utility_Functions::LOG_LEVEL_ERROR );



	    // If a post exists, get its menu_order and increment it
	    if ( ! empty( $posts ) ) {
	        $max_order = intval( get_post_field( 'menu_order', $posts[0] ) );
	        return $max_order + 1;
	    }

	    // If no posts exist, start with order 1
	    return 1;
	}

	/**
	 * Remove 'Add New' button for decker_task post type.
	 */
	public function remove_add_new_link() {
	    global $submenu;
	    // Remove the "Add New" submenu link
		if (isset($submenu['edit.php?post_type=decker_task'])) {
		    foreach ($submenu['edit.php?post_type=decker_task'] as $key => $item) {
		        // Busca la entrada "Añadir nueva entrada"
		        if ($item[2] === 'post-new.php?post_type=decker_task') {
		            unset($submenu['edit.php?post_type=decker_task'][$key]);
		        }
		    }
		}
	}

	/**
	 * Update the stack and order of a task.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function update_task_stack_and_order( $request ) {
		$task_id    = $request['id'];
		// $new_stack  = $request->get_param( 'stack' );
		// $new_order  = $request->get_param( 'order' );
		$board_id = $request->get_param('board_id');
	    $source_stack = $request->get_param('source_stack');
	    $target_stack = $request->get_param('target_stack');
	    $source_order = $request->get_param('source_order');
	    $target_order = $request->get_param('target_order');

		$valid_stacks = array( 'to-do', 'in-progress', 'done' );

		if ( ! in_array( $source_stack, $valid_stacks ) || ! in_array( $target_stack, $valid_stacks ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid stack value.' ), 400 );
		}

		if (!$task_id || !$source_order || !$target_order ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid parameters.' ), 400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Task not found.' ), 404 );
		}

		// Update the stack and the order
		if ($source_stack != $target_stack) {
			update_post_meta( $task_id, 'stack', $target_stack );
		}

		global $wpdb;

	 	$final_order = $target_order;
		if ($target_order > $source_order) {
			$final_order = $target_order+1;
		}

		// Realiza la actualización usando SQL directamente
		$updated = $wpdb->update(
		    $wpdb->posts, // La tabla de posts de WordPress
		    array(
		        'menu_order' => $final_order,
		        'post_modified' => current_time('mysql'),
		        'post_modified_gmt' => current_time('mysql', 1)
		    ),
		    array('ID' => $task_id), // La condición para encontrar la fila correcta
		    array('%d', '%s', '%s'), // Los tipos de datos de los valores: entero y cadenas
		    array('%d')  // El tipo de datos de la condición (entero)
		);

		// Verifica si la actualización fue exitosa
		if (false === $updated) {
		    // Manejo de error, por ejemplo, registro de error	
		    Decker_Utility_Functions::write_log( 'Error updating menu_order for task ID ' . $task_id . ': ' . $wpdb->last_error, Decker_Utility_Functions::LOG_LEVEL_ERROR );
		}

		// Reorder tasks in the source stack
		if ($source_stack !== $target_stack) {
			$result = $this->reorder_tasks_in_stack( $board_id, $source_stack );
		}
		// Reorder tasks in the target stack
		$result = $this->reorder_tasks_in_stack( $board_id, $target_stack );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => 'success',
				'message' => 'Task stack and order updated successfully.',
			),
			200
		);
	}

	/**
	 * Reorder tasks within a stack and board after a task is deleted.
	 *
	 * @param int $board_term_id The board term ID.
	 * @param string $stack The stack to reorder.
	 * @param int $exclude_post_id Task to exclude.
	 */
	private function reorder_tasks_in_stack( int $board_term_id, string $stack, int $exclude_post_id = 0 ) {
	    global $wpdb;

		// This is the autoincrement value
	    $wpdb->query( "SET @rownum := 0" );

	    // Perform the UPDATE in a single statement
	    $result = $wpdb->query(
	        $wpdb->prepare("
				UPDATE {$wpdb->posts} p
			    INNER JOIN (
			        SELECT
			            t.ID,
			            (@rownum := @rownum + 1) AS new_menu_order
			        FROM (
			            SELECT 
			                p.ID, 
			                p.menu_order, 
			                COALESCE(CAST(pm_priority.meta_value AS UNSIGNED), 0) AS meta_value,
			                p.post_modified
			            FROM {$wpdb->posts} p
			            INNER JOIN {$wpdb->term_relationships} tr 
			                ON p.ID = tr.object_id
			            INNER JOIN {$wpdb->term_taxonomy} tt 
			                ON tr.term_taxonomy_id = tt.term_taxonomy_id
			            INNER JOIN {$wpdb->postmeta} pm_stack 
			                ON p.ID = pm_stack.post_id 
			                AND pm_stack.meta_key = 'stack'
			            LEFT JOIN {$wpdb->postmeta} pm_priority 
			                ON p.ID = pm_priority.post_id 
			                AND pm_priority.meta_key = 'max_priority'
			            WHERE 
			                p.post_type = 'decker_task'
			                AND p.post_status = 'publish'
			                AND pm_stack.meta_value = %s
			                AND tt.term_id = %d
			                AND p.ID != %d
			            GROUP BY 
			                p.ID
			            ORDER BY 
			                meta_value DESC,
			                p.menu_order ASC,
			                p.post_modified DESC
			        ) AS t
			    ) AS ordered_tasks ON p.ID = ordered_tasks.ID
			    SET p.menu_order = ordered_tasks.new_menu_order;",
	            $stack,
	            $board_term_id,
	            $exclude_post_id
	        )
	    );

		if ( $result === false ) {
		    // Handle error, e.g., log the error
		    Decker_Utility_Functions::write_log( $wpdb->last_error, Decker_Utility_Functions::LOG_LEVEL_ERROR );
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
	    $stack = get_post_meta( $post_id, 'stack', true );
	    if ( $board_term_id >0 && $stack ) {
	        $this->reorder_tasks_in_stack( $board_term_id, $stack, $post_id);
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

	    // Decker_Utility_Functions::write_log( '-------.', Decker_Utility_Functions::LOG_LEVEL_ERROR );
	    // Decker_Utility_Functions::write_log( $new_status, Decker_Utility_Functions::LOG_LEVEL_ERROR );
	    // Decker_Utility_Functions::write_log( $old_status, Decker_Utility_Functions::LOG_LEVEL_ERROR );



		if ( $new_status === 'archived' && $old_status === 'publish' ) {
		    // $board_term_id = get_post_meta( $post->ID, 'decker_board', true );


			$board_term_id = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
			$board_term_id = ! empty( $board_term_id ) ? $board_term_id[0] : 0;

			$stack = get_post_meta( $post->ID, 'stack', true );

		    if ( $board_term_id >0 && $stack ) {
		        $this->reorder_tasks_in_stack( $board_term_id, $stack, $post->ID);
		    }
		}
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
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid parameters.' ), 400 );
		}

		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$relations = $relations ? $relations : array();

		$this->add_user_date_relation( $task_id, $user_id, $date );

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Relation marked successfully.' ), 200 );
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
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid parameters.' ), 400 );
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

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Relation unmarked successfully.' ), 200 );
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
					return current_user_can( 'read' );
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
					return current_user_can( 'read' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/order',
			array(
				'methods'  => 'PUT',
				'callback' => array( $this, 'update_task_stack_and_order' ),
				'permission_callback' => function () {
					return current_user_can( 'read' );
				},
			)
		);

		register_rest_route(
			'decker/v1',
			'/tasks/(?P<id>\d+)/stack',
			array(
				'methods'  => 'PUT',
				'callback' => array( $this, 'update_task_stack_and_order' ),
				'permission_callback' => function () {
					return current_user_can( 'read' );
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
					return current_user_can( 'read' );
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
					return current_user_can( 'read' );
				},
			)
		);

		register_rest_route(
		    'decker/v1',
		    '/tasks/(?P<id>\d+)/archive',
		    array(
		        'methods'             => 'POST',
		        'callback'            => array( $this, 'archive_task' ),
		        'permission_callback' => function () {
		            return current_user_can( 'read' );
		        },
		    )
		);

		register_rest_route(
		    'decker/v1',
		    '/fix-order/(?P<board_id>\d+)',
		    array(
		        'methods'  => 'POST',
		        'callback' => array( $this, 'handle_fix_order' ),
		        'permission_callback' => function () {
		            return current_user_can( 'manage_options' );
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
			return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Invalid parameters.' ), 
			400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Task not found.' ), 
			404 );
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( ! is_array( $assigned_users ) ) {
			$assigned_users = array();
		}

		if ( ! in_array( $user_id, $assigned_users ) ) {
			$assigned_users[] = $user_id;
			update_post_meta( $task_id, 'assigned_users', $assigned_users );
		}

		return new WP_REST_Response( array(
	        	'success' => true,
            	'message' => 'User assigned successfully.' ), 
		200 );
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
			return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Invalid parameters.' ), 
			400 );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'decker_task' !== $task->post_type ) {
			return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Task not found.' ), 
			404 );
		}

		$assigned_users = get_post_meta( $task_id, 'assigned_users', true );
		if ( is_array( $assigned_users ) && in_array( $user_id, $assigned_users ) ) {
			$assigned_users = array_diff( $assigned_users, array( $user_id ) );
			update_post_meta( $task_id, 'assigned_users', $assigned_users );
		}

		return new WP_REST_Response( array(
	        	'success' => true,
            	'message' => 'User removed successfully.' ), 
		200 );
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

	/**
	 * Archive a task by updating its status to 'archived'.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function archive_task( $request ) {
	    $task_id = $request['id'];

	    // Validar el ID de la tarea
	    if ( ! $task_id ) {
	        return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'ID de tarea inválido.' ), 
	        400 );
	    }

	    $task = get_post( $task_id );
	    if ( ! $task || 'decker_task' !== $task->post_type ) {
	        return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Tarea no encontrada.' ),
	        404 );
	    }

	    // Actualizar el estado de la tarea a 'archived'
	    $updated = wp_update_post( array(
	        'ID'          => $task_id,
	        'post_status' => 'archived',
	    ), true );

	    if ( is_wp_error( $updated ) ) {
	        return new WP_REST_Response( array(
	        	'success' => false,
            	'message' => 'Error al archivar la tarea.' ), 
	        500 );
	    }

	    return new WP_REST_Response( array(
	        	'success' => true,
            	'message' => 'Tarea archivada exitosamente.' ), 
	  	200 );
	}

	/**
	 * Handle fixing the order for tasks in the specified board.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response The REST response.
	 */
	public function handle_fix_order( $request ) {
	    $board_id = intval( $request['board_id'] );

	    if ( $board_id <= 0 ) {
	        return new WP_REST_Response( array(
	            'message' => 'Invalid board ID.'
	        ), 400 );
	    }

	    $stacks = array( 'to-do', 'in-progress', 'done' );

	    foreach ( $stacks as $stack ) {
	        $this->reorder_tasks_in_stack( $board_id, $stack );
	    }

	    return new WP_REST_Response( array(
	        'success' => true,
	        'message' => 'Tasks reordered successfully for board ' . $board_id . '.'
	    ), 200 );
	}



	/**
	 * Register the decker_task post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Tasks', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Task', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Decker', 'admin menu', 'decker' ),
			// 'name_admin_bar'     => _x( 'Task', 'add new on admin bar', 'decker' ),
			// 'add_new'            => _x( 'Add New', 'task', 'decker' ),
			// 'add_new_item'       => __( 'Add New Task', 'decker' ),
			// 'new_item'           => __( 'New Task', 'decker' ),
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

	// /**
	//  * Display custom post states.
	//  *
	//  * @param array $statuses The current post states.
	//  * @return array The modified post states.
	//  */
	// public function display_post_states( $statuses ) {
	// 	global $post;

	// 	if ( 'decker_task' == $post->post_type ) {
	// 		if ( 'archived' == $post->post_status ) {
	// 			$statuses['archived'] = __( 'Archived', 'decker' );
	// 		}

	// 		if ( 'draft' == $post->post_status ) {
	// 			$statuses['draft'] = __( 'Custom Draft State', 'decker' );
	// 		}
	// 	}

	// 	return $statuses;
	// }

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


public function display_attachment_meta_box( $post ) {
    // Retrieve existing attachments from post meta; initialize as empty array if none exist
    $attachments = get_post_meta( $post->ID, 'attachments', true );
    $attachments = is_array( $attachments ) ? $attachments : array();

    // Include the nonce field for security
    wp_nonce_field( 'save_decker_task', 'decker_task_nonce' );
    ?>
    <div id="attachments-meta-box">
        <!-- Button to open the media library modal -->
        <p>
            <button type="button" class="button" id="add-attachments"><?php esc_html_e( 'Add Attachments', 'decker' ); ?></button>
        </p>
        
        <!-- List of attached media -->
        <ul id="attachments-list">
            <?php foreach ( $attachments as $attach_id ) : 
                $attachment_url = wp_get_attachment_url( $attach_id );
                $attachment_title = get_the_title( $attach_id );
                ?>
                <li data-attachment-id="<?php echo esc_attr( $attach_id ); ?>">
                    <a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank"><?php echo esc_html( $attachment_title ); ?></a>
                    <button type="button" class="button remove-attachment"><?php esc_html_e( 'Remove', 'decker' ); ?></button>
                    <!-- Hidden input to store attachment IDs -->
                    <input type="hidden" name="attachments[]" value="<?php echo esc_attr( $attach_id ); ?>">
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <!-- JavaScript to handle the media library modal -->
    <script>
    jQuery(document).ready(function($){
        var frame;
        $('#add-attachments').on('click', function(e){
            e.preventDefault();
            // If the media frame already exists, reopen it.
            if ( frame ) {
                frame.open();
                return;
            }
            // Create a new media frame
            frame = wp.media({
                title: '<?php echo esc_js( __( 'Select Attachments', 'decker' ) ); ?>',
                button: {
                    text: '<?php echo esc_js( __( 'Add Attachments', 'decker' ) ); ?>',
                },
                multiple: true // Set to true to allow multiple files to be selected
            });
            // When an attachment is selected, run a callback.
            frame.on( 'select', function() {
                var attachments = frame.state().get('selection').toJSON();
                attachments.forEach(function(attachment){
                    // Append the selected attachments to the list
                    $('#attachments-list').append(
                        '<li data-attachment-id="' + attachment.id + '">' +
                            '<a href="' + attachment.url + '" target="_blank">' + attachment.title + '</a> ' +
                            '<button type="button" class="button remove-attachment"><?php echo esc_js( __( 'Remove', 'decker' ) ); ?></button>' +
                            '<input type="hidden" name="attachments[]" value="' + attachment.id + '">' +
                        '</li>'
                    );
                });
            });
            // Finally, open the modal
            frame.open();
        });
        // Handle removal of attachments
        $('#attachments-list').on('click', '.remove-attachment', function(){
            $(this).closest('li').remove();
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
	public function modify_task_order_before_save(array $data, array $postarr, array $unsanitized_postarr, bool $update ) {

		// Prevent the user from directly modifying the menu_order
		if ( isset( $postarr['menu_order'] ) ) {
		    // Remove the menu_order field so it won't be saved
		    unset( $postarr['menu_order'] );
	        Decker_Utility_Functions::write_log( 'Avoid order modification by user.', Decker_Utility_Functions::LOG_LEVEL_ERROR );
		}

	    // Ensure we're working with the correct post type and only on Insert post.
	    if ( !$update && 'decker_task' === $postarr['post_type'] ) {

	        // Log the function call for debugging.
	        Decker_Utility_Functions::write_log( 'Function modify_task_order_before_save called.', Decker_Utility_Functions::LOG_LEVEL_DEBUG );


	        // Log input data for inspection.
	        Decker_Utility_Functions::write_log( 'Input $data: ' . print_r( $data, true ), Decker_Utility_Functions::LOG_LEVEL_DEBUG );
	        Decker_Utility_Functions::write_log( 'Input $postarr: ' . print_r( $postarr, true ), Decker_Utility_Functions::LOG_LEVEL_DEBUG );

	        // Initialize variables.
	        $board = '';
	        $stack = '';

	        // 1. Attempt to retrieve 'decker_board' and 'stack' directly from $postarr.
	        if ( isset( $postarr['decker_board'] ) ) {
	            $board = intval( $postarr['decker_board'] );
	            Decker_Utility_Functions::write_log( "Found 'decker_board' directly in \$postarr: $board", Decker_Utility_Functions::LOG_LEVEL_DEBUG );
	        }

	        if ( isset( $postarr['stack'] ) ) {
	            $stack = sanitize_text_field( $postarr['stack'] );
	            Decker_Utility_Functions::write_log( "Found 'stack' directly in \$postarr: $stack", Decker_Utility_Functions::LOG_LEVEL_DEBUG );
	        }

	        // 2. If not found directly, attempt to retrieve from 'meta_input' and 'tax_input'.
	        if ( empty( $board ) && isset( $postarr['tax_input']['decker_board'][0] ) ) {
	            $board = intval( $postarr['tax_input']['decker_board'][0] );

	            // Decker_Utility_Functions::write_log( "-----" , Decker_Utility_Functions::LOG_LEVEL_INFO );

	            // Decker_Utility_Functions::write_log( $postarr['tax_input']['decker_board'], Decker_Utility_Functions::LOG_LEVEL_INFO );


			    // Retrieve the term ID based on the term name.
			    // $term = get_term( $board, 'decker_board' );

			    // if ( $term && ! is_wp_error( $term ) ) {
			    //     $board_name = $term->name; // Get the term ID.
			    //     Decker_Utility_Functions::write_log( "Retrieved term ID for 'decker_board' with ID '$board': $board_name", Decker_Utility_Functions::LOG_LEVEL_INFO );
			    // } else {
			    //     Decker_Utility_Functions::write_log( "Failed to retrieve term Name for 'decker_board' with ID '$board'.", Decker_Utility_Functions::LOG_LEVEL_ERROR );
			    // }
	        }

	        if ( empty( $stack ) && isset( $postarr['meta_input']['stack'] ) ) {
	            $stack = sanitize_text_field( $postarr['meta_input']['stack'] );
	            Decker_Utility_Functions::write_log( "Found 'stack' in 'meta_input': $stack", Decker_Utility_Functions::LOG_LEVEL_DEBUG );
	        }

	        // 3. Validate that both 'board' and 'stack' have been retrieved.
	        if ( ! empty( $board ) && ! empty( $stack ) ) {

	            // Decker_Utility_Functions::write_log( $board, Decker_Utility_Functions::LOG_LEVEL_INFO );
	            // Decker_Utility_Functions::write_log( $stack, Decker_Utility_Functions::LOG_LEVEL_INFO );

	            // Calculate the new order value based on 'board' and 'stack'.
	            $new_order = $this->get_new_task_order( $board, $stack );

	            // Ensure that the new order is a valid number.
	            if ( is_numeric( $new_order ) ) {
	                // Assign the calculated menu_order to the post data.
	                $data['menu_order'] = intval( $new_order );

	                // Log the new menu_order value.
	                Decker_Utility_Functions::write_log( "Assigned 'menu_order' = $new_order for post ID: " . $postarr['ID'], Decker_Utility_Functions::LOG_LEVEL_DEBUG );
	            } else {
	                // Log an error if the new_order is not numeric.
	                Decker_Utility_Functions::write_log( "Invalid 'new_order' value: $new_order for post ID: " . $postarr['ID'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
	            }
	        } else {
	            // Log a warning if either 'board' or 'stack' is missing.
	            Decker_Utility_Functions::write_log( "Missing 'decker_board' or 'stack' for post ID: " . $postarr['ID'], Decker_Utility_Functions::LOG_LEVEL_ERROR );
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

	    // Save attachments
	    if ( isset( $_POST['attachments'] ) ) {
	        $attachments = array_map( 'intval', $_POST['attachments'] );
	        update_post_meta( $post_id, 'attachments', $attachments );
	    } else {
	        // If no attachments are provided, delete the meta
	        delete_post_meta( $post_id, 'attachments' );
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
	                // Disable the menu_order field
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
		        .page-title-action {
	               	display: none !important;
	            }
			</style>';
		}
	}

    public function handle_save_decker_task() {
        // Verificar el nonce de seguridad
        check_ajax_referer( 'save_decker_task_nonce', 'nonce' );

        // Obtener y sanitizar los datos del formulario
        $ID = isset( $_POST['task_id'] ) ? intval( $_POST['task_id'] ) : 0;
        $title = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
        $description = isset( $_POST['description'] ) ? wp_kses_post( $_POST['description'] ) : '';
        $stack = isset( $_POST['stack'] ) ? sanitize_text_field( $_POST['stack'] ) : '';
        $board = isset( $_POST['board'] ) ? intval( $_POST['board'] ) : 0;
        $max_priority = isset( $_POST['max_priority'] ) ? boolval( $_POST['max_priority'] ) : false;
              
		try {
		    $duedate = isset( $_POST['due_date'] ) ? new DateTime( sanitize_text_field( $_POST['due_date'] ) ) : new DateTime();
		} catch ( Exception $e ) {
		    $duedate = new DateTime(); // Default value if conversion fails
		}

        $author = isset( $_POST['author'] ) ? intval( $_POST['author'] ) : get_current_user_id();
                

		$assigned_users = is_string($_POST['assignees'])
		    ? array_map('intval', explode(',', $_POST['assignees']))
		    : (is_array($_POST['assignees']) ? array_map('intval', $_POST['assignees']) : []);

		$labels = is_string($_POST['labels'])
		    ? array_map('intval', explode(',', $_POST['labels']))
		    : (is_array($_POST['labels']) ? array_map('intval', $_POST['labels']) : []);


        $creation_date = new DateTime(); // O ajusta según corresponda

        // Llamar a la función común para crear o actualizar la tarea
        $result = self::create_or_update_task(
            $ID,
            $title,
            $description,
            $stack,
            $board,
            $max_priority,
            $duedate,
            $author,
            $assigned_users,
            $labels,
            $creation_date,
            false,
            0,
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_success( [ 'message' => 'Tarea guardada exitosamente.', 'task_id' => $result ] );
        }

        wp_die(); // Finalizar correctamente
    }


	public static function create_or_update_task(
	    int $ID,
	    string $title,
	    string $description,
	    string $stack,
	    int $board,
	    bool $max_priority,
	    ?DateTime $duedate,
	    int $author,
	    array $assigned_users,
	    array $labels,
	    DateTime $creation_date,
	    bool $archived = false,
	    int $id_nextcloud_card = 0
	) {


	    // Validate required fields
	    if ( empty( $title ) ) {
	        return new WP_Error( 'missing_field', __( 'The title is required.', 'decker' ) );
	    }
	    if ( empty( $stack ) ) {
	        return new WP_Error( 'missing_field', __( 'The stack is required.', 'decker' ) );
	    }
	    if ( $board <= 0 ) {
	        return new WP_Error( 'missing_field', __( 'The board is required and must be a positive integer.', 'decker' ) );
	    }

		if ( ! term_exists( $board, 'decker_board' ) ) {		

			Decker_Utility_Functions::write_log( 'Invalid default board: "' . esc_html( $board ) . '" does not exist in the decker_board taxonomy.', Decker_Utility_Functions::LOG_LEVEL_ERROR );
	        return new WP_Error( 'invalid', __( 'The board does not exist in the decker_board taxonomy.', 'decker' ) );
		}

	    // Convertir objetos DateTime a formato string (si no, pasamos null to undefined)
	    $duedate_str = $duedate ? $duedate->format('Y-m-d') : null;
	    $creation_date_str = $creation_date->format('Y-m-d H:i:s');

	    // Preparar los términos para tax_input
	    $tax_input = array();

	    // Asignar la taxonomía 'decker_board' con el ID del board
	    if ( $board > 0 ) {
	        $tax_input['decker_board'] = array( $board );
	    }

	    // Incluir etiquetas en tax_input si las hay
	    if ( ! empty( $labels ) ) {
	        // Asegúrate de que $labels contiene IDs de términos válidos
	        $tax_input['decker_label'] = array_map( 'intval', $labels );
	    }

	    // Preparar los metadatos personalizados
	    $meta_input = array(
	        'id_nextcloud_card' => $id_nextcloud_card,
	        'stack'             => sanitize_text_field( $stack ),
	        'duedate'           => $duedate_str,
	        'max_priority'      => $max_priority ? '1' : '0',
	        'assigned_users'    => array_map( 'intval', $assigned_users ),
	    );

	    // Preparar los datos del post
	    $post_data = array(
	        'post_title'    => sanitize_text_field( $title ),
	        'post_content'  => wp_kses_post( $description ),
	        'post_status'   => $archived ? 'archived' : 'publish',
	        'post_type'     => 'decker_task',
	        'post_date'     => $creation_date_str,
	        'post_author'   => $author,
	        'meta_input'    => $meta_input,
	        'tax_input'     => $tax_input,
	    );



	    // Determinar si es una actualización o creación
	    if ( $ID > 0 ) {
	        // Actualizar el post existente
	        $post_data['ID'] = $ID;
	        $task_id = wp_update_post( $post_data );

	        if ( is_wp_error( $task_id ) ) {
	            return $task_id; // Retornar el error para manejarlo externamente
	        }
	    } else {
	        // Crear un nuevo post
	        $task_id = wp_insert_post( $post_data );
	        if ( is_wp_error( $task_id ) ) {
	            return $task_id; // Retornar el error para manejarlo externamente
	        }
	    }

	    // Retornar el ID de la tarea creada o actualizada
	    return $task_id;
	}



}

// Instantiate the class.
if ( class_exists( 'Decker_Tasks' ) ) {
	new Decker_Tasks();
}
