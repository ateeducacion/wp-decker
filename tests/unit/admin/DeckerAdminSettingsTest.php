<?php
/**
 * Test Decker_Admin_Settings Class
 *
 * @package Decker\Tests
 */

class DeckerAdminSettingsTest extends WP_UnitTestCase {

	/**
	 * Instance of Decker_Admin_Settings.
	 *
	 * @var Decker_Admin_Settings
	 */
	protected $admin_settings;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		// Instantiate the Decker_Admin_Settings mock class.
		$this->admin_settings = new Decker_Admin_Settings_Test_Mock();

		remove_action( 'admin_init', array( $this->admin_settings, 'handle_clear_all_data' ) );
	}

	/**
	 * Test the settings_validate method with valid input.
	 */
	public function test_settings_validate_with_valid_ignored_users() {
		// Create test users
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();

		$input = array(
			'ignored_users' => "{$user1_id},{$user2_id}",
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( "{$user1_id},{$user2_id}", $validated['ignored_users'] );
	}

	public function test_settings_validate_with_empty_ignored_users() {
		$input = array(
			'ignored_users' => '',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '', $validated['ignored_users'] );
	}

	public function test_settings_validate_with_invalid_ignored_users() {
		// Create one valid user
		$valid_user_id = $this->factory->user->create();

		$input = array(
			'ignored_users' => "999999,{$valid_user_id},invalid",
		);

		$validated = $this->admin_settings->settings_validate( $input );

		// Should only keep the valid user ID
		$this->assertEquals( "{$valid_user_id}", $validated['ignored_users'] );

		// Check if invalid IDs were stored in transient
		$invalid_ids = get_transient( 'decker_invalid_user_ids' );
		$this->assertNotFalse( $invalid_ids );
		$this->assertEquals( array( '999999' ), $invalid_ids );
	}

	public function test_settings_validate_with_valid_input() {
		$input = array(
			'shared_key'           => 'validkey123!',
			'alert_color'          => 'success',
			'minimum_user_profile' => 'editor',
			'alert_message'        => '<strong>Alert!</strong> This is a test message.',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'validkey123!', $validated['shared_key'] );
		$this->assertEquals( 'success', $validated['alert_color'] );
		$this->assertEquals( 'editor', $validated['minimum_user_profile'] );
		$this->assertEquals( '<strong>Alert!</strong> This is a test message.', $validated['alert_message'] );
	}

	/**
	 * Test the settings_validate method with an invalid alert_color.
	 */
	public function test_settings_validate_with_invalid_alert_color() {
		$input = array(
			'shared_key'           => 'validkey123!',
			'alert_color'          => 'invalid_color',
			'minimum_user_profile' => 'editor',
			'alert_message'        => 'Test message.',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		// Should default to 'info'
		$this->assertEquals( 'info', $validated['alert_color'] );
	}

	/**
	 * Test the settings_validate method with an invalid minimum_user_profile.
	 */
	public function test_settings_validate_with_invalid_minimum_user_profile() {
		$input = array(
			'shared_key'           => 'validkey123!',
			'alert_color'          => 'warning',
			'minimum_user_profile' => 'nonexistent_role',
			'alert_message'        => 'Test message.',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		// Should default to 'editor'
		$this->assertEquals( 'editor', $validated['minimum_user_profile'] );
	}

	/**
	 * Test collaborative_editing setting validation with enabled value.
	 */
	public function test_settings_validate_collaborative_editing_enabled() {
		$input = array(
			'collaborative_editing' => '1',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '1', $validated['collaborative_editing'] );
	}

	/**
	 * Test collaborative_editing setting validation with disabled value.
	 */
	public function test_settings_validate_collaborative_editing_disabled() {
		$input = array(
			'collaborative_editing' => '0',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '0', $validated['collaborative_editing'] );
	}

	/**
	 * Test collaborative_editing setting validation with missing value defaults to disabled.
	 */
	public function test_settings_validate_collaborative_editing_missing() {
		$input = array();

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '0', $validated['collaborative_editing'] );
	}

	/**
	 * Test ai_enabled setting validation with enabled value.
	 */
	public function test_settings_validate_ai_enabled_enabled() {
		$input = array(
			'ai_enabled' => '1',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '1', $validated['ai_enabled'] );
	}

	/**
	 * Test ai_enabled setting validation with missing value defaults to disabled.
	 */
	public function test_settings_validate_ai_enabled_missing() {
		$input = array();

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( '0', $validated['ai_enabled'] );
	}

	/**
	 * Test ai_provider defaults to the browser Gemini Nano provider.
	 */
	public function test_settings_validate_ai_provider_defaults_to_browser_gemini_nano() {
		$validated = $this->admin_settings->settings_validate( array() );

		$this->assertEquals(
			Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO,
			$validated['ai_provider']
		);
	}

	/**
	 * Test ai_provider validation falls back for unsupported providers.
	 */
	public function test_settings_validate_ai_provider_rejects_invalid_values() {
		$validated = $this->admin_settings->settings_validate(
			array(
				'ai_provider' => 'openrouter',
			)
		);

		$this->assertEquals(
			Decker_AI_Manager::PROVIDER_BROWSER_GEMINI_NANO,
			$validated['ai_provider']
		);
	}

	/**
	 * Test ai_api_key preserves the stored value when the field is left empty.
	 */
	public function test_settings_validate_ai_api_key_preserves_existing_value() {
		update_option(
			'decker_settings',
			array(
				'ai_api_key' => 'existing-key',
			)
		);

		$validated = $this->admin_settings->settings_validate(
			array(
				'ai_api_key' => '',
			)
		);

		$this->assertEquals( 'existing-key', $validated['ai_api_key'] );
	}

	/**
	 * Test ai_model defaults to the Gemini model constant.
	 */
	public function test_settings_validate_ai_model_defaults_when_empty() {
		$validated = $this->admin_settings->settings_validate(
			array(
				'ai_model' => '',
			)
		);

		$this->assertEquals(
			Decker_AI_Manager::DEFAULT_GEMINI_MODEL,
			$validated['ai_model']
		);
	}

	/**
	 * Test ai_prompt setting validation falls back to the default template.
	 */
	public function test_settings_validate_ai_prompt_defaults_when_empty() {
		$input = array(
			'ai_prompt' => '',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals(
			Decker::get_default_ai_prompt_template(),
			$validated['ai_prompt']
		);
	}

	/**
	 * Test ai_prompt setting validation preserves a custom template.
	 */
	public function test_settings_validate_ai_prompt_custom_value() {
		$input = array(
			'ai_prompt' => "Custom prompt\n{{task_context}}",
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals(
			"Custom prompt\n{{task_context}}",
			$validated['ai_prompt']
		);
	}

	/**
	 * Test signaling_server setting validation with valid URL.
	 */
	public function test_settings_validate_signaling_server_valid_url() {
		$input = array(
			'signaling_server' => 'wss://my-signaling-server.example.com',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'wss://my-signaling-server.example.com', $validated['signaling_server'] );
	}

	/**
	 * Test signaling_server setting validation with empty value defaults to public server.
	 */
	public function test_settings_validate_signaling_server_empty_defaults() {
		$input = array(
			'signaling_server' => '',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'wss://signaling.yjs.dev', $validated['signaling_server'] );
	}

	/**
	 * Test signaling_server setting validation with missing value defaults to public server.
	 */
	public function test_settings_validate_signaling_server_missing_defaults() {
		$input = array();

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'wss://signaling.yjs.dev', $validated['signaling_server'] );
	}

	/**
	 * Test legacy OpenAI fields are removed while Gemini settings are preserved.
	 */
	public function test_settings_validate_removes_legacy_openai_fields() {
		$input = array(
			'ai_provider'      => 'gemini_api',
			'ai_api_key'      => 'secret-key',
			'ai_model'        => 'gemini-2.5-flash',
			'openai_api_url'  => 'https://example.com/v1/chat/completions',
			'openai_api_key'  => 'legacy-key',
			'openai_model'    => 'legacy-model',
			'signaling_server' => 'wss://signaling.yjs.dev',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'gemini_api', $validated['ai_provider'] );
		$this->assertEquals( 'secret-key', $validated['ai_api_key'] );
		$this->assertEquals( 'gemini-2.5-flash', $validated['ai_model'] );
		$this->assertArrayNotHasKey( 'openai_api_url', $validated );
		$this->assertArrayNotHasKey( 'openai_api_key', $validated );
		$this->assertArrayNotHasKey( 'openai_model', $validated );
	}

	/**
	 * Test collaborative_editing_render outputs correct HTML when disabled.
	 */
	public function test_collaborative_editing_render_disabled() {
		update_option( 'decker_settings', array( 'collaborative_editing' => '0' ) );

		ob_start();
		$this->admin_settings->collaborative_editing_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[collaborative_editing]"', $output );
		$this->assertStringContainsString( 'value="1"', $output );
		$this->assertStringNotContainsString( 'checked', $output );
	}

	/**
	 * Test collaborative_editing_render outputs correct HTML when enabled.
	 */
	public function test_collaborative_editing_render_enabled() {
		update_option( 'decker_settings', array( 'collaborative_editing' => '1' ) );

		ob_start();
		$this->admin_settings->collaborative_editing_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[collaborative_editing]"', $output );
		$this->assertStringContainsString( "checked='checked'", $output );
	}

	/**
	 * Test ai_enabled_render outputs unchecked HTML by default.
	 */
	public function test_ai_enabled_render_disabled_by_default() {
		update_option( 'decker_settings', array() );

		ob_start();
		$this->admin_settings->ai_enabled_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[ai_enabled]"', $output );
		$this->assertStringNotContainsString( "checked='checked'", $output );
	}

	/**
	 * Test ai_prompt_render outputs the default template.
	 */
	public function test_ai_prompt_render_uses_default_template() {
		update_option( 'decker_settings', array() );

		ob_start();
		$this->admin_settings->ai_prompt_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[ai_prompt]"', $output );
		$this->assertStringContainsString(
			'{{mode_instruction}}',
			$output
		);
		$this->assertStringContainsString(
			'nano-class models',
			$output
		);
	}

	/**
	 * Test ai_api_key_render does not expose the saved API key.
	 */
	public function test_ai_api_key_render_masks_saved_value() {
		update_option(
			'decker_settings',
			array(
				'ai_api_key' => 'super-secret-key',
			)
		);

		ob_start();
		$this->admin_settings->ai_api_key_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[ai_api_key]"', $output );
		$this->assertStringContainsString( 'type="password"', $output );
		$this->assertStringNotContainsString( 'super-secret-key', $output );
		$this->assertStringContainsString(
			'Leave this field empty to keep the current key.',
			$output
		);
	}

	/**
	 * Test ai_provider_render outputs the provider radio buttons.
	 */
	public function test_ai_provider_render_outputs_supported_providers() {
		update_option(
			'decker_settings',
			array(
				'ai_provider' => Decker_AI_Manager::PROVIDER_GEMINI_API,
			)
		);

		ob_start();
		$this->admin_settings->ai_provider_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'browser_gemini_nano', $output );
		$this->assertStringContainsString( 'gemini_api', $output );
		$this->assertStringContainsString( "checked='checked'", $output );
	}

	/**
	 * Test signaling_server_render outputs correct HTML with default value.
	 */
	public function test_signaling_server_render_default() {
		update_option( 'decker_settings', array() );

		ob_start();
		$this->admin_settings->signaling_server_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="decker_settings[signaling_server]"', $output );
		$this->assertStringContainsString( 'wss://signaling.yjs.dev', $output );
		$this->assertStringContainsString( 'placeholder="wss://signaling.yjs.dev"', $output );
	}

	/**
	 * Test signaling_server_render outputs correct HTML with custom value.
	 */
	public function test_signaling_server_render_custom_value() {
		update_option( 'decker_settings', array( 'signaling_server' => 'wss://custom-server.example.com' ) );

		ob_start();
		$this->admin_settings->signaling_server_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="wss://custom-server.example.com"', $output );
	}

	/**
	 * Test AI settings fields are registered in the dedicated AI section.
	 */
	public function test_settings_init_registers_ai_fields_in_ai_section() {
		global $wp_settings_fields;

		update_option( 'decker_settings', array() );
		$wp_settings_fields = array();

		$this->admin_settings->settings_init();

		$this->assertArrayHasKey( 'ai_enabled', $wp_settings_fields['decker']['decker_ai_section'] );
		$this->assertArrayHasKey( 'ai_provider', $wp_settings_fields['decker']['decker_ai_section'] );
		$this->assertArrayHasKey( 'ai_api_key', $wp_settings_fields['decker']['decker_ai_section'] );
		$this->assertArrayHasKey( 'ai_model', $wp_settings_fields['decker']['decker_ai_section'] );
		$this->assertArrayHasKey( 'ai_prompt', $wp_settings_fields['decker']['decker_ai_section'] );
	}

	/**
	 * Test the shared_key_render method when shared_key is empty.
	 */
	public function test_shared_key_render_with_empty_shared_key() {
		// Mock get_option to return empty shared_key.
		update_option( 'decker_settings', array() );

		// Start output buffering.
		ob_start();
		$this->admin_settings->shared_key_render();
		$output = ob_get_clean();

		// Retrieve the updated option.
		$options = get_option( 'decker_settings' );
		$this->assertNotEmpty( $options['shared_key'], 'Shared key should be generated and saved.' );

		// Assert that the output contains the input field with the generated shared_key.
		$this->assertStringContainsString( $options['shared_key'], $output );
		$this->assertStringContainsString( 'name="decker_settings[shared_key]"', $output );
	}

	/**
	 * Test the handle_clear_all_data method correctly deletes data.
	 */
	public function test_handle_clear_all_data() {

		$administrator = $this->factory->user->create_and_get( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator->ID );

		// Create terms for boards and labels.
		$board_id = $this->factory->board->create();
		$label_id = $this->factory->label->create();
		$post_id  = $this->factory->task->create();

		// Ensure data exists.
		$this->assertNotFalse( get_post( $post_id ), 'Post should exist before deletion.' );
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		$this->assertNotEmpty( $terms, 'Terms should exist before deletion.' );

		// Simulate form submission.
		$_POST['decker_clear_all_data']       = '1';
		$_POST['decker_clear_all_data_nonce'] = wp_create_nonce( 'decker_clear_all_data_action' );

		// Ensur that $_REQUEST has the same values.
		$_REQUEST['decker_clear_all_data']       = $_POST['decker_clear_all_data'];
		$_REQUEST['decker_clear_all_data_nonce'] = $_POST['decker_clear_all_data_nonce'];

		// Set the request method.
		$_SERVER['REQUEST_METHOD'] = 'POST';

		// Execute the method.
		$this->admin_settings->handle_clear_all_data();

		// Verify that the redirection was performed correctly.
		$expected_url = add_query_arg(
			array(
				'page'                => 'decker_settings',
				'decker_data_cleared' => 'true',
			),
			admin_url( 'options-general.php' )
		);
		$this->assertEquals( $expected_url, $this->admin_settings->redirect_url );

		// Verify that the post has been deleted.
		$this->assertNull( get_post( $post_id ), 'Post should be deleted.' );

		// Assert that the term is deleted.
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		$this->assertEmpty( $terms, 'Terms should be deleted.' );
	}


	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		// Reset any global variables or options if necessary.
		delete_option( 'decker_settings' );
	}
}

/**
 * Mock Class for Decker_Admin_Settings to Override Redirect Behavior.
 */
class Decker_Admin_Settings_Test_Mock extends Decker_Admin_Settings {
	/**
	 * Redirect URL captured during tests.
	 *
	 * @var string|null
	 */
	public $redirect_url = null;

	/**
	 * Override the redirect_and_exit method to prevent exiting during tests.
	 *
	 * @param string $url URL to redirect to.
	 */
	protected function redirect_and_exit( $url ) {
		$this->redirect_url = $url;
		// Do not call exit to allow the test to continue.
	}
}
