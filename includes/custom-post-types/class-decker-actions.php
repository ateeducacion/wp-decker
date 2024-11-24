<?php
/**
 * This file contains the functions related to the decker_action taxonomy.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Actions
 *
 * Handles the decker_action taxonomy.
 */
class Decker_Actions {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Register hooks.
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_filter( 'wp_insert_term_data', array( $this, 'restrict_depth' ), 10, 2 );
		add_filter( 'wp_insert_term', array( $this, 'term_depth_error' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'hide_description' ) );
		add_filter( 'manage_edit-decker_action_columns', array( $this, 'customize_columns' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_access' ), 10, 3 );
	}

	/**
	 * Register the decker_action taxonomy.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'          => _x( 'Actions', 'taxonomy general name', 'decker' ),
			'singular_name' => _x( 'Action', 'taxonomy singular name', 'decker' ),
			'search_items'  => __( 'Search Actions', 'decker' ),
			'all_items'     => __( 'All Actions', 'decker' ),
			'edit_item'     => __( 'Edit Action', 'decker' ),
			'update_item'   => __( 'Update Action', 'decker' ),
			'add_new_item'  => __( 'Add New Action', 'decker' ),
			'new_item_name' => __( 'New Action Name', 'decker' ),
			'menu_name'     => __( 'Actions', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'hierarchical'       => true,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'query_var'          => true,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'rewrite'            => array( 'slug' => 'decker_action' ),
			'show_in_rest'       => true, // Enable REST API.
			'rest_base'          => 'actions', // Base name in REST API.
			'can_export'         => true,
			'capabilities'       => array(
				'assign_terms' => 'read',
			),
		);

		register_taxonomy( 'decker_action', array( 'decker_task' ), $args );
	}

	/**
	 * Restrict the depth of the decker_action taxonomy hierarchy to two levels.
	 *
	 * @param array  $args The term options.
	 * @param string $taxonomy The taxonomy name.
	 * @return array The modified term options.
	 */
	public function restrict_depth( $args, $taxonomy ) {
		if ( 'decker_action' === $taxonomy ) {
			if ( isset( $args['parent'] ) && $args['parent'] > 0 ) {
				$parent_term = get_term( $args['parent'], 'decker_action' );
				if ( $parent_term && 0 != $parent_term->parent ) {
					$args['parent'] = 0;
				}
			}
		}
		return $args;
	}

	/**
	 * Add an error message if attempting to add a term more than two levels deep.
	 *
	 * @param WP_Error $term WP_Error or the WP_Term object.
	 * @param string   $taxonomy The taxonomy in which the term is being added.
	 * @return WP_Error|WP_Term WP_Error if there is an error, otherwise the WP_Term object.
	 */
	public function term_depth_error( $term, $taxonomy ) {
		if ( 'decker_action' === $taxonomy && ! is_wp_error( $term ) ) {
			if ( $term->parent > 0 ) {
				$parent_term = get_term( $term->parent, 'decker_action' );
				if ( $parent_term && 0 != $parent_term->parent ) {
					return new WP_Error( 'term_depth_error', __( 'Terms in the Actions taxonomy cannot be more than two levels deep.', 'decker' ) );
				}
			}
		}
		return $term;
	}

	/**
	 * Hide the description field in the decker_action taxonomy term form.
	 */
	public function hide_description() {
		if ( isset( $_GET['taxonomy'] ) && 'decker_action' == $_GET['taxonomy'] ) {
			echo '<style>.term-description-wrap, .column-description { display: none; }</style>';
		}
	}

	/**
	 * Customize the columns displayed in the decker_action taxonomy term list.
	 *
	 * @param array $columns The current columns.
	 * @return array The customized columns.
	 */
	public function customize_columns( $columns ) {
		unset( $columns['description'] ); // Hide the description column.
		return $columns;
	}

	/**
	 * Require authentication to access the decker_action taxonomy in the REST API.
	 *
	 * @param mixed           $result The result to send to the client.
	 * @param WP_REST_Server  $server Server instance.
	 * @param WP_REST_Request $request The request.
	 * @return mixed The result to send to the client.
	 */
	public function restrict_rest_access( $result, $server, $request ) {
		if ( false !== strpos( $request->get_route(), '/wp/v2/actions' ) ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'rest_forbidden', __( 'You are not authorized to access this resource.', 'decker' ), array( 'status' => 401 ) );
			}
		}
		return $result;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Actions' ) ) {
	new Decker_Actions();
}
