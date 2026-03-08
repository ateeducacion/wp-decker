<?php
/**
 * Admin_Settings Class
 *
 * This class handles the settings page for the Decker plugin.
 *
 * @package Decker
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

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
	 * Render Shared Key Field
	 *
	 * Outputs the HTML for the shared_key field, generating it only if it does not exist or does not meet the criteria.
	 */
	public function shared_key_render() {
		$options = get_option( 'decker_settings', array() );

		// Generate a new shared key (UUID) only if it does not exist or does not meet criteria.
		if ( empty( $options['shared_key'] ) ) {
			$options['shared_key'] = wp_generate_uuid4();
			// Save the newly generated UUID back to the options.
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
	 * Render Allow Email Notifications Field.
	 *
	 * Outputs the HTML for the allow_email_notifications field.
	 */
	public function allow_email_notifications_render() {
		$options = get_option( 'decker_settings', array() );
		$checked = isset( $options['allow_email_notifications'] ) && '1' === $options['allow_email_notifications'];

		echo '<label>';
		echo '<input type="checkbox" name="decker_settings[allow_email_notifications]" value="1" ' . checked( $checked, true, false ) . '>';
		echo esc_html__( 'Enable email notifications for all plugin events.', 'decker' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'This setting allows users to manage email notifications in their profile. By default, all notifications are enabled.', 'decker' ) . '</p>';
	}

	/**
	 * Render Collaborative Editing Field.
	 *
	 * Outputs the HTML for the collaborative_editing field.
	 */
	public function collaborative_editing_render() {
		$options = get_option( 'decker_settings', array() );
		$checked = isset( $options['collaborative_editing'] ) && '1' === $options['collaborative_editing'];

		echo '<label>';
		echo '<input type="checkbox" name="decker_settings[collaborative_editing]" value="1" ' . checked( $checked, true, false ) . '>';
		echo esc_html__( 'Enable real-time collaborative editing for tasks.', 'decker' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, multiple users can edit the same task simultaneously with real-time synchronization using WebRTC.', 'decker' ) . '</p>';
	}

	/**
	 * Render Signaling Server Field.
	 *
	 * Outputs the HTML for the signaling_server field.
	 */
	public function signaling_server_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = isset( $options['signaling_server'] ) ? sanitize_text_field( $options['signaling_server'] ) : 'wss://signaling.yjs.dev';

		echo '<input type="url" name="decker_settings[signaling_server]" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="wss://signaling.yjs.dev">';
		echo '<p class="description">' . esc_html__( 'WebRTC signaling server URL for collaborative editing. Leave empty to use the default public server (wss://signaling.yjs.dev).', 'decker' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Public servers:', 'decker' ) . '</strong></p>';
		echo '<ul class="description" style="list-style: disc; margin-left: 20px;">';
		echo '<li><code>wss://signaling.yjs.dev</code> ' . esc_html__( '(Default - Global)', 'decker' ) . '</li>';
		echo '<li><code>wss://y-webrtc-signaling-eu.herokuapp.com</code> ' . esc_html__( '(Europe)', 'decker' ) . '</li>';
		echo '<li><code>wss://y-webrtc-signaling-us.herokuapp.com</code> ' . esc_html__( '(United States)', 'decker' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * Get the selected AI provider, with fallback for legacy settings.
	 *
	 * @param array $options Plugin settings.
	 * @return string Provider slug.
	 */
	protected function get_ai_provider_value( $options ) {
		$valid_providers = array( 'openai', 'openrouter', 'gemini' );

		if ( ! empty( $options['ai_provider'] ) ) {
			$provider = sanitize_key( $options['ai_provider'] );
			if ( in_array( $provider, $valid_providers, true ) ) {
				return $provider;
			}
		}

		if ( ! empty( $options['openai_api_url'] ) ) {
			$legacy_url = strtolower( (string) $options['openai_api_url'] );

			if ( false !== strpos( $legacy_url, 'openrouter.ai' ) ) {
				return 'openrouter';
			}

			if (
				false !== strpos( $legacy_url, 'generativelanguage.googleapis.com' ) ||
				false !== strpos( $legacy_url, 'googleapis.com' )
			) {
				return 'gemini';
			}
		}

		return 'openai';
	}

	/**
	 * Get the configured AI API key, with fallback for legacy settings.
	 *
	 * @param array $options Plugin settings.
	 * @return string API key.
	 */
	protected function get_ai_api_key_value( $options ) {
		if ( ! empty( $options['ai_api_key'] ) ) {
			return sanitize_text_field( $options['ai_api_key'] );
		}

		if ( ! empty( $options['openai_api_key'] ) ) {
			return sanitize_text_field( $options['openai_api_key'] );
		}

		return '';
	}

	/**
	 * Check whether server-side AI settings should be shown.
	 *
	 * @param array $options Plugin settings.
	 * @return bool True when an AI API key is saved.
	 */
	protected function has_saved_ai_api_key( $options ) {
		return '' !== $this->get_ai_api_key_value( $options );
	}

	/**
	 * Get the default model for the selected provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string Default model identifier.
	 */
	protected function get_default_ai_model( $provider ) {
		$defaults = array(
			'openai'     => 'gpt-5-mini',
			'openrouter' => 'openai/gpt-5-mini',
			'gemini'     => 'gemini-2.0-flash',
		);

		return isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : $defaults['openai'];
	}

	/**
	 * Get the configured AI model, with fallback for legacy settings.
	 *
	 * @param array $options Plugin settings.
	 * @return string Model identifier.
	 */
	protected function get_ai_model_value( $options ) {
		$provider = $this->get_ai_provider_value( $options );

		if ( ! empty( $options['ai_model'] ) ) {
			return sanitize_text_field( $options['ai_model'] );
		}

		if ( ! empty( $options['openai_model'] ) ) {
			return sanitize_text_field( $options['openai_model'] );
		}

		return $this->get_default_ai_model( $provider );
	}

	/**
	 * Determine if the AI model field should be shown.
	 *
	 * @param array $options Plugin settings.
	 * @return bool True when the model field should be shown.
	 */
	protected function should_show_ai_model_field( $options ) {
		return $this->has_saved_ai_api_key( $options );
	}

	/**
	 * Determine if the AI URL override field should be shown.
	 *
	 * @param array $options Plugin settings.
	 * @return bool True when the URL override field should be shown.
	 */
	protected function should_show_ai_url_override_field( $options ) {
		return $this->has_saved_ai_api_key( $options )
			&& 'gemini' !== $this->get_ai_provider_value( $options );
	}

	/**
	 * Get a setting value from the submitted input with legacy fallback.
	 *
	 * @param array  $input        Submitted input.
	 * @param string $key          Preferred setting key.
	 * @param string $legacy_key   Legacy fallback key.
	 * @return string Submitted value.
	 */
	protected function get_input_setting_value( $input, $key, $legacy_key ) {
		if ( isset( $input[ $key ] ) ) {
			return sanitize_text_field( $input[ $key ] );
		}

		if ( isset( $input[ $legacy_key ] ) ) {
			return sanitize_text_field( $input[ $legacy_key ] );
		}

		return '';
	}

	/**
	 * Render AI Provider Field.
	 *
	 * Outputs the HTML for the ai_provider settings field.
	 */
	public function ai_provider_render() {
		$options  = get_option( 'decker_settings', array() );
		$provider = $this->get_ai_provider_value( $options );

		echo '<select name="decker_settings[ai_provider]">';
		echo '<option value="openai" ' . selected( $provider, 'openai', false ) . '>' .
			esc_html__( 'OpenAI', 'decker' ) . '</option>';
		echo '<option value="openrouter" ' . selected( $provider, 'openrouter', false ) . '>' .
			esc_html__( 'OpenRouter', 'decker' ) . '</option>';
		echo '<option value="gemini" ' . selected( $provider, 'gemini', false ) . '>' .
			esc_html__( 'Gemini', 'decker' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Choose which provider to use for server-side AI fallback. Browser-native AI remains preferred when available.', 'decker' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'OpenAI uses OpenAI credentials and model names. OpenRouter uses OpenRouter credentials and model names. Gemini uses Gemini credentials and model names.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI API Key Field.
	 *
	 * Outputs the HTML for the ai_api_key settings field.
	 */
	public function ai_api_key_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = $this->get_ai_api_key_value( $options );

		echo '<input type="password" name="decker_settings[ai_api_key]" class="regular-text" '
			. 'value="' . esc_attr( $value ) . '" autocomplete="off">';
		echo '<p class="description">' . esc_html__( 'Enter the API key for the selected AI provider to enable server-side AI text improvement. If left empty, the feature will still work in browsers that support the built-in Prompt API (for example, Chrome).', 'decker' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Model and URL override settings are shown after an API key has been saved.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI API URL Override Field.
	 *
	 * Outputs the HTML for the openai_api_url settings field.
	 */
	public function openai_api_url_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = isset( $options['openai_api_url'] ) ? esc_url( $options['openai_api_url'] ) : 'https://api.openai.com/v1/chat/completions';

		echo '<input type="url" name="decker_settings[openai_api_url]" class="regular-text code" '
			. 'value="' . esc_attr( $value ) . '" placeholder="https://api.openai.com/v1/chat/completions">';
		echo '<p class="description">' . esc_html__( 'Optional advanced override for the HTTPS chat completions endpoint. Leave the default value to use the selected provider endpoint automatically.', 'decker' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'WordPress Playground may block server-side provider requests because outbound HTTP is proxied through the browser. In that environment, browser-native AI is usually more reliable.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI Model Field.
	 *
	 * Outputs the HTML for the ai_model settings field.
	 */
	public function ai_model_render() {
		$options        = get_option( 'decker_settings', array() );
		$selected_model = $this->get_ai_model_value( $options );

		$models = array(
			'gpt-5-mini'         => 'OpenAI: gpt-5-mini',
			'gpt-5'              => 'OpenAI: gpt-5',
			'openai/gpt-5-mini'  => 'OpenRouter: openai/gpt-5-mini',
			'openai/gpt-5'       => 'OpenRouter: openai/gpt-5',
			'gemini-2.0-flash'   => 'Gemini: gemini-2.0-flash',
			'gemini-2.5-flash'   => 'Gemini: gemini-2.5-flash',
		);

		echo '<input type="text" name="decker_settings[ai_model]" class="regular-text" '
			. 'value="' . esc_attr( $selected_model ) . '" list="decker-openai-models" autocomplete="off">';
		echo '<datalist id="decker-openai-models">';
		foreach ( $models as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</datalist>';
		echo '<p class="description">' . esc_html__( 'Enter the model identifier for the selected provider. Examples: OpenAI can use gpt-5-mini, OpenRouter can use openai/gpt-5-mini, and Gemini can use gemini-2.0-flash.', 'decker' ) . '</p>';
	}

	/**
	 * Render User Profile Field.
	 *
	 * Outputs the HTML for the minimum_user_profile field, displaying only roles with edit permissions.
	 */
	public function minimum_user_profile_render() {
		// Get saved plugin options.
		$options       = get_option( 'decker_settings', array() );

		// Default to 'editor' if no user profile is selected.
		$selected_role = isset( $options['minimum_user_profile'] ) && ! empty( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// Retrieve all registered roles in WordPress.
		$roles = wp_roles()->roles;

		// Filter roles to include only those with 'edit_posts' capability.
		$editable_roles = array_filter(
			$roles,
			function ( $role ) {
				return isset( $role['capabilities']['edit_posts'] ) && $role['capabilities']['edit_posts'];
			}
		);

		// Render the select dropdown for user profiles.
		echo '<select name="decker_settings[minimum_user_profile]" id="minimum_user_profile">';
		foreach ( $editable_roles as $role_value => $role_data ) {
			echo '<option value="' . esc_attr( $role_value ) . '" ' . selected( $selected_role, $role_value, false ) . '>' . esc_html( $role_data['name'] ) . '</option>';
		}
		echo '</select>';

		// Add a description below the dropdown.
		echo '<p class="description">' . esc_html__( 'Select the minimum user profile that can use Decker.', 'decker' ) . '</p>';
	}


	/**
	 * Render Alert Message Field.
	 *
	 * Outputs the HTML for the alert_message field.
	 */
	public function alert_message_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = isset( $options['alert_message'] ) ? wp_kses_post( $options['alert_message'] ) : '';
		echo '<textarea name="decker_settings[alert_message]" class="large-text" rows="5">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Enter the alert message to display as a banner. Supports HTML. Leave empty to hide.', 'decker' ) . '</p>';
	}

	/**
	 * Render Alert Color Field.
	 *
	 * Outputs the HTML for the alert_color field.
	 */
	public function alert_color_render() {
		$options = get_option( 'decker_settings', array() );
		$color   = isset( $options['alert_color'] ) ? $options['alert_color'] : 'info';

		$colors = array(
			'success' => 'Success',
			'danger'  => 'Danger',
			'warning' => 'Warning',
			'info'    => 'Info',
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
	 * Handle Clear All Data.
	 *
	 * Handles the clearing of all Decker data.
	 */
	public function handle_clear_all_data() {
		if ( isset( $_POST['decker_clear_all_data'] ) && check_admin_referer( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce' ) ) {

			// Delete all Decker custom post types and taxonomies.
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

			// Delete all Decker taxonomies.
			$taxonomies = array( 'decker_board', 'decker_label' );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				foreach ( $terms as $term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}

			// Redirect and terminate execution.
			$redirect_url = add_query_arg(
				array(
					'page'                => 'decker_settings',
					'decker_data_cleared' => 'true',
				),
				admin_url( 'options-general.php' )
			);

			$this->redirect_and_exit( $redirect_url );

		}
	}

	/**
	 * Redirect and Exit.
	 *
	 * Handles the redirection and termination of execution.
	 *
	 * @param string $url URL to redirect to.
	 */
	protected function redirect_and_exit( $url ) {
		wp_redirect( $url );
		exit;
	}

	/**
	 * Define Hooks.
	 *
	 * Registers all the hooks related to the settings page.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_all_data' ) );
	}

	/**
	 * Create Menu.
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
	 * Settings Initialization.
	 *
	 * Registers settings and adds settings sections and fields.
	 */
	public function settings_init() {
		$options = get_option( 'decker_settings', array() );

		register_setting( 'decker', 'decker_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'decker_main_section',
			__( 'Decker Configuration', 'decker' ),
			array( $this, 'settings_section_callback' ),
			'decker'
		);

		$fields = array(
			'alert_color'           => __( 'Alert Color', 'decker' ), // Alert color radio buttons.
			'alert_message'         => __( 'Alert Message', 'decker' ), // Alert message field.
			'minimum_user_profile'  => __( 'Minimum User Profile', 'decker' ), // User profile dropdown.
			'shared_key'            => __( 'Shared Key', 'decker' ),
			'allow_email_notifications' => __( 'Allow Email Notifications', 'decker' ),
			'collaborative_editing' => __( 'Collaborative Editing', 'decker' ),
			'signaling_server'      => __( 'Signaling Server', 'decker' ),
			'ai_provider'           => __( 'AI Provider', 'decker' ),
			'ai_api_key'            => __( 'AI API Key', 'decker' ),
		);

		if ( $this->should_show_ai_model_field( $options ) ) {
			$fields['ai_model'] = __( 'AI Model', 'decker' );
		}

		if ( $this->should_show_ai_url_override_field( $options ) ) {
			$fields['openai_api_url'] = __( 'AI API URL Override', 'decker' );
		}

		$fields['clear_all_data_button'] = __( 'Clear All Data', 'decker' );
		$fields['ignored_users']         = __( 'Ignored Users', 'decker' );

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
	 * Settings Section Callback.
	 *
	 * Outputs a description for the settings section.
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure the Decker plugin settings.', 'decker' ) . '</p>';
	}




	/**
	 * Render Clear All Data Button.
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
	/**
	 * Render Ignored Users Field.
	 *
	 * Outputs the HTML for the ignored_users field.
	 */
	public function ignored_users_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['ignored_users'] ) ? sanitize_text_field( $options['ignored_users'] ) : '';
		echo '<input type="text" name="decker_settings[ignored_users]" class="regular-text" value="' . esc_attr( $value ) . '" pattern="^[0-9]+(,[0-9]+)*$" title="' . esc_attr__( 'Please enter comma-separated user IDs (numbers only)', 'decker' ) . '">';
		echo '<p class="description">' . esc_html__( 'Enter comma-separated user IDs to ignore from Decker functionality.', 'decker' ) . '</p>';
	}

	/**
	 * Render Clear All Data Button.
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
	public function clear_all_data_button_render() {
		wp_nonce_field( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce', true, true );
		echo '<input type="submit" name="decker_clear_all_data" class="button button-secondary" style="background-color: red; color: white;" value="' . esc_attr__( 'Clear All Data', 'decker' ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all Decker records? This action cannot be undone.', 'decker' ) ) . '\');">';
		echo '<p class="description">' . esc_html__( 'Click the button to delete all Decker labels, tasks, and boards.', 'decker' ) . '</p>';
	}

	/**
	 * Options Page.
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
	 * Admin Notices.
	 *
	 * Displays admin notices.
	 */
	public function admin_notices() {
		if ( isset( $_GET['decker_data_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Decker records have been deleted.', 'decker' ) . '</p></div>';
		}

		$invalid_user_ids = get_transient( 'decker_invalid_user_ids' );
		if ( false !== $invalid_user_ids ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				sprintf(
					// Translators: %s is a list of invalid user IDs that have been removed.
					esc_html__( 'The following user IDs were invalid and have been removed: %s', 'decker' ),
					esc_html( implode( ', ', $invalid_user_ids ) )
				) .
				'</p></div>';
			delete_transient( 'decker_invalid_user_ids' );
		}
	}

	/**
	 * Settings Validation.
	 *
	 * Validates the settings fields.
	 *
	 * @param array $input The input fields to validate.
	 * @return array The validated fields.
	 */
	public function settings_validate( $input ) {

		// Validate shared key.
		$input['shared_key'] = isset( $input['shared_key'] ) ? sanitize_text_field( $input['shared_key'] ) : '';

		// Validate allow email notifications.
		$input['allow_email_notifications'] = isset( $input['allow_email_notifications'] ) && '1' === $input['allow_email_notifications'] ? '1' : '0';

		// Validate collaborative editing.
		$input['collaborative_editing'] = isset( $input['collaborative_editing'] ) && '1' === $input['collaborative_editing'] ? '1' : '0';

		// Validate signaling server.
		if ( isset( $input['signaling_server'] ) && ! empty( $input['signaling_server'] ) ) {
			// Include wss protocol for WebSocket signaling servers.
			$input['signaling_server'] = esc_url_raw( $input['signaling_server'], array( 'wss', 'ws', 'https', 'http' ) );
		} else {
			$input['signaling_server'] = 'wss://signaling.yjs.dev';
		}

		$valid_providers = array( 'openai', 'openrouter', 'gemini' );

		// Validate AI provider.
		$input['ai_provider'] = isset( $input['ai_provider'] ) ? sanitize_key( $input['ai_provider'] ) : 'openai';
		if ( ! in_array( $input['ai_provider'], $valid_providers, true ) ) {
			$input['ai_provider'] = 'openai';
		}

		// Validate AI API key with backward compatibility for legacy field names.
		$input['ai_api_key'] = $this->get_input_setting_value( $input, 'ai_api_key', 'openai_api_key' );

		// Validate AI API URL override.
		$input['openai_api_url'] = isset( $input['openai_api_url'] ) ? esc_url_raw( $input['openai_api_url'], array( 'https' ) ) : '';
		if ( empty( $input['openai_api_url'] ) ) {
			$input['openai_api_url'] = 'https://api.openai.com/v1/chat/completions';
		} else {
			$parsed_url = wp_parse_url( $input['openai_api_url'] );
			if (
				! wp_http_validate_url( $input['openai_api_url'] ) ||
				empty( $parsed_url['scheme'] ) ||
				'https' !== $parsed_url['scheme'] ||
				empty( $parsed_url['host'] ) ||
				! empty( $parsed_url['user'] ) ||
				! empty( $parsed_url['pass'] )
			) {
				$input['openai_api_url'] = 'https://api.openai.com/v1/chat/completions';
			}
		}

		// Validate AI model with backward compatibility for legacy field names.
		$input['ai_model'] = $this->get_input_setting_value( $input, 'ai_model', 'openai_model' );
		if ( empty( $input['ai_model'] ) ) {
			$input['ai_model'] = $this->get_default_ai_model( $input['ai_provider'] );
		}

		// Validate alert color.
		$valid_colors = array( 'success', 'danger', 'warning', 'info' );
		if ( isset( $input['alert_color'] ) && ! in_array( $input['alert_color'], $valid_colors ) ) {
			$input['alert_color'] = 'info'; // Default to info if invalid.
		} else {
			$input['alert_color'] = isset( $input['alert_color'] ) ? $input['alert_color'] : 'info';
		}

		// Validate user profile.
		$roles = wp_roles()->get_names();
		if ( isset( $input['minimum_user_profile'] ) && ! array_key_exists( $input['minimum_user_profile'], $roles ) ) {
			$input['minimum_user_profile'] = 'editor'; // Default to editor if invalid.
		} else {
			$input['minimum_user_profile'] = isset( $input['minimum_user_profile'] ) ? $input['minimum_user_profile'] : 'editor';
		}

		// Validate alert message.
		$input['alert_message'] = isset( $input['alert_message'] ) ? wp_kses_post( $input['alert_message'] ) : '';

		// Initialize ignored_users if not set.
		if ( ! isset( $input['ignored_users'] ) ) {
			$input['ignored_users'] = '';
		}

		// Validate ignored users if not empty.
		if ( ! empty( $input['ignored_users'] ) ) {
			$user_ids = array_map( 'trim', explode( ',', $input['ignored_users'] ) );
			$valid_user_ids = array();
			$invalid_user_ids = array();

			foreach ( $user_ids as $user_id ) {
				if ( is_numeric( $user_id ) ) {
					if ( get_user_by( 'id', $user_id ) ) {
						$valid_user_ids[] = $user_id;
					} else {
						$invalid_user_ids[] = $user_id;
					}
				}
			}

			$input['ignored_users'] = ! empty( $valid_user_ids ) ? implode( ',', $valid_user_ids ) : '';

			// Set transient if there were invalid IDs.
			if ( ! empty( $invalid_user_ids ) ) {
				set_transient( 'decker_invalid_user_ids', $invalid_user_ids, 45 );
			}
		}

		return $input;
	}
}
