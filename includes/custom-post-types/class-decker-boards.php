<?php
/**
 * Board Model for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

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
	 * Registers all the hooks related to the decker_label taxonomy.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'decker_board_add_form_fields', array( $this, 'add_color_field' ), 10, 2 );
		add_action( 'decker_board_edit_form_fields', array( $this, 'edit_color_field' ), 10, 2 );
		add_action( 'created_decker_board', array( $this, 'save_color_meta' ), 10, 2 );
		add_action( 'edited_decker_board', array( $this, 'save_color_meta' ), 10, 2 );
		add_action( 'admin_head', array( $this, 'hide_description' ) );
		add_filter( 'manage_edit-decker_board_columns', array( $this, 'customize_columns' ) );
		add_filter( 'manage_decker_board_custom_column', array( $this, 'add_column_content' ), 10, 3 );
	}

	/**
	 * Register the decker_board taxonomy.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Boards', 'taxonomy general name', 'decker' ),
			'singular_name'     => _x( 'Board', 'taxonomy singular name', 'decker' ),
			'search_items'      => __( 'Search Boards', 'decker' ),
			'all_items'         => __( 'All Boards', 'decker' ),
			'edit_item'         => __( 'Edit Board', 'decker' ),
			'update_item'       => __( 'Update Board', 'decker' ),
			'add_new_item'      => __( 'Add New Board', 'decker' ),
			'new_item_name'     => __( 'New Board Name', 'decker' ),
			'menu_name'         => __( 'Boards', 'decker' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'show_tagcloud'     => false,
			'show_in_quick_edit' => false,
			'rewrite'           => array( 'slug' => 'decker_board' ),
			'show_in_rest'      => false,
			'rest_base'         => 'boards',
			'can_export'        => true,
			'capabilities' => array(
			    'assign_terms' => 'read',
			),
		);

		// function allow_all_to_assign_decker_boards( $allcaps, $caps, $args, $user ) {
		//     $allcaps['assign_decker_boards'] = true;
		//     return $allcaps;
		// }
		// add_filter( 'user_has_cap', 'allow_all_to_assign_decker_boards', 10, 4 );

		register_taxonomy( 'decker_board', array( 'decker_task' ), $args );
	}

	/**
	 * Add color field to add term form.
	 */
	public function add_color_field() {
		?>
		<div class="form-field term-color-wrap">
			<label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label>
			<input name="term-color" id="term-color" type="color" value="">
		</div>
		<?php
	}

	/**
	 * Add color field to edit term form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	public function edit_color_field( $term ) {
		$term_id = $term->term_id;
		$color = get_term_meta( $term_id, 'term-color', true );
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
	 * Save color metadata when a term is created or edited.
	 *
	 * @param int $term_id The term ID.
	 */
	public function save_color_meta( $term_id ) {
		if ( isset( $_POST['term-color'] ) ) {
			$term_color = sanitize_hex_color( wp_unslash( $_POST['term-color'] ) );
			update_term_meta( $term_id, 'term-color', $term_color );
		}
	}

	/**
	 * Hide description field in term forms.
	 */
	public function hide_description() {
		if ( isset( $_GET['taxonomy'] ) && 'decker_board' === $_GET['taxonomy'] ) {
			echo '<style>.term-description-wrap, .column-description { display: none; }</style>';
		}
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
				$new_columns[ $key ] = $value;
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
			$color = get_term_meta( $term_id, 'term-color', true );
			$content = '<span style="display:inline-block;width:20px;height:20px;background-color:' . esc_attr( $color ) . ';"></span>';
		}
		return $content;
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Boards' ) ) {
	new Decker_Boards();
}
