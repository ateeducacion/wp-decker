<?php
/**
 * Admin_Settings Class
 *
 * This class handles the settings page for the Decker plugin.
 *
 * @package Decker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Admin_Settings
 *
 * Handles the settings page for the Decker plugin.
 */
class Decker_Admin_Settings {

	/**
	 * Constructor
	 *
	 * Initializes the class by defining hooks.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Render User Profile Field
	 *
	 * Outputs the HTML for the user_profile field.
	 */
	public function user_profile_render() {
		$options = get_option( 'decker_settings', array() );
		$selected_role = isset( $options['user_profile'] ) ? $options['user_profile'] : 'decker_role';

		$roles = wp_roles()->get_names();

		echo '<select name="decker_settings[user_profile]" id="user_profile">';
		foreach ( $roles as $role_value => $role_name ) {
			echo '<option value="' . esc_attr( $role_value ) . '" ' . selected( $selected_role, $role_value, false ) . '>' . esc_html( $role_name ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Select the user profile to be used by the plugin. Administrators will also have access.', 'decker' ) . '</p>';
	}

	/**
	 * Render Alert Message Field
	 *
	 * Outputs the HTML for the alert_message field.
	 */
	public function alert_message_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['alert_message'] ) ? wp_kses_post( $options['alert_message'] ) : '';
		echo '<textarea name="decker_settings[alert_message]" class="large-text" rows="5">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Enter the alert message to display as a banner. Supports HTML. Leave empty to hide.', 'decker' ) . '</p>';
	}

	/**
	 * Render Alert Color Field
	 *
	 * Outputs the HTML for the alert_color field.
	 */
	public function alert_color_render() {
		$options = get_option( 'decker_settings', array() );
		$color = isset( $options['alert_color'] ) ? $options['alert_color'] : 'info';

		$colors = array(
			'success' => 'Success',
			'danger' => 'Danger',
			'warning' => 'Warning',
			'info' => 'Info',
		);

		foreach ( $colors as $value => $label ) {
			echo '<label style="margin-right: 15px;">';
			echo '<input type="radio" name="decker_settings[alert_color]" value="' . esc_attr( $value ) . '" ' . checked( $color, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Select the color for the alert message.', 'decker' ) . '</p>';
	}

	/**
	 * Handle Clear All Data
	 *
	 * Handles the clearing of all Decker data.
	 */
	public function handle_clear_all_data() {
		if ( isset( $_POST['decker_clear_all_data'] ) && check_admin_referer( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce' ) ) {

			// Delete all Decker custom post types and taxonomies
			$custom_post_types = array( 'decker_task' );
			foreach ( $custom_post_types as $post_type ) {
				$posts = get_posts(
					array(
						'post_type'   => $post_type,
						'numberposts' => -1,
						'post_status' => array( 'publish', 'archived' ),
					)
				);
				foreach ( $posts as $post ) {
					wp_delete_post( $post->ID, true );
				}
			}

			// Delete all Decker taxonomies
			$taxonomies = array( 'decker_board', 'decker_label' );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy' => $taxonomy,
						'hide_empty' => false,
					)
				);
				foreach ( $terms as $term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}

			// Delete all Decker options
			delete_option( 'decker_plugin_cache' );

			wp_redirect(
				add_query_arg(
					array(
						'page' => 'decker_settings',
						'decker_data_cleared' => 'true',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Define Hooks
	 *
	 * Registers all the hooks related to the settings page.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_all_data' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Create Menu
	 *
	 * Adds the settings page to the admin menu.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Decker Settings', 'decker' ),
			__( 'Decker', 'decker' ),
			'manage_options',
			'decker_settings',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Settings Initialization
	 *
	 * Registers settings and adds settings sections and fields.
	 */
	public function settings_init() {
		register_setting( 'decker', 'decker_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'decker_main_section',
			__( 'Decker Configuration', 'decker' ),
			array( $this, 'settings_section_callback' ),
			'decker'
		);

		$fields = array(
			'nextcloud_url_base' => __( 'Nextcloud URL Base', 'decker' ),
			'nextcloud_username' => __( 'Nextcloud Username', 'decker' ),
			'nextcloud_access_token' => __( 'Nextcloud Access Token', 'decker' ),
			'decker_ignored_board_ids' => __( 'Ignored Boards', 'decker' ),
			'prioridad_maxima_etiqueta' => __( 'Max Priority Label', 'decker' ),
			'clear_cache_button' => __( 'Clear Cache', 'decker' ),
			'clear_all_data_button' => __( 'Clear All Data', 'decker' ),
			'log_level' => __( 'Log Level', 'decker' ), // Log level radio buttons
			'decker_log' => __( 'Decker Log', 'decker' ), // Log field
			'clear_log_button' => __( 'Clear Log', 'decker' ), // New clear log button
			'alert_message' => __( 'Alert Message', 'decker' ), // Alert message field
			'alert_color' => __( 'Alert Color', 'decker' ), // Alert color radio buttons
			'user_profile' => __( 'User Profile', 'decker' ), // User profile dropdown

		);

		foreach ( $fields as $field_id => $field_title ) {
			add_settings_field(
				$field_id,
				$field_title,
				array( $this, $field_id . '_render' ),
				'decker',
				'decker_main_section'
			);
		}
	}

	/**
	 * Settings Section Callback
	 *
	 * Outputs a description for the settings section.
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Enter your Nextcloud user and access token to configure the Decker plugin.', 'decker' ) . '</p>';
	}

	/**
	 * Render Nextcloud URL Base Field
	 *
	 * Outputs the HTML for the nextcloud_url_base field.
	 */
	public function nextcloud_url_base_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['nextcloud_url_base'] ) ? esc_url( $options['nextcloud_url_base'] ) : '';
		echo '<input type="text" name="decker_settings[nextcloud_url_base]" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	/**
	 * Render Nextcloud Username Field
	 *
	 * Outputs the HTML for the nextcloud_username field.
	 */
	public function nextcloud_username_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['nextcloud_username'] ) ? sanitize_text_field( $options['nextcloud_username'] ) : '';
		echo '<input type="text" name="decker_settings[nextcloud_username]" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	/**
	 * Render Nextcloud Access Token Field
	 *
	 * Outputs the HTML for the nextcloud_access_token field.
	 */
	public function nextcloud_access_token_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['nextcloud_access_token'] ) ? sanitize_text_field( $options['nextcloud_access_token'] ) : '';
		echo '<input type="password" name="decker_settings[nextcloud_access_token]" value="' . esc_attr( $value ) . '" class="regular-text">';
	}

	/**
	 * Render Ignored Boards Field
	 *
	 * Outputs the HTML for the decker_ignored_board_ids field.
	 */
	public function decker_ignored_board_ids_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['decker_ignored_board_ids'] ) ? sanitize_text_field( $options['decker_ignored_board_ids'] ) : '';
		echo '<input type="text" name="decker_settings[decker_ignored_board_ids]" value="' . esc_attr( $value ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Enter the IDs of the boards you want to ignore, separated by commas.', 'decker' ) . '</p>';
	}

	/**
	 * Render Max Priority Label Field
	 *
	 * Outputs the HTML for the prioridad_maxima_etiqueta field.
	 */
	public function prioridad_maxima_etiqueta_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['prioridad_maxima_etiqueta'] ) ? sanitize_text_field( $options['prioridad_maxima_etiqueta'] ) : '';
		echo '<input type="text" name="decker_settings[prioridad_maxima_etiqueta]" value="' . esc_attr( $value ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Set the label for "Max Priority".', 'decker' ) . '</p>';
	}

	/**
	 * Render Clear Cache Button
	 *
	 * Outputs the HTML for the clear_cache_button field.
	 */
	public function clear_cache_button_render() {
		wp_nonce_field( 'decker_clear_cache_action', 'decker_clear_cache_nonce', true, true );
		echo '<input type="submit" name="decker_clear_cache" class="button button-secondary" value="' . esc_attr__( 'Clear Cache', 'decker' ) . '">';
		echo '<p class="description">' . esc_html__( 'Click the button to clear Decker cache.', 'decker' ) . '</p>';
	}

	/**
	 * Render Clear All Data Button
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
	public function clear_all_data_button_render() {
		wp_nonce_field( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce', true, true );
		echo '<input type="submit" name="decker_clear_all_data" class="button button-secondary" style="background-color: red; color: white;" value="' . esc_attr__( 'Clear All Data', 'decker' ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all Decker records? This action cannot be undone.', 'decker' ) ) . '\');">';
		echo '<p class="description">' . esc_html__( 'Click the button to delete all Decker labels, tasks, and boards.', 'decker' ) . '</p>';
	}

	/**
	 * Render Log Level Field
	 *
	 * Outputs the HTML for the log level field.
	 */
	public function log_level_render() {
		$options = get_option( 'decker_settings', array() );
		$log_level = isset( $options['log_level'] ) ? $options['log_level'] : Decker_Utility_Functions::LOG_LEVEL_ERROR;

		$levels = array(
			Decker_Utility_Functions::LOG_LEVEL_DEBUG => 'Debug',
			Decker_Utility_Functions::LOG_LEVEL_INFO => 'Info',
			Decker_Utility_Functions::LOG_LEVEL_ERROR => 'Error',
		);

		foreach ( $levels as $value => $label ) {
			echo '<label style="margin-right: 15px;">';
			echo '<input type="radio" name="decker_settings[log_level]" value="' . esc_attr( $value ) . '" ' . checked( $log_level, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}
	}


	/**
	 * Render  Log
	 *
	 * Outputs the HTML for the render log field.
	 */
	public function decker_log_render() {
		$log = get_option( 'decker_log', '' );
		echo '<textarea readonly rows="10" class="large-text">' . esc_textarea( $log ) . '</textarea>';
	}


	/**
	 * Render Clear Log Button
	 *
	 * Outputs the HTML for the clear log field.
	 */
	public function clear_log_button_render() {
		wp_nonce_field( 'decker_clear_log_action', 'decker_clear_log_nonce', true, true );
		echo '<input type="submit" name="decker_clear_log" class="button button-secondary" value="' . esc_attr__( 'Clear Log', 'decker' ) . '">';
		echo '<p class="description">' . esc_html__( 'Click the button to clear the Decker log.', 'decker' ) . '</p>';
	}


	/**
	 * Options Page
	 *
	 * Renders the settings page.
	 */
	public function options_page() {
		?>
		<form action="options.php" method="post">
			<h2><?php esc_html_e( 'Decker Settings', 'decker' ); ?></h2>
			<?php
			settings_fields( 'decker' );
			do_settings_sections( 'decker' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Admin Notices
	 *
	 * Displays admin notices.
	 */
	public function admin_notices() {
		if ( isset( $_GET['decker_cache_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Decker cache has been cleared.', 'decker' ) . '</p></div>';
		}
		if ( isset( $_GET['decker_data_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Decker records have been deleted.', 'decker' ) . '</p></div>';
		}
		if ( isset( $_GET['decker_log_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Decker log has been cleared.', 'decker' ) . '</p></div>';
		}
	}

	/**
	 * Handle Clear Cache
	 *
	 * Handles the clearing of the cache.
	 */
	public function handle_clear_cache() {
		if ( isset( $_POST['decker_clear_cache'] ) && check_admin_referer( 'decker_clear_cache_action', 'decker_clear_cache_nonce' ) ) {
			delete_option( 'decker_plugin_cache' );
			wp_redirect(
				add_query_arg(
					array(
						'page' => 'decker_settings',
						'decker_cache_cleared' => 'true',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle Clear log
	 *
	 * Handles the clearing of the log.
	 */
	public function handle_clear_log() {
		if ( isset( $_POST['decker_clear_log'] ) && check_admin_referer( 'decker_clear_log_action', 'decker_clear_log_nonce' ) ) {
			update_option( 'decker_log', '' ); // Clear the log by setting it to an empty string
			wp_redirect(
				add_query_arg(
					array(
						'page' => 'decker_settings',
						'decker_log_cleared' => 'true',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}
	}


	/**
	 * Settings Validation
	 *
	 * Validates the settings fields.
	 *
	 * @param array $input The input fields to validate.
	 * @return array The validated fields.
	 */
	public function settings_validate( $input ) {
		if ( ! preg_match( '/\bhttps?:\/\/\S+/i', $input['nextcloud_url_base'] ) ) {
			add_settings_error( 'nextcloud_url_base', 'invalid-url', __( 'Invalid URL.', 'decker' ) );
			$input['nextcloud_url_base'] = '';
		}

		if ( ! preg_match( '/^(\d+(,\d+)*)?$/', $input['decker_ignored_board_ids'] ) ) {
			add_settings_error( 'decker_ignored_board_ids', 'invalid-ids', __( 'Invalid IDs. Must be numbers separated by commas.', 'decker' ) );
			$input['decker_ignored_board_ids'] = '';
		}

		// Validate log level
		if ( ! in_array(
			$input['log_level'],
			array(
				Decker_Utility_Functions::LOG_LEVEL_DEBUG,
				Decker_Utility_Functions::LOG_LEVEL_INFO,
				Decker_Utility_Functions::LOG_LEVEL_ERROR,
			)
		) ) {
			$input['log_level'] = Decker_Utility_Functions::LOG_LEVEL_ERROR; // Default to ERROR if invalid
		}

		// Validate alert color
		$valid_colors = array( 'success', 'danger', 'warning', 'info' );
		if ( ! in_array( $input['alert_color'], $valid_colors ) ) {
			$input['alert_color'] = 'info'; // Default to info if invalid
		}

		// Validate user profile
		$roles = wp_roles()->get_names();
		if ( ! array_key_exists( $input['user_profile'], $roles ) ) {
			$input['user_profile'] = 'decker_role'; // Default to decker_role if invalid
		}
		$input['alert_message'] = wp_kses_post( $input['alert_message'] );

		return $input;
	}
}
