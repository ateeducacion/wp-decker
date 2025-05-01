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
		add_action( 'decker_board_add_form_fields', array( $this, 'add_color_field' ), 10, 2 );
		add_action( 'decker_board_edit_form_fields', array( $this, 'edit_color_field' ), 10, 2 );
		add_action( 'created_decker_board', array( $this, 'save_color_meta' ), 10, 2 );
		add_action( 'edited_decker_board', array( $this, 'save_color_meta' ), 10, 2 );
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
	 * Add color field to add term form.
	 */
	public function add_color_field() {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );
		?>
		<div class="form-field term-color-wrap">
			<label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label>
			<input name="term-color" id="term-color" type="color" value="">
		</div>
		<div class="form-field term-show-in-boards-wrap">
			<label for="term-show-in-boards">
				<input type="checkbox" name="term-show-in-boards" id="term-show-in-boards" value="1" checked>
				<?php esc_html_e( 'Show in Boards', 'decker' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Display this board in the Boards section of the sidebar', 'decker' ); ?></p>
		</div>
		<div class="form-field term-show-in-kb-wrap">
			<label for="term-show-in-kb">
				<input type="checkbox" name="term-show-in-kb" id="term-show-in-kb" value="1" checked>
				<?php esc_html_e( 'Show in Knowledge Base', 'decker' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Display this board in the Knowledge Base section of the sidebar', 'decker' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Add color field to edit term form.
	 *
	 * @param WP_Term $term The current term object.
	 */
	public function edit_color_field( $term ) {
		wp_nonce_field( 'decker_term_action', 'decker_term_nonce' );

		$term_id = $term->term_id;
		$color   = get_term_meta( $term_id, 'term-color', true );
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb = get_term_meta( $term_id, 'term-show-in-kb', true );

		// Default to true if not set.
		if ( '' === $show_in_boards ) {
			$show_in_boards = '1';
		}
		if ( '' === $show_in_kb ) {
			$show_in_kb = '1';
		}
		?>
		<tr class="form-field term-color-wrap">
			<th scope="row"><label for="term-color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
			<td>
				<input name="term-color" id="term-color" type="color" value="<?php echo esc_attr( $color ) ? esc_attr( $color ) : ''; ?>">
			</td>
		</tr>
		<tr class="form-field term-show-in-boards-wrap">
			<th scope="row"><?php esc_html_e( 'Visibility', 'decker' ); ?></th>
			<td>
				<label for="term-show-in-boards">
					<input type="checkbox" name="term-show-in-boards" id="term-show-in-boards" value="1" <?php checked( $show_in_boards, '1' ); ?>>
					<?php esc_html_e( 'Show in Boards', 'decker' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Display this board in the Boards section of the sidebar', 'decker' ); ?></p>
				
				<label for="term-show-in-kb">
					<input type="checkbox" name="term-show-in-kb" id="term-show-in-kb" value="1" <?php checked( $show_in_kb, '1' ); ?>>
					<?php esc_html_e( 'Show in Knowledge Base', 'decker' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Display this board in the Knowledge Base section of the sidebar', 'decker' ); ?></p>
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

		// Save visibility settings.
		$show_in_boards = isset( $_POST['term-show-in-boards'] ) ? '1' : '0';
		$show_in_kb = isset( $_POST['term-show-in-kb'] ) ? '1' : '0';

		update_term_meta( $term_id, 'term-show-in-boards', $show_in_boards );
		update_term_meta( $term_id, 'term-show-in-kb', $show_in_kb );
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
