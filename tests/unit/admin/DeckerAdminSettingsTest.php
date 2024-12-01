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
		// Instantiate the Decker_Admin_Settings class.
		$this->admin_settings = new Decker_Admin_Settings();

		remove_action( 'admin_init', array( $this->admin_settings, 'handle_clear_all_data' ) );

	}

	/**
	 * Test the settings_validate method with valid input.
	 */
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

		// Capture the redirect to prevent actual redirection during tests.
        add_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 1 );
		add_filter( 'wp_die_handler', array( $this, 'get_f_wp_die_handler' ) );

		try {
		    // Run the method.
		    $this->admin_settings->handle_clear_all_data();
		} catch ( Exception $e ) {
		    // Optionally, assert the exception message or handle it.
		    $this->assertStringContainsString( '', $e->getMessage() );
		}


		// Remove the filter.
	    remove_filter( 'wp_redirect', array( $this, 'filter_wp_redirect' ), 10, 1 );
		remove_filter( 'wp_die_handler', array( $this, 'get_f_wp_die_handler' ) );


		// Assert that the post is deleted.
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

    /**
     * Callback para filtrar wp_redirect durante las pruebas.
     *
     * @param string $location URL to go.
     * @return false to prevent redirect.
     */
    public function filter_wp_redirect( $location ) {
        return false; // Prevent redirect.
    }

	/**
	 * Returns the wp_die handler for testing.
	 *
	 * @return callable
	 */
	public function get_f_wp_die_handler() {
	    return array( $this, 'wp_die_handler' );
	}

	/**
	 * Custom wp_die handler for testing.
	 *
	 * @param string $message The message passed to wp_die().
	 * @param string $title   The title passed to wp_die().
	 * @param array  $args    Additional arguments passed to wp_die().
	 */
	public function wp_die_handler( $message, $title = '', $args = array() ) {
	    // For testing purposes, you can throw an exception or handle it as needed.
	    throw new Exception( $message );
	}

}
