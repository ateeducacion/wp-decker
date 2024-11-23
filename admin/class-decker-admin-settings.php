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

	/*
	 * Render Shared Key Field
	 *
	 * Outputs the HTML for the shared_key field, generating it only if it does not exist or does not meet the criteria.
	 */
	public function shared_key_render() {
	    $options = get_option( 'decker_settings', array() );

	    // Generate a new shared key (UUID) only if it does not exist or does not meet criteria
	    if ( empty( $options['shared_key'] ) ) {
	        $options['shared_key'] = wp_generate_uuid4();
	        // Save the newly generated UUID back to the options
	        update_option( 'decker_settings', $options );
	    }

	    $value = sanitize_text_field( $options['shared_key'] );
	    echo '<input type="text" name="decker_settings[shared_key]" pattern=".{8,}" value="' . esc_attr( $value ) . '" class="regular-text" pattern="" title="The key must be at least 8 characters long and include letters, numbers, and symbols." required>';
		echo '<p class="description">' . esc_html__( 'Provide the Bearer token in the Authorization header for the email-to-post endpoint. Example request:', 'decker' ) . '</p>';
		echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">';
		echo 'POST ' . esc_url( get_site_url() ) . '/wp-json/decker/v1/email-to-post';
		echo "\nHeader: Authorization: Bearer YOUR_SHARED_KEY";
		echo '</pre>';

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
			'shared_key' => __( 'Shared Key', 'decker' ),
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
		echo '<p>' . esc_html__( 'Configure the Decker plugin settings.', 'decker' ) . '</p>';
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
		if ( isset( $_GET['decker_data_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Decker records have been deleted.', 'decker' ) . '</p></div>';
		}
		if ( isset( $_GET['decker_log_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Decker log has been cleared.', 'decker' ) . '</p></div>';
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


	    // Validate shared key
	    $input['shared_key'] = isset( $input['shared_key'] ) ? sanitize_text_field( $input['shared_key'] ) : '';

	    // Validate log level
	    if ( isset( $input['log_level'] ) && ! in_array(
	        $input['log_level'],
	        array(
	            Decker_Utility_Functions::LOG_LEVEL_DEBUG,
	            Decker_Utility_Functions::LOG_LEVEL_INFO,
	            Decker_Utility_Functions::LOG_LEVEL_ERROR,
	        )
	    ) ) {
	        $input['log_level'] = Decker_Utility_Functions::LOG_LEVEL_ERROR; // Default to ERROR if invalid
	    } else {
	        $input['log_level'] = isset( $input['log_level'] ) ? $input['log_level'] : Decker_Utility_Functions::LOG_LEVEL_ERROR;
	    }

	    // Validate alert color
	    $valid_colors = array( 'success', 'danger', 'warning', 'info' );
	    if ( isset( $input['alert_color'] ) && ! in_array( $input['alert_color'], $valid_colors ) ) {
	        $input['alert_color'] = 'info'; // Default to info if invalid
	    } else {
	        $input['alert_color'] = isset( $input['alert_color'] ) ? $input['alert_color'] : 'info';
	    }

	    // Validate user profile
	    $roles = wp_roles()->get_names();
	    if ( isset( $input['user_profile'] ) && ! array_key_exists( $input['user_profile'], $roles ) ) {
	        $input['user_profile'] = 'decker_role'; // Default to decker_role if invalid
	    } else {
	        $input['user_profile'] = isset( $input['user_profile'] ) ? $input['user_profile'] : 'decker_role';
	    }

	    // Validate alert message
	    $input['alert_message'] = isset( $input['alert_message'] ) ? wp_kses_post( $input['alert_message'] ) : '';

	    return $input;
	}
}
