<?php
/**
 * Characterization tests for the rendered Decker settings screen.
 *
 * These drive the WordPress Settings API — registration on admin_init, then
 * do_settings_sections() — rather than any render method directly, so the
 * class that owns each field can change without the tests noticing. What is
 * pinned is what an administrator actually sees: every field input, in its
 * section, with its stored value reflected.
 *
 * @package Decker
 */

class DeckerAdminSettingsScreenTest extends Decker_Test_Base {

	/**
	 * Rendered screen HTML, built once per test.
	 *
	 * @var string
	 */
	private $html;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// A known option state so value reflection can be asserted.
		update_option(
			'decker_settings',
			array(
				'shared_key'           => 'fixed-shared-key-1234',
				'alert_message'        => 'Maintenance tonight',
				'alert_color'          => 'warning',
				'minimum_user_profile' => 'editor',
				'task_editor_type'     => 'classic',
				'ignored_users'        => '7,9',
				'signaling_server'     => 'wss://collab.example.test',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		global $wp_settings_sections, $wp_settings_fields, $wp_registered_settings;
		$wp_settings_sections   = array();
		$wp_settings_fields     = array();
		$wp_registered_settings = array();

		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Register the settings as admin_init would, then render the screen.
	 *
	 * @return string The rendered sections and fields.
	 */
	private function render_screen() {
		if ( null !== $this->html ) {
			return $this->html;
		}

		( new Decker_Admin_Settings() )->settings_init();

		ob_start();
		do_settings_sections( 'decker' );
		$this->html = ob_get_clean();

		return $this->html;
	}

	/**
	 * Every persisted field is rendered with its decker_settings[...] input name.
	 *
	 * @dataProvider field_name_provider
	 *
	 * @param string $field Option key the input must carry.
	 */
	public function test_every_field_input_is_rendered( $field ) {
		$this->assertStringContainsString(
			'name="decker_settings[' . $field . ']"',
			$this->render_screen(),
			"The settings screen lost the '{$field}' input."
		);
	}

	/**
	 * The option keys the screen must offer inputs for.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function field_name_provider() {
		return array(
			'shared key'          => array( 'shared_key' ),
			'email notifications' => array( 'allow_email_notifications' ),
			'collaboration'       => array( 'collaborative_editing' ),
			'signaling server'    => array( 'signaling_server' ),
			'minimum profile'     => array( 'minimum_user_profile' ),
			'alert message'       => array( 'alert_message' ),
			'alert color'         => array( 'alert_color' ),
			'ignored users'       => array( 'ignored_users' ),
			'task editor'         => array( 'task_editor_type' ),
			'ai enabled'          => array( 'ai_enabled' ),
			'ai provider'         => array( 'ai_provider' ),
			'ai api key'          => array( 'ai_api_key' ),
			'ai model'            => array( 'ai_model' ),
			'ai prompt'           => array( 'ai_prompt' ),
		);
	}

	/**
	 * Both section intros are rendered.
	 */
	public function test_section_intros_are_rendered() {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'Configure the Decker plugin settings.', $html );
		$this->assertStringContainsString( 'Configure how Decker improves task descriptions with AI.', $html );
	}

	/**
	 * Stored values are reflected back into the rendered controls.
	 */
	public function test_stored_values_are_reflected() {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'value="fixed-shared-key-1234"', $html );
		$this->assertStringContainsString( 'Maintenance tonight', $html );
		$this->assertStringContainsString( 'value="7,9"', $html );
		$this->assertStringContainsString( 'value="wss://collab.example.test"', $html );

		// The stored radio choices come back checked.
		$this->assertMatchesRegularExpression( '/value="warning"\s+checked=/', $html );
		$this->assertMatchesRegularExpression( '/value="classic"\s+checked=/', $html );
	}

	/**
	 * The destructive clear-all-data control ships with its own nonce.
	 */
	public function test_clear_all_data_control_is_nonce_protected() {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'name="decker_clear_all_data"', $html );
		$this->assertStringContainsString( 'name="decker_clear_all_data_nonce"', $html );
	}

	/**
	 * The browser-local board status toggle renders without a persisted name.
	 *
	 * It must not submit as part of decker_settings: the preference lives in
	 * the browser only.
	 */
	public function test_board_status_toggle_is_browser_local() {
		$html = $this->render_screen();

		$this->assertStringContainsString( 'id="sidebar-board-status-check"', $html );
		$this->assertStringNotContainsString( 'name="decker_settings[sidebar_board_status]"', $html );
	}

	/**
	 * The saved Gemini key is never echoed back into the form.
	 */
	public function test_saved_api_key_is_not_echoed() {
		update_option(
			'decker_settings',
			array_merge( get_option( 'decker_settings', array() ), array( 'ai_api_key' => 'sk-super-secret' ) )
		);

		$html = $this->render_screen();

		$this->assertStringNotContainsString( 'sk-super-secret', $html );
		$this->assertMatchesRegularExpression(
			'/name="decker_settings\[ai_api_key\]"[^>]*value=""/',
			$html,
			'The API key input must render empty even when a key is stored.'
		);
	}
}
