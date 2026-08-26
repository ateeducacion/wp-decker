<?php
/**
 * Validates the Decker settings before they are stored.
 *
 * Owns one private method per field plus the shared checkbox helper; the
 * public validate() applies them all in the order the settings screen lists
 * the fields.
 *
 * @package    Decker
 * @subpackage Decker/admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Admin_Settings_Validator
 */
class Decker_Admin_Settings_Validator {

	/**
	 * Validate a settings submission.
	 *
	 * @param mixed $input          The raw submission.
	 * @param array $current_values The currently stored settings option.
	 * @return array The validated fields.
	 */
	public function validate( $input, array $current_values ) {
		$input = is_array( $input ) ? $input : array();

		$input = $this->strip_legacy_openai_fields( $input );

		// Validate shared key.
		$input['shared_key'] = $this->validate_shared_key( $input );

		// Validate allow email notifications.
		$input['allow_email_notifications'] = $this->sanitize_checkbox( $input, 'allow_email_notifications' );

		// Validate collaborative editing.
		$input['collaborative_editing'] = $this->sanitize_checkbox( $input, 'collaborative_editing' );

		// Validate the sidebar board status indicators.
		$input['sidebar_board_status'] = $this->sanitize_checkbox( $input, 'sidebar_board_status' );

		// Validate AI settings.
		$input['ai_enabled']  = $this->sanitize_checkbox( $input, 'ai_enabled' );
		$input['ai_provider'] = Decker_AI_Manager::get_selected_provider( $input );
		$input['ai_api_key']  = $this->validate_ai_api_key( $input, $current_values );
		$input['ai_model']    = $this->validate_ai_model( $input );
		$input['ai_prompt']   = $this->validate_ai_prompt( $input );

		// Validate signaling server.
		$input['signaling_server'] = $this->validate_signaling_server( $input );

		// Validate alert color.
		$input['alert_color'] = $this->validate_alert_color( $input );

		// Validate user profile.
		$input['minimum_user_profile'] = $this->validate_minimum_user_profile( $input );

		// Validate task editor type. Quill stays the default during the transition period.
		$valid_editors = array( 'classic', 'quill' );
		if ( ! isset( $input['task_editor_type'] ) || ! in_array( $input['task_editor_type'], $valid_editors, true ) ) {
			$input['task_editor_type'] = 'quill';
		}

		// Validate alert message.
		$input['alert_message'] = $this->validate_alert_message( $input );

		// Validate ignored users.
		$input['ignored_users'] = $this->validate_ignored_users( $input );

		return $input;
	}

	/**
	 * Strip Legacy OpenAI Fields.
	 *
	 * Removes the deprecated openai_* keys that are no longer stored.
	 *
	 * @param array $input The input fields being validated.
	 * @return array The input array without the legacy OpenAI keys.
	 */
	private function strip_legacy_openai_fields( array $input ) {
		unset(
			$input['openai_api_url'],
			$input['openai_api_key'],
			$input['openai_model']
		);

		return $input;
	}

	/**
	 * Sanitize Checkbox.
	 *
	 * Returns '1' when the given key is present and strictly equals '1', else '0'.
	 *
	 * @param array  $input The input fields being validated.
	 * @param string $key   The checkbox key to read.
	 * @return string Either '1' or '0'.
	 */
	private function sanitize_checkbox( array $input, $key ) {
		return isset( $input[ $key ] ) && '1' === $input[ $key ] ? '1' : '0';
	}

	/**
	 * Validate Shared Key.
	 *
	 * @param array $input The input fields being validated.
	 * @return string The sanitized shared key, or '' when absent.
	 */
	private function validate_shared_key( array $input ) {
		return isset( $input['shared_key'] ) ? sanitize_text_field( $input['shared_key'] ) : '';
	}

	/**
	 * Validate AI API Key.
	 *
	 * Sanitizes a newly provided key, otherwise keeps the previously stored one.
	 *
	 * @param array $input          The input fields being validated.
	 * @param array $current_values The currently stored settings option.
	 * @return string The validated API key.
	 */
	private function validate_ai_api_key( array $input, array $current_values ) {
		return isset( $input['ai_api_key'] ) && '' !== trim( $input['ai_api_key'] )
			? Decker_AI_Manager::sanitize_api_key( $input['ai_api_key'] )
			: Decker_AI_Manager::get_api_key( $current_values );
	}

