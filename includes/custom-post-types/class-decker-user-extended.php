<?php
/**
 * User Extended model for the Decker plugin.
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_User_Extended
 *
 * Handles the Custom Post Type and its metaboxes for users in the Decker plugin.
 */
class Decker_User_Extended {

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
		add_action( 'show_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_custom_user_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'show_custom_user_column_content' ), 10, 3 );
	}

	/**
	 * Add custom fields to user profile.
	 *
	 * @param WP_User $user The user object.
	 */
	public function add_custom_user_profile_fields( $user ) {
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_style( 'wp-color-picker' );
		?>
		<h3><?php esc_html_e( 'Additional Information', 'decker' ); ?></h3>

		<table class="form-table">
			<tr>
				<th><label for="decker_color"><?php esc_html_e( 'Color', 'decker' ); ?></label></th>
				<td>
					<input type="text" name="decker_color" id="decker_color" value="<?php echo esc_attr( get_the_author_meta( 'decker_color', $user->ID ) ); ?>" class="regular-text" />
					<br />
					<span class="description"><?php esc_html_e( 'Select your favorite color.', 'decker' ); ?></span>
				</td>
			</tr>
		</table>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('#decker_color').wpColorPicker();
			});
		</script>
		<?php
	}

	/**
	 * Save custom fields.
	 *
	 * @param int $user_id The user ID.
	 */
	public function save_custom_user_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}

		check_admin_referer( 'update-user_' . $user_id );

		if ( isset( $_POST['decker_color'] ) ) {
			$decker_color = sanitize_text_field( wp_unslash( $_POST['decker_color'] ) );
			update_user_meta( $user_id, 'decker_color', $decker_color );
		}

	}

	/**
	 * Add custom column to users list.
	 *
	 * @param array $columns The current columns.
	 * @return array The customized columns.
	 */
	public function add_custom_user_columns( $columns ) {
		$columns['decker_color'] = __( 'Color', 'decker' );
		return $columns;
	}

	/**
	 * Show custom column content in users list.
	 *
	 * @param string $value The current column content.
	 * @param string $column_name The column name.
	 * @param int    $user_id The user ID.
	 * @return string The customized column content.
	 */
	public function show_custom_user_column_content( $value, $column_name, $user_id ) {
		if ( 'decker_color' === $column_name ) {
			$color = get_user_meta( $user_id, 'decker_color', true );
			if ( $color ) {
				$value = '<span style="display:inline-block;width:20px;height:20px;background-color:' . esc_attr( $color ) . ';"></span>';
			} else {
				$value = esc_html( '—' );
			}
		}
		return $value;
	}

}

// Instantiate the class.
if ( class_exists( 'Decker_User_Extended' ) ) {
	new Decker_User_Extended();
}
