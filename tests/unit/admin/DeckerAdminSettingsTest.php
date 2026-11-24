<?php
/**
 * Test Decker_Admin_Settings Class
 *
 * @package Decker\Tests
 */

class DeckerAdminSettingsTest extends Decker_Test_Base {

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
	 * Retired settings are discarded without changing supported settings.
	 */
	public function test_retired_settings_are_not_saved() {
		$input = array(
			'collaborative_editing' => '1',
			'signaling_server' => 'wss://obsolete.example.test',
			'sidebar_board_status' => '1',
			'ai_enabled' => '1',
			'ai_provider' => 'gemini_api',
			'ai_api_key' => 'retired-api-key',
			'ai_model' => 'retired-model',
			'ai_prompt' => 'Retired prompt',
			'alert_message' => 'Keep this setting',
		);
		update_option( 'decker_settings', $input );
		$validated = $this->admin_settings->settings_validate( $input );
		foreach ( array( 'collaborative_editing', 'signaling_server', 'sidebar_board_status', 'ai_enabled', 'ai_provider', 'ai_api_key', 'ai_model', 'ai_prompt' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $validated );
		}
		$this->assertSame( 'Keep this setting', $validated['alert_message'] );
		update_option( 'decker_settings', $validated );
		$this->assertArrayNotHasKey( 'ai_api_key', get_option( 'decker_settings' ) );
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
	 * Legacy OpenAI configuration is also discarded when settings are saved.
	 */
	public function test_settings_validate_removes_legacy_openai_fields() {
		$input = array(
			'ai_provider'      => 'gemini_api',
			'ai_api_key'      => 'secret-key',
			'ai_model'        => 'gemini-2.5-flash',
			'openai_api_url'  => 'https://example.com/v1/chat/completions',
			'openai_api_key'  => 'legacy-key',
			'openai_model'    => 'legacy-model',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		foreach ( array( 'ai_provider', 'ai_api_key', 'ai_model' ) as $field ) {
			$this->assertArrayNotHasKey( $field, $validated );
		}
		$this->assertArrayNotHasKey( 'openai_api_url', $validated );
		$this->assertArrayNotHasKey( 'openai_api_key', $validated );
		$this->assertArrayNotHasKey( 'openai_model', $validated );
	}

	/**
	 * Test the shared_key_render method when shared_key is empty.
	 */
	public function test_shared_key_render_with_empty_shared_key() {
		// Mock get_option to return empty shared_key.
		update_option( 'decker_settings', array() );

		// Start output buffering.
		ob_start();
		( new Decker_Admin_Settings_Fields() )->render( array( 'field' => 'shared_key' ) );
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
	 * Lock-in: non-array input is coerced to an empty array and every field
	 * falls back to its documented default.
	 */
	public function test_settings_validate_non_array_input_returns_full_defaults() {
		foreach ( array( 'not-an-array', null ) as $bad_input ) {
			$validated = $this->admin_settings->settings_validate( $bad_input );

			$this->assertEquals( '', $validated['shared_key'] );
			$this->assertEquals( '0', $validated['allow_email_notifications'] );
			$this->assertEquals( 'info', $validated['alert_color'] );
			$this->assertEquals( 'editor', $validated['minimum_user_profile'] );
			$this->assertArrayNotHasKey( 'task_editor_type', $validated );
			$this->assertEquals( '', $validated['alert_message'] );
			$this->assertEquals( '', $validated['ignored_users'] );
		}
	}

	/**
	 * Missing editor settings stay retired.
	 */
	public function test_settings_validate_task_editor_type_removes_missing_editor_option() {
		$validated = $this->admin_settings->settings_validate( array() );

		$this->assertArrayNotHasKey( 'task_editor_type', $validated );
	}

	/**
	 * An old classic selection is discarded.
	 */
	public function test_settings_validate_task_editor_type_removes_classic_option() {
		$validated = $this->admin_settings->settings_validate( array( 'task_editor_type' => 'classic' ) );

		$this->assertArrayNotHasKey( 'task_editor_type', $validated );
	}

	/**
	 * Invalid old editor settings are discarded.
	 */
	public function test_settings_validate_task_editor_type_removes_invalid_editor_option() {
		$validated = $this->admin_settings->settings_validate( array( 'task_editor_type' => 'markdown' ) );

		$this->assertArrayNotHasKey( 'task_editor_type', $validated );
	}

	/**
	 * Lock-in: the persisted insertion order of the validated keys must not change.
	 */
	public function test_settings_validate_output_key_order_locked() {
		$validated = $this->admin_settings->settings_validate( array() );

		$this->assertEquals(
			array(
				'shared_key',
				'allow_email_notifications',
				'subtasks_enabled',
				'alert_color',
				'minimum_user_profile',
				'alert_message',
				'ignored_users',
			),
			array_keys( $validated )
		);
	}

	/**
	 * Lock-in: unknown keys pass through untouched (retired feature keys are removed).
	 */
	public function test_settings_validate_preserves_unknown_passthrough_keys() {
		$input = array(
			'future_setting' => 'keep-me',
			'shared_key'     => 'k',
		);

		$validated = $this->admin_settings->settings_validate( $input );

		$this->assertEquals( 'keep-me', $validated['future_setting'] );
	}

	/**
	 * Lock-in: allow_email_notifications uses a strict '1' === comparison.
	 */
	public function test_settings_validate_allow_email_notifications_strict_one() {
		$this->assertEquals(
			'1',
			$this->admin_settings->settings_validate( array( 'allow_email_notifications' => '1' ) )['allow_email_notifications']
		);
		$this->assertEquals(
			'0',
			$this->admin_settings->settings_validate( array( 'allow_email_notifications' => 'yes' ) )['allow_email_notifications']
		);
		$this->assertEquals(
			'0',
			$this->admin_settings->settings_validate( array() )['allow_email_notifications']
		);
	}

	/**
	 * Lock-in: missing alert_color / minimum_user_profile keys take their defaults.
	 */
	public function test_settings_validate_alert_color_and_profile_missing_keys_default() {
		$validated = $this->admin_settings->settings_validate( array() );

		$this->assertEquals( 'info', $validated['alert_color'] );
		$this->assertEquals( 'editor', $validated['minimum_user_profile'] );
	}

	/**
	 * Lock-in: alert_message is filtered through wp_kses_post.
	 */
	public function test_settings_validate_alert_message_runs_wp_kses_post() {
		$validated = $this->admin_settings->settings_validate(
			array( 'alert_message' => '<script>alert(1)</script><strong>ok</strong>' )
		);

		$this->assertEquals( 'alert(1)<strong>ok</strong>', $validated['alert_message'] );
		$this->assertEquals( '', $this->admin_settings->settings_validate( array() )['alert_message'] );
	}

	/**
	 * Lock-in: a set-but-empty '0' ignored_users passes through and sets no transient.
	 */
	public function test_settings_validate_ignored_users_zero_string_passes_through() {
		$validated = $this->admin_settings->settings_validate( array( 'ignored_users' => '0' ) );

		$this->assertSame( '0', $validated['ignored_users'] );
		$this->assertFalse( get_transient( 'decker_invalid_user_ids' ) );
	}

	/**
	 * Lock-in: all-invalid ignored_users empties the value and stores the transient.
	 */
	public function test_settings_validate_ignored_users_all_invalid_sets_transient_and_empties() {
		$validated = $this->admin_settings->settings_validate( array( 'ignored_users' => '999999,888888' ) );

		$this->assertSame( '', $validated['ignored_users'] );
		$this->assertEquals( array( '999999', '888888' ), get_transient( 'decker_invalid_user_ids' ) );
	}

	/**
	 * Lock-in: valid ignored_users IDs do not set the invalid-IDs transient.
	 */
	public function test_settings_validate_ignored_users_valid_ids_do_not_set_transient() {
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();

		$this->admin_settings->settings_validate( array( 'ignored_users' => "{$user1_id},{$user2_id}" ) );

		$this->assertFalse( get_transient( 'decker_invalid_user_ids' ) );
	}

	/**
	 * Lock-in: ignored_users entries are trimmed before validation.
	 */
	public function test_settings_validate_ignored_users_trims_entries() {
		$user1_id = $this->factory->user->create();
		$user2_id = $this->factory->user->create();

		$validated = $this->admin_settings->settings_validate(
			array( 'ignored_users' => " {$user1_id} , {$user2_id} " )
		);

		$this->assertEquals( "{$user1_id},{$user2_id}", $validated['ignored_users'] );
	}

	/**
	 * Lock-in: the sanitize callback never writes the decker_settings option itself.
	 */
	public function test_settings_validate_has_no_option_write_side_effect() {
		$input = array(
			'shared_key'           => 'validkey123!',
			'allow_email_notifications' => '1',
			'ai_enabled'           => '1',
			'ai_provider'          => 'gemini_api',
			'ai_api_key'           => 'a-key',
			'ai_model'             => 'gemini-2.5-flash',
			'ai_prompt'            => 'Prompt',
			'alert_color'          => 'success',
			'minimum_user_profile' => 'editor',
			'alert_message'        => 'Hi',
			'ignored_users'        => '',
		);

		$this->admin_settings->settings_validate( $input );

		$this->assertFalse( get_option( 'decker_settings' ) );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		parent::tear_down();
		// Reset any global variables or options if necessary.
		delete_option( 'decker_settings' );
		delete_transient( 'decker_invalid_user_ids' );
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
