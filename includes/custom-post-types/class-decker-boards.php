<?php
/**
 * Board Model for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Boards
 *
 * Handles the decker_board taxonomy.
 */
class Decker_Boards {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks
	 *
	 * Registers all the hooks related to the decker_board taxonomy.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_taxonomy' ) );

		// The colour picker and the visibility toggles own their own hooks.
		new Decker_Board_Term_Fields( 'decker_board' );

		add_action( 'delete_term', array( $this, 'decker_handle_board_deletion' ), 10, 3 );

		add_filter( 'manage_edit-decker_board_columns', array( $this, 'customize_columns' ) );
		add_filter( 'manage_decker_board_custom_column', array( $this, 'add_column_content' ), 10, 3 );

		// Enforce capability checks.
		add_filter( 'pre_insert_term', array( $this, 'prevent_term_creation' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'prevent_term_deletion' ), 10, 2 );

		// Register REST field for term meta.
		add_action( 'rest_api_init', array( $this, 'register_rest_fields' ) );
	}

	/**
	 * Register the decker_board taxonomy.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'          => _x( 'Boards', 'taxonomy general name', 'decker' ),
			'singular_name' => _x( 'Board', 'taxonomy singular name', 'decker' ),
			'search_items'  => __( 'Search Boards', 'decker' ),
			'all_items'     => __( 'All Boards', 'decker' ),
			'edit_item'     => __( 'Edit Board', 'decker' ),
			'update_item'   => __( 'Update Board', 'decker' ),
			'add_new_item'  => __( 'Add New Board', 'decker' ),
			'new_item_name' => __( 'New Board Name', 'decker' ),
			'menu_name'     => __( 'Boards', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'query_var'          => true,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'rewrite'            => array( 'slug' => 'decker_board' ),
			'show_in_rest'       => true,
			'rest_base'          => 'decker_board',
			'can_export'         => true,
			'capabilities'       => array(
				'manage_terms' => 'edit_posts',
				'edit_terms'   => 'edit_posts',
				'delete_terms' => 'edit_posts',
				'assign_terms' => 'edit_posts',
			),
		);

		register_taxonomy( 'decker_board', array( 'decker_task' ), $args );
	}

	/**
	 * Handle the deletion of a term in the 'decker_board' taxonomy.
	 *
	 * This function ensures that when a board is deleted, any users who have
	 * this board set as their default will have the 'decker_default_board'
	 * user meta removed.
	 *
	 * @param int    $term_id  The ID of the term being deleted.
	 * @param int    $tt_id    The term taxonomy ID (not used here).
	 * @param string $taxonomy The taxonomy slug.
	 */
	public function decker_handle_board_deletion( $term_id, $tt_id, $taxonomy ) {

		// Ensure the taxonomy is 'decker_board'.
		if ( 'decker_board' !== $taxonomy ) {
			return;
		}

		// Sanitize the term ID.
		$term_id = intval( $term_id );

		// Retrieve all users who have this board as their default.
		$users = get_users(
			array(
				'meta_key'   => 'decker_default_board',
				'meta_value' => $term_id,
				'fields'     => 'ID',
			)
		);

		// If there are no users, exit early.
		if ( empty( $users ) ) {
			return;
		}

		// Remove the 'decker_default_board' user meta for each user.
		foreach ( $users as $user_id ) {
			delete_user_meta( $user_id, 'decker_default_board' );
		}
	}

	/**
	 * Prevent term creation for users without permissions.
	 *
	 * @param string $term   The term name.
	 * @param string $taxonomy The taxonomy slug.
	 * @return string|WP_Error Term name or WP_Error on failure.
	 */
	public function prevent_term_creation( $term, $taxonomy ) {
		if ( 'decker_board' !== $taxonomy ) {
			return $term;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'term_creation_blocked', 'You do not have permission to create terms.' );
		}

		return $term;
	}

	/**
	 * Prevent term deletion for users without permissions.
	 *
	 * @param string $term    The term slug.
	 * @param string $taxonomy The taxonomy slug.
	 * @return true|WP_Error True on success, or WP_Error on failure.
	 */
	public function prevent_term_deletion( $term, $taxonomy ) {
		if ( 'decker_board' !== $taxonomy ) {
			return true;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'You do not have permission to delete terms.' );
		}

		return true;
	}

	/**
	 * Customize columns in term list.
	 *
	 * @param array $columns The current columns.
	 * @return array The customized columns.
	 */
	public function customize_columns( $columns ) {
		unset( $columns['description'] );
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			if ( 'name' === $key ) {
				$new_columns[ $key ]  = $value;
				$new_columns['color'] = __( 'Color', 'decker' );
			} else {
				$new_columns[ $key ] = $value;
			}
		}
		return $new_columns;
	}

	/**
	 * Add custom column content in term list.
	 *
	 * @param string $content The current column content.
	 * @param string $column_name The column name.
	 * @param int    $term_id The term ID.
	 * @return string The customized column content.
	 */
	public function add_column_content( $content, $column_name, $term_id ) {
		if ( 'color' === $column_name ) {
			$color   = get_term_meta( $term_id, 'term-color', true );
			$content = '<span style="display:inline-block;width:20px;height:20px;background-color:' . esc_attr( $color ) . ';"></span>';
		}
		return $content;
	}

	/**
	 * Register REST fields for term meta.
	 */
	public function register_rest_fields() {
		register_rest_field(
			'decker_board',
			'meta',
			array(
				'get_callback'    => array( $this, 'get_term_meta' ),
				'update_callback' => null,
				'schema'          => null,
			)
		);
	}

	/**
	 * Get term meta for REST API
	 *
	 * @param array $object Term object array.
	 * @return array Term meta values.
	 */
	public function get_term_meta( $object ) {
		$term_id = $object['id'];
		return array(
			'term-color' => get_term_meta( $term_id, 'term-color', true ),
			'term-show-in-boards' => get_term_meta( $term_id, 'term-show-in-boards', true ),
			'term-show-in-kb' => get_term_meta( $term_id, 'term-show-in-kb', true ),
		);
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Boards' ) ) {
	new Decker_Boards();
}
