<?php
/**
 * Labels model for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Labels
 *
 * Handles the decker_label taxonomy.
 */
class Decker_Labels {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks
	 *
	 * Registers all the hooks related to the decker_label taxonomy.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'register_meta' ) );

		// The colour picker owns its own hooks.
		new Decker_Term_Color_Field( 'decker_label' );

		add_action( 'admin_head', array( $this, 'hide_description' ) );
		add_filter( 'manage_edit-decker_label_columns', array( $this, 'customize_columns' ) );
		add_filter( 'manage_decker_label_custom_column', array( $this, 'add_column_content' ), 10, 3 );

		// Enforce capability checks.
		add_filter( 'pre_insert_term', array( $this, 'prevent_term_creation' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'prevent_term_deletion' ), 10, 2 );
	}

	/**
	 * Register the decker_label taxonomy.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'          => _x( 'Labels', 'taxonomy general name', 'decker' ),
			'singular_name' => _x( 'Label', 'taxonomy singular name', 'decker' ),
			'search_items'  => __( 'Search Labels', 'decker' ),
			'all_items'     => __( 'All Labels', 'decker' ),
			'edit_item'     => __( 'Edit Label', 'decker' ),
			'update_item'   => __( 'Update Label', 'decker' ),
			'add_new_item'  => __( 'Add New Label', 'decker' ),
			'new_item_name' => __( 'New Label Name', 'decker' ),
			'menu_name'     => __( 'Labels', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'query_var'          => true,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'rewrite'            => array( 'slug' => 'decker_label' ),
			'show_in_rest'       => true,
			'rest_base'          => 'labels',
			'show_in_rest_meta'  => true,
			'can_export'         => true,
			'capabilities'       => array(
				'manage_terms' => 'edit_posts',
				'edit_terms'   => 'edit_posts',
				'delete_terms' => 'edit_posts',
				'assign_terms' => 'edit_posts',
			),
		);

		register_taxonomy( 'decker_label', array( 'decker_task' ), $args );
	}

	/**
	 * Prevent term creation for users without permissions.
	 *
	 * @param string $term   The term name.
	 * @param string $taxonomy The taxonomy slug.
	 * @return string|WP_Error Term name or WP_Error on failure.
	 */
	public function prevent_term_creation( $term, $taxonomy ) {
		if ( 'decker_label' !== $taxonomy ) {
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
		if ( 'decker_label' !== $taxonomy ) {
			return true;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'You do not have permission to delete terms.' );
		}

		return true;
	}

	/**
	 * Hide the description field in the decker_label taxonomy term form.
	 */
	public function hide_description() {
		if ( isset( $_GET['taxonomy'] ) && 'decker_label' == $_GET['taxonomy'] ) {
			echo '<style>.term-description-wrap { display: none; }</style>';
		}
	}

	/**
	 * Customize the columns displayed in the decker_label taxonomy term list.
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
	 * Display the color in the custom column of the term list.
	 *
	 * @param string $content The current column content.
	 * @param string $column_name The name of the column.
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
	 * Register term meta for REST API
	 */
	public function register_meta() {
		register_term_meta(
			'decker_label',
			'term-color',
			array(
				'type' => 'string',
				'single' => true,
				'show_in_rest' => true,
			)
		);
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Labels' ) ) {
	new Decker_Labels();
}
