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
	 *
	 * Initializes the class by setting up hooks.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_custom_user_profile_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_custom_user_profile_fields' ) );
		add_filter( 'manage_users_columns', array( $this, 'add_custom_user_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'show_custom_user_column_content' ), 10, 3 );
		add_action( 'user_register', array( $this, 'generate_user_secret' ) );
		register_activation_hook( __FILE__, array( $this, 'generate_general_secret' ) );
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
			<tr>
				<th><label for="decker_custom_field"><?php esc_html_e( 'Custom Field', 'decker' ); ?></label></th>
				<td>
					<input type="text" name="decker_custom_field" id="decker_custom_field" value="<?php echo esc_attr( get_the_author_meta( 'decker_custom_field', $user->ID ) ); ?>" class="regular-text" />
					<br />
					<span class="description"><?php esc_html_e( 'Enter additional information.', 'decker' ); ?></span>
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

		if ( isset( $_POST['decker_custom_field'] ) ) {
			$decker_custom_field = sanitize_text_field( wp_unslash( $_POST['decker_custom_field'] ) );
			update_user_meta( $user_id, 'decker_custom_field', $decker_custom_field );
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
				$value = esc_html__( 'Undefined', 'decker' );
			}
		}
		return $value;
	}

	/**
	 * Generate user secret key.
	 *
	 * @param int $user_id The user ID.
	 */
	public function generate_user_secret( $user_id ) {
		$secret = wp_generate_password( 20, false );
		update_user_meta( $user_id, 'user_ics_secret', $secret );
	}

	/**
	 * Generate general secret key.
	 */
	public function generate_general_secret() {
		$secret = wp_generate_password( 20, false );
		update_option( 'general_ics_secret', $secret );
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_User_Extended' ) ) {
	new Decker_User_Extended();
}
