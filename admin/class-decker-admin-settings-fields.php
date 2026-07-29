<?php
/**
 * Renders the fields of the Decker settings screen.
 *
 * Each field of the screen is drawn by one private method here. WordPress
 * reaches them through the single public render() dispatcher, which
 * add_settings_field() hands the field id as an argument, so adding a field
 * does not add to this class's public surface.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Admin_Settings_Fields
 */
class Decker_Admin_Settings_Fields {

	/**
	 * Render one settings field.
	 *
	 * Registered as the callback of every add_settings_field() call, which
	 * passes the field id in $args.
	 *
	 * @param array $args Field arguments; 'field' holds the field id.
	 */
	public function render( $args ) {
		$method = ( isset( $args['field'] ) ? $args['field'] : '' ) . '_render';

		if ( method_exists( $this, $method ) ) {
			$this->$method();
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
	 * AI Settings Section Callback.
	 *
	 * Outputs a description for the AI settings section.
	 *
	 * @return void
	 */
	public function ai_settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure how Decker improves task descriptions with AI. Browser-based Gemini Nano keeps the text in the browser, while the Gemini API sends the prompt to Google through your WordPress server using the saved API key.', 'decker' ) . '</p>';
	}

	/**
	 * Render Shared Key Field
	 *
	 * Outputs the HTML for the shared_key field, generating it only if it does not exist or does not meet the criteria.
	 */
	private function shared_key_render() {
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
	private function allow_email_notifications_render() {
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
	private function collaborative_editing_render() {
		$options = get_option( 'decker_settings', array() );
		$checked = isset( $options['collaborative_editing'] ) && '1' === $options['collaborative_editing'];

		echo '<label>';
		echo '<input type="checkbox" name="decker_settings[collaborative_editing]" value="1" ' . checked( $checked, true, false ) . '>';
		echo esc_html__( 'Enable real-time collaborative editing for tasks.', 'decker' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, multiple users can edit the same task simultaneously with real-time synchronization using WebRTC.', 'decker' ) . '</p>';
	}

	/**
	 * Render Board Status Indicators Field.
	 *
	 * Outputs a browser-local preference for the board status indicators.
	 */
	private function sidebar_board_status_render() {
		echo '<label for="sidebar-board-status-check">';
		echo '<input type="checkbox" id="sidebar-board-status-check" value="1" checked data-decker-persistent aria-describedby="sidebar-board-status-description"> ';
		echo esc_html__( 'Show board status indicators', 'decker' );
		echo '</label>';
		echo '<p class="description" id="sidebar-board-status-description">' . esc_html__( 'This preference is saved only in this browser.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI Enabled Field.
	 *
	 * Outputs the HTML for the ai_enabled field.
	 *
	 * @return void
	 */
	private function ai_enabled_render() {
		$options = get_option( 'decker_settings', array() );
		$checked = isset( $options['ai_enabled'] ) && '1' === $options['ai_enabled'];

		echo '<label>';
		echo '<input type="checkbox" name="decker_settings[ai_enabled]" value="1" ' . checked( $checked, true, false ) . '>';
		echo esc_html__( 'Enable AI improvements for task descriptions.', 'decker' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, users can improve task descriptions with either Gemini Nano in supported browsers or the Gemini API through the server.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI Provider Field.
	 *
	 * Outputs the HTML for the ai_provider field.
	 *
	 * @return void
	 */
	private function ai_provider_render() {
		$options  = get_option( 'decker_settings', array() );
		$provider = Decker_AI_Manager::get_selected_provider( $options );
		$choices  = array(
			Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO => __(
				'Gemini Nano (browser-based)',
				'decker'
			),
			Decker_AI_Manager::PROVIDER_GEMINI_API          => __(
				'Gemini API (server-side)',
				'decker'
			),
		);

		foreach ( $choices as $value => $label ) {
			echo '<p><label>';
			echo '<input type="radio" name="decker_settings[ai_provider]" value="' . esc_attr( $value ) . '" ' . checked( $provider, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label></p>';
		}

		echo '<p class="description">' . esc_html__( 'Choose whether AI improvements run in the browser with Gemini Nano or through the Gemini API on the server.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI API Key Field.
	 *
	 * Outputs the HTML for the ai_api_key field.
	 *
	 * @return void
	 */
	private function ai_api_key_render() {
		$options        = get_option( 'decker_settings', array() );
		$has_saved_key  = ! empty( $options['ai_api_key'] );
		$placeholder    = $has_saved_key ? '••••••••••••••••' : '';
		$description    = $has_saved_key
			? __( 'A Gemini API key is already stored. Leave this field empty to keep the current key.', 'decker' )
			: __( 'Paste a Gemini API key to enable server-side Gemini requests. The saved key is never shown again after saving.', 'decker' );

		echo '<input type="password" name="decker_settings[ai_api_key]" class="regular-text" value="" autocomplete="off" placeholder="' . esc_attr( $placeholder ) . '">';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/**
	 * Render AI Model Field.
	 *
	 * Outputs the HTML for the ai_model field.
	 *
	 * @return void
	 */
	private function ai_model_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = Decker_AI_Manager::get_model( $options );

		echo '<input type="text" name="decker_settings[ai_model]" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( Decker_AI_Manager::DEFAULT_GEMINI_MODEL ) . '">';
		echo '<p class="description">' . esc_html__( 'Optional Gemini model name for server-side requests. Leave the default unless you need a different compatible text-generation model.', 'decker' ) . '</p>';
	}

	/**
	 * Render AI Prompt Field.
	 *
	 * Outputs the HTML for the ai_prompt field.
	 *
	 * @return void
	 */
	private function ai_prompt_render() {
		$options = get_option( 'decker_settings', array() );
		$value   = isset( $options['ai_prompt'] ) && '' !== $options['ai_prompt']
			? sanitize_textarea_field( $options['ai_prompt'] )
			: Decker::get_default_ai_prompt_template();

		echo '<textarea name="decker_settings[ai_prompt]" class="large-text code" rows="12">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Customize the base prompt used for AI improvements. For smaller nano-class models, it usually works better to write this base prompt in English and let the model translate the final result into the WordPress language. Available placeholders: {{mode_instruction}}, {{task_context}}, {{content_html}}, {{language_instruction}}, {{response_format}}.', 'decker' ) . '</p>';
	}

	/**
	 * Render Signaling Server Field.
	 *
	 * Outputs the HTML for the signaling_server field.
	 */
	private function signaling_server_render() {
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
	 * Render User Profile Field.
	 *
	 * Outputs the HTML for the minimum_user_profile field, displaying only roles with edit permissions.
	 */
	private function minimum_user_profile_render() {
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
	private function alert_message_render() {
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
	private function alert_color_render() {
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
	 * Render Ignored Users Field.
	 *
	 * Outputs the HTML for the ignored_users field.
	 */
	private function ignored_users_render() {
		$options = get_option( 'decker_settings', array() );
		$value = isset( $options['ignored_users'] ) ? sanitize_text_field( $options['ignored_users'] ) : '';
		echo '<input type="text" name="decker_settings[ignored_users]" class="regular-text" value="' . esc_attr( $value ) . '" pattern="^[0-9]+(,[0-9]+)*$" title="' . esc_attr__( 'Please enter comma-separated user IDs (numbers only)', 'decker' ) . '">';
		echo '<p class="description">' . esc_html__( 'Enter comma-separated user IDs to ignore from Decker functionality.', 'decker' ) . '</p>';
	}

	/**
	 * Render Task Editor Type Field.
	 *
	 * Outputs the HTML for the task_editor_type field.
	 */
	private function task_editor_type_render() {
		$options     = get_option( 'decker_settings', array() );
		$editor_type = isset( $options['task_editor_type'] ) ? $options['task_editor_type'] : 'quill';
		$editors     = array(
			'classic' => __( 'Classic Editor', 'decker' ),
			'quill'   => __( 'Quill Editor', 'decker' ),
		);

		foreach ( $editors as $value => $label ) {
			echo '<label style="margin-right: 15px;">';
			echo '<input type="radio" name="decker_settings[task_editor_type]" value="' . esc_attr( $value ) . '" ' . checked( $editor_type, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}

		echo '<p class="description">' . esc_html__( 'Choose which editor to use for task descriptions. Collaborative editing always uses Quill.', 'decker' ) . '</p>';
	}

	/**
	 * Render Clear All Data Button.
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
	private function clear_all_data_button_render() {
		wp_nonce_field( 'decker_clear_all_data_action', 'decker_clear_all_data_nonce', true, true );
		echo '<input type="submit" name="decker_clear_all_data" class="button button-secondary" style="background-color: red; color: white;" value="' . esc_attr__( 'Clear All Data', 'decker' ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all Decker records? This action cannot be undone.', 'decker' ) ) . '\');">';
		echo '<p class="description">' . esc_html__( 'Click the button to delete all Decker labels, tasks, and boards.', 'decker' ) . '</p>';
	}
}
