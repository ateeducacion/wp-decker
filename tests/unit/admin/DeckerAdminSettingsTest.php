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
			'ignored_users' => "{$user1_id},{$user2_id}"
		);

		$validated = $this->admin_settings->settings_validate($input);

		$this->assertEquals("{$user1_id},{$user2_id}", $validated['ignored_users']);
	}

	public function test_settings_validate_with_invalid_ignored_users() {
		// Create one valid user
		$valid_user_id = $this->factory->user->create();
		
		$input = array(
			'ignored_users' => "999999,{$valid_user_id},invalid"
		);

		$validated = $this->admin_settings->settings_validate($input);

		// Should only keep the valid user ID
		$this->assertEquals("{$valid_user_id}", $validated['ignored_users']);
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
		$board_id = wp_insert_term( 'Board 1', 'decker_board' )['term_id'];
		$label_id = wp_insert_term( 'Label 1', 'decker_label' )['term_id'];

		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create mock data: a post and a term.

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Test Task',
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
			)
		);

		wp_insert_term( 'Test Board', 'decker_board' );

		// Ensure data exists.
		$this->assertNotFalse( get_post( $post_id ), 'Post should exist before deletion.' );
		$terms = get_terms(
			array(
				'taxonomy' => 'decker_board',
				'hide_empty' => false,
			)
		);
		$this->assertNotEmpty( $terms, 'Terms should exist before deletion.' );

		// Simulate form submission.
		$_POST['decker_clear_all_data'] = '1';
		$_POST['decker_clear_all_data_nonce'] = wp_create_nonce( 'decker_clear_all_data_action' );

		// Ensur that $_REQUEST has the same values.
		$_REQUEST['decker_clear_all_data'] = $_POST['decker_clear_all_data'];
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
				'taxonomy' => 'decker_board',
				'hide_empty' => false,
			)
		);
		$this->assertEmpty( $terms, 'Terms should be deleted.' );
	}

	/**
	 * Test the deny_access method.
	 */
	public function test_deny_access() {
		// Capture the output of wp_die.
		$this->expectException( 'PHPUnit\Framework\Error\Error' );

		// Mock wp_die to throw an exception instead of terminating the script.
		if ( ! function_exists( 'wp_die' ) ) {
			function wp_die( $message ) {
				throw new Exception( $message );
			}
		}

		// Call the deny_access method.
		$this->admin_settings->deny_access();
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
