<?php
/**
 * Class Test_Decker_Labels
 *
 * @package Decker
 */

class DeckerLabelsTest extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that taxonomies are registered.
		do_action( 'init' );

		// Create user roles for testing.
		add_role(
			'test_editor',
			'Test Editor',
			array(
				'read'       => true,
				'edit_posts' => true, // Grants permission to manage terms.
			)
		);

		add_role(
			'test_subscriber',
			'Test Subscriber',
			array(
				'read' => true,
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Remove roles created for testing.
		remove_role( 'test_editor' );
		remove_role( 'test_subscriber' );

		parent::tear_down();
	}

	/**
	 * Tests that the 'decker_label' taxonomy exists.
	 */
	public function test_taxonomies_exist() {
		$this->assertTrue( taxonomy_exists( 'decker_label' ), 'The taxonomy decker_label should exist.' );
	}

	/**
	 * Tests that the 'decker_label' taxonomy is associated with the 'decker_task' post type.
	 */
	public function test_taxonomy_relationships() {
		$label_object = get_taxonomy( 'decker_label' );

		$this->assertTrue( in_array( 'decker_task', (array) $label_object->object_type ), 'decker_label should be associated with decker_task.' );
	}

	/**
	 * Tests that a user with permissions can create terms.
	 */
	public function test_editor_can_create_terms() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_editor' role.
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login.
		wp_set_current_user( $editor->ID );

		// Create a term.
		$term_name = 'Sprint 1';
		$term = wp_insert_term( $term_name, 'decker_label' );

		// Verify that the term was created successfully.
		$this->assertNotWPError( $term, 'The term should be created without errors.' );
		$this->assertIsArray( $term, 'The term should be an array.' );
		$this->assertArrayHasKey( 'term_id', $term, 'The term should have an ID.' );
		$this->assertEquals( $term_name, get_term( $term['term_id'], 'decker_label' )->name, 'The term name should match.' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user without permissions cannot create terms.
	 */
	public function test_subscriber_cannot_create_terms() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_subscriber' role
		$subscriber = $this->factory->user->create_and_get( array( 'role' => 'test_subscriber' ) );
		$this->assertNotNull( $subscriber, 'The subscriber user should be created correctly.' );

		// Simulate the subscriber user login
		wp_set_current_user( $subscriber->ID );

		// Attempt to create a term
		$term_name = 'Sprint 2';
		$term = wp_insert_term( $term_name, 'decker_label' );

		// Verify that creation fails
		$this->assertWPError( $term, 'The term should not be created by a subscriber.' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user with permissions can delete terms.
	 */
	public function test_editor_can_delete_terms() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_editor' role
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login
		wp_set_current_user( $editor->ID );

		// Create a term
		$term_name = 'Sprint 3';
		$term = wp_insert_term( $term_name, 'decker_label' );
		$this->assertNotWPError( $term, 'The term should be created without errors.' );

		$term_id = $term['term_id'];

		// Verify that the term exists
		$this->assertNotNull( get_term( $term_id, 'decker_label' ), 'The term should exist before being deleted.' );

		// Delete the term
		$result = wp_delete_term( $term_id, 'decker_label' );

		// Verify that deletion was successful
		$this->assertTrue( $result, 'The term should be deleted successfully.' );
		$this->assertNull( get_term( $term_id, 'decker_label' ), 'The term should not exist after being deleted.' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user without permissions cannot delete terms.
	 */
	public function test_subscriber_cannot_delete_terms() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_editor' role to create the term
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login
		wp_set_current_user( $editor->ID );

		// Create a term
		$term_name = 'Sprint 4';
		$term = wp_insert_term( $term_name, 'decker_label' );
		$this->assertNotWPError( $term, 'The term should be created without errors.' );

		$term_id = $term['term_id'];

		// Clean up
		wp_set_current_user( 0 );

		// Create a user with the 'test_subscriber' role
		$subscriber = $this->factory->user->create_and_get( array( 'role' => 'test_subscriber' ) );
		$this->assertNotNull( $subscriber, 'The subscriber user should be created correctly.' );

		// Simulate the subscriber user login
		wp_set_current_user( $subscriber->ID );

		// Expect wp_die to be called.
		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'You do not have permission to delete terms.' );

		// Attempt to delete the term
		$result = wp_delete_term( $term_id, 'decker_label' );

		// $this->expectException( Exception::class );
		// $this->expectExceptionMessage( 'You do not have permission to delete this term.' );

		// Verify that the term still exists
		$this->assertNotNull( get_term( $term_id, 'decker_label' ), 'The term should exist because it was not deleted.' );

		// Clean up
		wp_set_current_user( 0 );

		// Delete the term to avoid leaving residual data
		wp_delete_term( $term_id, 'decker_label' );
	}

	/**
	 * Tests the creation and deletion of multiple terms.
	 */
	public function test_create_and_delete_multiple_terms() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_editor' role
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login
		wp_set_current_user( $editor->ID );

		$terms = array( 'Label A', 'Label B', 'Label C' );
		$term_ids = array();

		// Create multiple terms
		foreach ( $terms as $term_name ) {
			$term = wp_insert_term( $term_name, 'decker_label' );
			$this->assertNotWPError( $term, "The term '{$term_name}' should be created without errors." );
			$this->assertIsArray( $term, "The term '{$term_name}' should be an array." );
			$this->assertArrayHasKey( 'term_id', $term, "The term '{$term_name}' should have an ID." );
			$term_ids[] = $term['term_id'];
		}

		// Verify that all terms exist
		foreach ( $term_ids as $term_id ) {
			$this->assertNotNull( get_term( $term_id, 'decker_label' ), "The term with ID {$term_id} should exist." );
		}

		// Delete all terms
		foreach ( $term_ids as $term_id ) {
			$result = wp_delete_term( $term_id, 'decker_label' );
			$this->assertTrue( $result, "The term with ID {$term_id} should be deleted successfully." );
			$this->assertNull( get_term( $term_id, 'decker_label' ), "The term with ID {$term_id} should not exist after being deleted." );
		}

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that only users with edit permissions can save color metadata.
	 */
	public function test_editor_can_save_color_meta() {
		// Create a user with the 'test_editor' role
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login
		wp_set_current_user( $editor->ID );

		// Create a term with color
		$term_name = 'Sprint 5';
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term-color'] = '#ff0000';

		$term = wp_insert_term( $term_name, 'decker_label' );
		$this->assertNotWPError( $term, 'The term should be created without errors.' );

		$term_id = $term['term_id'];

		// Verify that the color has been saved correctly
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#ff0000', $color, 'The term color should be #ff0000.' );

		// Clean up
		unset( $_POST['decker_term_nonce'] );
		unset( $_POST['term-color'] );
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that users without permissions cannot save color metadata.
	 */
	public function test_subscriber_cannot_save_color_meta() {

		// Ensure 'decker_term_action' matches your plugin action.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a user with the 'test_editor' role to create the term
		$editor = $this->factory->user->create_and_get( array( 'role' => 'test_editor' ) );
		$this->assertNotNull( $editor, 'The editor user should be created correctly.' );

		// Simulate the editor user login
		wp_set_current_user( $editor->ID );

		// Create a term
		$term_name = 'Sprint 6';
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term-color'] = '#00ff00';

		$term = wp_insert_term( $term_name, 'decker_label' );
		$this->assertNotWPError( $term, 'The term should be created without errors.' );

		$term_id = $term['term_id'];

		// Clean up
		wp_set_current_user( 0 );
		unset( $_POST['decker_term_nonce'] );
		unset( $_POST['term-color'] );

		// Create a user with the 'test_subscriber' role
		$subscriber = $this->factory->user->create_and_get( array( 'role' => 'test_subscriber' ) );
		$this->assertNotNull( $subscriber, 'The subscriber user should be created correctly.' );

		// Simulate the subscriber user login
		wp_set_current_user( $subscriber->ID );

		// Attempt to update the term's color
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term-color'] = '#0000ff';

		// Simulate editing the term
		$result = wp_update_term(
			$term_id,
			'decker_label',
			array(
				'name'        => $term_name,
				'description' => '',
				'slug'        => '',
				'meta'        => array( 'term-color' => '#0000ff' ),
			)
		);

		// Verify that the update fails
		// Note: wp_update_term does not directly check permissions, so this test may need adjustments
		// depending on how capabilities are handled in your implementation.
		// For a more accurate test, you should simulate user actions in the admin form.

		// Verify that the color has not changed
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#00ff00', $color, 'The term color should not have changed for a subscriber.' );

		// Clean up
		unset( $_POST['decker_term_nonce'] );
		unset( $_POST['term-color'] );
		wp_set_current_user( 0 );
	}
}