	/**
	 * Validate AI Model.
	 *
	 * @param array $input The input fields being validated.
	 * @return string The sanitized model name, or the default Gemini model.
	 */
	private function validate_ai_model( array $input ) {
		return isset( $input['ai_model'] ) && '' !== trim( $input['ai_model'] )
			? sanitize_text_field( $input['ai_model'] )
			: Decker_AI_Manager::DEFAULT_GEMINI_MODEL;
	}

	/**
	 * Validate AI Prompt.
	 *
	 * @param array $input The input fields being validated.
	 * @return string The sanitized prompt, or the default prompt template.
	 */
	private function validate_ai_prompt( array $input ) {
		return isset( $input['ai_prompt'] ) && '' !== trim( $input['ai_prompt'] )
			? sanitize_textarea_field( $input['ai_prompt'] )
			: Decker::get_default_ai_prompt_template();
	}

	/**
	 * Validate Signaling Server.
	 *
	 * @param array $input The input fields being validated.
	 * @return string The sanitized server URL, or the default public server.
	 */
	private function validate_signaling_server( array $input ) {
		if ( isset( $input['signaling_server'] ) && ! empty( $input['signaling_server'] ) ) {
			// Include wss protocol for WebSocket signaling servers.
			return esc_url_raw( $input['signaling_server'], array( 'wss', 'ws', 'https', 'http' ) );
		}

		return 'wss://signaling.yjs.dev';
	}

	/**
	 * Validate Alert Color.
	 *
	 * @param array $input The input fields being validated.
	 * @return string A valid alert color, defaulting to 'info'.
	 */
	private function validate_alert_color( array $input ) {
		$valid_colors = array( 'success', 'danger', 'warning', 'info' );
		if ( isset( $input['alert_color'] ) && ! in_array( $input['alert_color'], $valid_colors ) ) {
			return 'info'; // Default to info if invalid.
		}

		return isset( $input['alert_color'] ) ? $input['alert_color'] : 'info';
	}

	/**
	 * Validate Minimum User Profile.
	 *
	 * @param array $input The input fields being validated.
	 * @return string A valid role slug, defaulting to 'editor'.
	 */
	private function validate_minimum_user_profile( array $input ) {
		$roles = wp_roles()->get_names();
		if ( isset( $input['minimum_user_profile'] ) && ! array_key_exists( $input['minimum_user_profile'], $roles ) ) {
			return 'editor'; // Default to editor if invalid.
		}

		return isset( $input['minimum_user_profile'] ) ? $input['minimum_user_profile'] : 'editor';
	}

	/**
	 * Validate Alert Message.
	 *
	 * @param array $input The input fields being validated.
	 * @return string The filtered alert message, or '' when absent.
	 */
	private function validate_alert_message( array $input ) {
		return isset( $input['alert_message'] ) ? wp_kses_post( $input['alert_message'] ) : '';
	}

	/**
	 * Validate Ignored Users.
	 *
	 * Keeps numeric user IDs that resolve to an existing user, dropping the rest,
	 * and stores a transient when numeric-but-nonexistent IDs are present.
	 *
	 * @param array $input The input fields being validated.
	 * @return string A comma-separated list of valid user IDs, or '' when absent/none.
	 */
	private function validate_ignored_users( array $input ) {
		// Initialize ignored_users if not set.
		if ( ! isset( $input['ignored_users'] ) ) {
			return '';
		}

		// Validate ignored users if not empty.
		if ( empty( $input['ignored_users'] ) ) {
			return $input['ignored_users'];
		}

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

		// Set transient if there were invalid IDs.
		if ( ! empty( $invalid_user_ids ) ) {
			set_transient( 'decker_invalid_user_ids', $invalid_user_ids, 45 );
		}

		return ! empty( $valid_user_ids ) ? implode( ',', $valid_user_ids ) : '';
	}
}
