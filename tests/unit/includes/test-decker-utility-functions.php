<?php
/**
 * Unit tests for Decker_Utility_Functions.
 *
 * @package Decker
 */

class Test_Decker_Utility_Functions extends WP_UnitTestCase {

	/**
	 * Test current_user_has_at_least_minimum_role.
	 */
	public function test_current_user_has_at_least_minimum_role() {
		// Mock roles.
		$roles = array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array(
				'read' => true,
				'edit_posts' => true,
			),
			'administrator' => array(
				'read' => true,
				'edit_posts' => true,
				'manage_options' => true,
			),
		);

		// Mock WordPress roles.
		$wp_roles = wp_roles();
		$wp_roles->roles = $roles;
		$wp_roles->role_objects = array_map(
			function ( $capabilities ) {
				return (object) array( 'capabilities' => $capabilities );
			},
			$roles
		);

		// Mock decker settings.
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'editor' ) );

		// Create users.
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Test administrator.
		wp_set_current_user( $admin );
		$current_user_roles = wp_get_current_user()->roles;
		error_log( 'Current user roles (administrator): ' . implode( ', ', $current_user_roles ) );
		$this->assertTrue( Decker_Utility_Functions::current_user_has_at_least_minimum_role(), 'Administrator should have editor access.' );

		// Test editor.
		wp_set_current_user( $editor );
		$current_user_roles = wp_get_current_user()->roles;
		error_log( 'Current user roles (editor): ' . implode( ', ', $current_user_roles ) );
		$this->assertTrue( Decker_Utility_Functions::current_user_has_at_least_minimum_role(), 'Editor should have editor access.' );

		// Test subscriber.
		wp_set_current_user( $subscriber );
		$current_user_roles = wp_get_current_user()->roles;
		error_log( 'Current user roles (subscriber): ' . implode( ', ', $current_user_roles ) );
		$this->assertFalse( Decker_Utility_Functions::current_user_has_at_least_minimum_role(), 'Subscriber should not have editor access.' );

		// Cleanup.
		wp_set_current_user( 0 );
	}
}
