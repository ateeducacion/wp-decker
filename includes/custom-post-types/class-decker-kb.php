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
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'adjust_admin_menu' ) );
		
		// REST API endpoints
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

		// Handle labels
		if ( ! empty( $params['labels'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $params['labels'] ), 'decker_label' );
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

		return new WP_REST_Response(
			array(
				'success'  => true,
				'article' => array(
					'id'         => $post->ID,
					'title'      => $post->post_title,
					'content'    => $post->post_content,
					'labels'     => $labels,
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
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'            => true,
			'show_in_menu'      => 'edit.php?post_type=decker_task',
			'show_in_nav_menus'  => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'knowledge-base' ),
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
	 * Link existing Decker Labels taxonomy
	 */
	public function register_taxonomy() {
		register_taxonomy_for_object_type( 'decker_label', 'decker_kb' );
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
	 * Get all articles
	 *
	 * @param array $args Optional. Additional arguments for WP_Query.
	 * @return array Array of Event objects.
	 */
	public static function get_articles( $args = array() ) {
		$default_args = array(
			'post_type'      => 'decker_kb',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'cache_results'  => true, // Enable post caching.
		);

		$args = wp_parse_args( $args, $default_args );
		$posts = get_posts( $args );

		// After getting posts, load all metadata into cache at once.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		update_meta_cache( 'post', $post_ids ); // 1 consulta extra para todos los metadatos

		return $posts;

		/*
		// Avoid modifying native WP_Post objects.
		// $articles = array();
		// foreach ( $posts as $post ) {
		// $articles[] = array(
		// 'post' => $post,
		// 'meta' => get_post_meta( $post->ID ), // get_post_meta() will use cache and avoid additional queries.

		// );
		// }

		// return $articles;
		*/
	}
}

if ( class_exists( 'Decker_Kb' ) ) {
	new Decker_Kb();
}
