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
		add_action( 'decker_label_add_form_fields', array( $this, 'add_color_field' ), 10, 2 );
		add_action( 'decker_label_edit_form_fields', array( $this, 'edit_color_field' ), 10, 2 );
		add_action( 'created_decker_label', array( $this, 'save_color_meta' ), 10, 2 );
		add_action( 'edited_decker_label', array( $this, 'save_color_meta' ), 10, 2 );

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
			'rest_base'          => 'decker_label',
			'rest_controller_class' => 'WP_REST_Terms_Controller',
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
	 * Add color field in the add new term form.
	 */
	public function add_color_field() {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );
		?>
		<div class="form-field term-color-wrap">
			<label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label>
			<input name="term-color" id="term-color" type="color" value="">
		</div>
		<?php
	}

	/**
	 * Add color field in the edit term form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	public function edit_color_field( $term ) {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );

		$term_id = $term->term_id;
		$color   = get_term_meta( $term_id, 'term-color', true );
		?>
		<tr class="form-field term-color-wrap">
			<th scope="row"><label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
			<td>
				<input name="term-color" id="term-color" type="color" value="<?php echo esc_attr( $color ) ? esc_attr( $color ) : ''; ?>">
			</td>
		</tr>
		<?php
	}

	/**
	 * Save the color meta when a term is created or edited.
	 *
	 * @param int $term_id The term ID.
	 */
	public function save_color_meta( $term_id ) {

		// Check if nonce is set and verified.
		if ( isset( $_POST['decker_term_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['decker_term_nonce'] ) );
			if ( ! wp_verify_nonce( $nonce, 'decker_term_action' ) ) {
				return;
			}
		} else {
			return;
		}

		// Check user capabilities.
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		if ( isset( $_POST['term-color'] ) ) {
			$term_color = sanitize_hex_color( wp_unslash( $_POST['term-color'] ) );
			update_term_meta( $term_id, 'term-color', $term_color );
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
}

// Instantiate the class.
if ( class_exists( 'Decker_Labels' ) ) {
	new Decker_Labels();
}
