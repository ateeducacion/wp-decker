<?php
/**
 * Class Test_Decker_User_Extended
 *
 * @package Decker
 */

class DeckerUserExtendedTest extends WP_UnitTestCase {

	/**
	 * Instance of Decker_User_Extended.
	 *
	 * @var Decker_User_Extended
	 */
	protected $decker_user_extended;

	/**
	 * Set up the test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		// Ensure the Decker_User_Extended class is available.
		if ( class_exists( 'Decker_User_Extended' ) ) {
			$this->decker_user_extended = new Decker_User_Extended();
		} else {
			$this->fail( 'The Decker_User_Extended class does not exist.' );
		}
	}

	/**
	 * Test creating users and assigning color and board.
	 */
	public function test_create_users_and_assign_color_and_board() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create 'decker_board' terms.
		$board_ids = array();
		for ( $i = 1; $i <= 2; $i++ ) {
			$term = wp_insert_term( "Board {$i}", 'decker_board' );
			if ( ! is_wp_error( $term ) ) {
				$board_ids[] = $term['term_id'];
			}
		}

		$this->assertCount( 2, $board_ids, 'Failed to create the correct number of decker_board terms.' );

		// Create two test users.
		$user_ids = $this->factory->user->create_many( 2 );

		foreach ( $user_ids as $index => $user_id ) {
			// Assign favorite color.
			$color = sprintf( '#%06X', mt_rand( 0, 0xFFFFFF ) );
			update_user_meta( $user_id, 'decker_color', $color );

			// Assign default board.
			$board_id = $board_ids[ $index % count( $board_ids ) ];
			update_user_meta( $user_id, 'decker_default_board', $board_id );

			// Verify that metadata was saved correctly.
			$saved_color = get_user_meta( $user_id, 'decker_color', true );
			$saved_board = get_user_meta( $user_id, 'decker_default_board', true );

			$this->assertEquals( $color, $saved_color, "Failed to save color for user ID {$user_id}." );
			$this->assertEquals( $board_id, $saved_board, "Failed to save board for user ID {$user_id}." );
		}
	}

	/**
	 * Test that user meta is deleted when a decker_board term is deleted.
	 */
	public function test_delete_decker_board_and_remove_user_meta() {

		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' ); // Ensure 'decker_term_action' matches your plugin action.

		// Create a 'decker_board' term.
		$term = wp_insert_term( 'Board to Delete', 'decker_board' );
		$this->assertFalse( is_wp_error( $term ), 'Failed to create decker_board term.' );
		$term_id = $term['term_id'];

		// Create a user and assign the board to be deleted.
		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, 'decker_default_board', $term_id );

		// Verify that the meta is assigned.
		$saved_board = get_user_meta( $user_id, 'decker_default_board', true );
		$this->assertEquals( $term_id, $saved_board, 'Failed to assign the board to the user.' );

		// Delete the 'decker_board' term.
		wp_delete_term( $term_id, 'decker_board' );

		// Verify that the meta has been removed.
		$deleted_board = get_user_meta( $user_id, 'decker_default_board', true );
		$this->assertEmpty( $deleted_board, 'The decker_default_board meta was not removed after deleting the term.' );
	}

	/**
	 * Test default email notification settings.
	 */
	public function test_default_email_notification_settings() {
		$user_id = $this->factory->user->create();
		$email_notifications = get_user_meta( $user_id, 'decker_email_notifications', true );

		$this->assertEmpty( $email_notifications, 'Email notification settings should be empty by default.' );

		// Ensure defaults are applied when retrieved.
		$default_settings = array(
			'task_assigned'   => '1',
			'task_completed'  => '1',
			'task_commented'  => '1',
		);
		$email_notifications = wp_parse_args( $email_notifications, $default_settings );

		$this->assertEquals( $default_settings, $email_notifications, 'Default email settings should be applied.' );
	}

	/**
	 * Test saving email notification settings.
	 */
	public function test_save_email_notification_settings() {
		$user_id = $this->factory->user->create();

		// Simulate saving settings.
		$settings = array(
			'task_assigned'   => '0',
			'task_completed'  => '1',
			'task_commented'  => '0',
		);

		update_user_meta( $user_id, 'decker_email_notifications', $settings );

		$saved_settings = get_user_meta( $user_id, 'decker_email_notifications', true );
		$this->assertEquals( $settings, $saved_settings, 'Failed to save email notification settings.' );
	}

	/**
	 * Test sanitization of email notification settings.
	 */
	public function test_sanitize_email_notification_settings() {

		// Enable global email notifications.
		update_option( 'decker_settings', array( 'allow_email_notifications' => '1' ) );

		$user_id = $this->factory->user->create();

		// Simulate invalid settings.
		$invalid_settings = array(
			'task_assigned'   => 'invalid',
			'task_completed'  => '1',
			'task_commented'  => null,
		);

		// Set the POST data to simulate saving invalid settings.
		$_POST['decker_email_notifications'] = $invalid_settings;

		// Call the method to save the settings.
		$this->decker_user_extended->save_custom_user_profile_fields( $user_id );

		// Retrieve the saved settings.
		$saved_settings = get_user_meta( $user_id, 'decker_email_notifications', true );

		// Verify that invalid values are sanitized.
		$this->assertEquals(
			array(
				'task_assigned'   => '0',
				'task_completed'  => '1',
				'task_commented'  => '0',
			),
			$saved_settings,
			'Failed to sanitize invalid email notification settings.'
		);

		// Ensure the result is always an array.
		$this->assertIsArray( $saved_settings, 'Email notification settings should always be an array.' );
	}



	/**
	 * Test email notification fields visibility based on global setting.
	 */
	public function test_email_notification_fields_visibility() {
		$user_id = $this->factory->user->create();

		// Case 1: Global setting enabled.
		update_option( 'decker_settings', array( 'allow_email_notifications' => '1' ) );
		ob_start();
		$this->decker_user_extended->add_custom_user_profile_fields( get_userdata( $user_id ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Notify me when a task is assigned to me', $output, 'Email notification fields should be visible when global setting is enabled.' );

		// Case 2: Global setting disabled.
		update_option( 'decker_settings', array( 'allow_email_notifications' => '0' ) );
		ob_start();
		$this->decker_user_extended->add_custom_user_profile_fields( get_userdata( $user_id ) );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Notify me when a task is assigned to me', $output, 'Email notification fields should not be visible when global setting is disabled.' );
	}



	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		// Clear any user meta created.
		$users = get_users( array( 'number' => -1 ) );
		foreach ( $users as $user ) {
			delete_user_meta( $user->ID, 'decker_color' );
			delete_user_meta( $user->ID, 'decker_default_board' );
		}

		// Delete all 'decker_board' terms.
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				wp_delete_term( $term->term_id, 'decker_board' );
			}
		}

		parent::tear_down();
	}
}
