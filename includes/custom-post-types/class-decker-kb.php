<?php
 /**
  * The file that defines the Knowledge Base custom post type
  *
  * @link       https://github.com/ateeducacion/wp-decker
  * @since      2.0.14
  *
  * @package    Decker
  * @subpackage Decker/includes/custom-post-types
  */

 /**
  * Class to handle the Knowledge Base custom post type
  */
class Decker_Kb {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 10, 3 );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'adjust_admin_menu' ) );

		// REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_rest_routes() {
		register_rest_route(
			'decker/v1',
			'/kb',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_article' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_article' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Check if user has permission to manage KB articles
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Save or update KB article
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_article( $request ) {
		$params = $request->get_params();

		$post_data = array(
			'post_type'    => 'decker_kb',
			'post_title'   => sanitize_text_field( $params['title'] ),
			'post_content' => wp_kses_post( $params['content'] ),
			'post_status'  => 'publish',
			'post_parent'  => isset( $params['parent_id'] ) ? intval( $params['parent_id'] ) : 0,
			'menu_order'   => isset( $params['menu_order'] ) ? intval( $params['menu_order'] ) : 0,
		);

		if ( ! empty( $params['id'] ) ) {
			$post_data['ID'] = intval( $params['id'] );
		}

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $post_id->get_error_message(),
				),
				400
			);
		}

		// Handle labels.
		if ( ! empty( $params['labels'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $params['labels'] ), 'decker_label' );
		}
		
		// Handle board.
		if ( ! empty( $params['board'] ) ) {
			$board_id = intval( $params['board'] );
			if ( $board_id > 0 ) {
				wp_set_object_terms( $post_id, array( $board_id ), 'decker_board' );
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Article saved successfully', 'decker' ),
				'id'     => $post_id,
			),
			200
		);
	}

	/**
	 * Restricts REST API access for decker_kb post type.
	 *
	 * @param mixed           $result The pre-calculated result to return.
	 * @param WP_REST_Server  $rest_server The REST server instance.
	 * @param WP_REST_Request $request The current REST request.
	 * @return mixed WP_Error if unauthorized, otherwise the original result.
	 */
	public function restrict_rest_access( $result, $rest_server, $request ) {
		$route = $request->get_route();

		if ( strpos( $route, '/wp/v2/decker_kb' ) === 0 ) {
			// Usa la capacidad específica del CPT.
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
	 * Get KB article data
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_article( $request ) {
		$article_id = $request->get_param( 'id' );

		$post = get_post( $article_id );
		if ( ! $post || 'decker_kb' !== $post->post_type ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Article not found', 'decker' ),
				),
				404
			);
		}

		$labels = wp_get_object_terms( $article_id, 'decker_label', array( 'fields' => 'ids' ) );
		$board_terms = wp_get_object_terms( $article_id, 'decker_board', array( 'fields' => 'ids' ) );
		$board_id = !empty($board_terms) ? $board_terms[0] : 0;

		return new WP_REST_Response(
			array(
				'success'  => true,
				'article' => array(
					'id'         => $post->ID,
					'title'      => $post->post_title,
					'content'    => $post->post_content,
					'labels'     => $labels,
					'board'      => $board_id,
					'parent_id'  => $post->post_parent,
					'menu_order' => $post->menu_order,
				),
			),
			200
		);
	}

	/**
	 * Register the custom post type
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Knowledge Base', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Article', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Knowledge Base', 'admin menu', 'decker' ),
			'add_new'           => __( 'Add New Article', 'decker' ),
			'add_new_item'       => __( 'Add New Article', 'decker' ),
			'new_item'           => __( 'New Article', 'decker' ),
			'edit_item'          => __( 'Edit Article', 'decker' ),
			'view_item'          => __( 'View Article', 'decker' ),
			'all_items'          => __( 'Knowledge Base', 'decker' ),
			'parent_item_colon' => __( 'Parent Article:', 'decker' ),
			'not_found'          => __( 'No articles found.', 'decker' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'            => true,
			'show_in_menu'      => 'edit.php?post_type=decker_task',
			'show_in_nav_menus'  => true,
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'     => 'post',
			'has_archive'        => true,
			'hierarchical'       => true, // Enable hierarchy.
			'menu_position'       => 25,
			'supports'           => array( 'title', 'editor', 'page-attributes', 'revisions' ),
			'show_in_rest'       => true, // Enable Gutenberg.
			'menu_icon'          => 'dashicons-book',
		);

		register_post_type( 'decker_kb', $args );
	}

	/**
	 * Link existing Decker taxonomies
	 */
	public function register_taxonomy() {
		register_taxonomy_for_object_type( 'decker_label', 'decker_kb' );
		register_taxonomy_for_object_type( 'decker_board', 'decker_kb' );
	}

	/**
	 * Ensure Gutenberg is disabled for the custom post type.
	 *
	 * This function checks if the post type is `decker_kb` and forces the use of the classic editor.
	 * Otherwise, it retains the default behavior.
	 *
	 * @param bool   $use_block_editor Whether to use the block editor.
	 * @param string $post_type The post type being checked.
	 * @return bool True if Gutenberg should be enabled, false otherwise.
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		if ( 'decker_kb' === $post_type ) {
			return false; // Deactivate gutenberg editor.
		}
		return $use_block_editor;
	}

	/**
	 * Adjust admin menu to show hierarchy
	 */
	public function adjust_admin_menu() {
		add_filter(
			'parent_file',
			function ( $parent_file ) {
				global $current_screen;

				if ( 'decker_kb' === $current_screen->post_type ) {
					  $parent_file = 'edit.php?post_type=decker_kb';
				}
				return $parent_file;
			}
		);
	}


	/**
	 * Get relative time for a post's modified date
	 *
	 * @param int $post_id The post ID
	 * @return string The relative time as a human-readable string
	 */
	public static function get_relative_time( $post_id ): string {
		$modified_date = get_post_modified_time( 'U', false, $post_id );
		
		if ( !$modified_date ) {
			return __( 'No date', 'decker' );
		}

		$modified_date_obj = new DateTime( '@' . $modified_date );
		$modified_date_obj->setTime( 0, 0, 0 ); // Ignore time.

		$today = new DateTime( 'today' );
		$yesterday = ( clone $today )->modify( '-1 day' );
		$tomorrow = ( clone $today )->modify( '+1 day' );

		if ( $modified_date_obj == $today ) {
			return __( 'Today', 'decker' );
		} elseif ( $modified_date_obj == $yesterday ) {
			return __( 'Yesterday', 'decker' );
		} elseif ( $modified_date_obj == $tomorrow ) {
			return __( 'Tomorrow', 'decker' );
		} else {
			$now = current_time( 'timestamp' ); // WordPress current time.
			$diff_days = $today->diff( $modified_date_obj )->days;

			// Use human_time_diff.
			if ( $modified_date_obj < $today ) {
				// Translators: %s is the time elapsed (e.g., "2 hours", "3 days").
				return sprintf( __( '%s ago', 'decker' ), human_time_diff( $modified_date, $now ) );
			} else {
				// Translators: %s is the time remaining until the due date (e.g., "in 2 hours", "in 3 days").
				return sprintf( __( 'in %s', 'decker' ), human_time_diff( $now, $modified_date ) );
			}
		}
	}

	/**
	 * Get all articles in hierarchical order.
	 *
	 * @param array $args Optional. Additional arguments for WP_Query.
	 * @return array Array of WP_Post objects ordered hierarchically.
	 */
	public static function get_articles( $args = array() ) {
		$default_args = array(
			'post_type'      => 'decker_kb',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'cache_results'  => true,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		$args  = wp_parse_args( $args, $default_args );
		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return array();
		}

		// After getting posts, load all metadata into cache at once.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		update_meta_cache( 'post', $post_ids );

		// Map posts by ID and organize them into a tree.
		$post_map  = array();
		$tree_root = array();

		foreach ( $posts as $post ) {
			$post->ancestors = get_post_ancestors( $post->ID ); // Ancestors.
			$post->depth = count( $post->ancestors ); // Depth in hierarchy.
			$post_map[ $post->ID ] = $post;
		}

		foreach ( $posts as $post ) {
			$parent_id = $post->post_parent;
			if ( $parent_id && isset( $post_map[ $parent_id ] ) ) {
				if ( ! isset( $post_map[ $parent_id ]->children ) ) {
					$post_map[ $parent_id ]->children = array();
				}
				$post_map[ $parent_id ]->children[] = $post;
			} else {
				$tree_root[] = $post; // Is a root node.
			}
		}

		// Flatten tree structure into an ordered list.
		$ordered_posts = array();
		self::flatten_hierarchical_posts( $tree_root, $ordered_posts );

		return $ordered_posts;
	}

	/**
	 * Recursively flatten hierarchical posts into an ordered list.
	 *
	 * @param array $posts Array of hierarchical posts.
	 * @param array $output Ordered output array.
	 */
	private static function flatten_hierarchical_posts( $posts, &$output ) {
		foreach ( $posts as $post ) {
			$output[] = $post;
			if ( isset( $post->children ) ) {
				self::flatten_hierarchical_posts( $post->children, $output );
			}
		}
	}
}

if ( class_exists( 'Decker_Kb' ) ) {
	new Decker_Kb();
}
