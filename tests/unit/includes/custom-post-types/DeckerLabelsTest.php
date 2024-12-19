<?php
/**
 * Class Test_Decker_Labels
 *
 * @package Decker
 */

class DeckerLabelsTest extends Decker_Test_Base {

	private int $editor;
	private int $subscriber;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that taxonomies are registered.
		do_action( 'init' );

		// Create users for testing
		$this->editor = self::factory()->user->create(
			array(
				'role' => 'editor',
			)
		);

		$this->subscriber = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->editor );
		wp_delete_user( $this->subscriber );
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
		wp_set_current_user( $this->editor );

		// Create a term using the factory with color
		$term_id = self::factory()->label->create(
			array(
				'name' => 'Sprint 1',
				'color' => '#ff5733',
			)
		);

		// Verify that the term was created successfully
		$term = get_term( $term_id, 'decker_label' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should be a valid WP_Term object.' );
		$this->assertEquals( 'Sprint 1', $term->name, 'The term name should match.' );

		// Verify the color meta was saved
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#ff5733', $color, 'The term color should match.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user without permissions cannot create terms.
	 */
	public function test_subscriber_cannot_create_terms() {
		wp_set_current_user( $this->subscriber );

		// Attempt to create a term using the factory
		$term = self::factory()->label->create_and_get(
			array(
				'name' => 'Sprint 2',
				'color' => '#33ff57',
			)
		);

		// Verify that creation fails
		$this->assertWPError( $term, 'The term should not be created by a subscriber.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user with permissions can delete terms.
	 */
	public function test_editor_can_delete_terms() {
		wp_set_current_user( $this->editor );

		// Create a term using the factory
		$term_id = self::factory()->label->create(
			array(
				'name' => 'Sprint 3',
				'color' => '#33ff57',
			)
		);

		// Verify the term exists
		$term = get_term( $term_id, 'decker_label' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should exist before deletion.' );

		// Delete the term
		$result = wp_delete_term( $term_id, 'decker_label' );

		// Verify that deletion was successful
		$this->assertTrue( $result, 'The term should be deleted successfully.' );
		$this->assertNull( get_term( $term_id, 'decker_label' ), 'The term should not exist after being deleted.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user without permissions cannot delete terms.
	 */
	public function test_subscriber_cannot_delete_terms() {
		wp_set_current_user( $this->editor );

		// Create a term using the factory
		$term_id = self::factory()->label->create(
			array(
				'name' => 'Sprint 4',
				'color' => '#5733ff',
			)
		);

		// Verify the term exists
		$term = get_term( $term_id, 'decker_label' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should exist before attempted deletion.' );

		wp_set_current_user( $this->subscriber );

		// Expect wp_die to be called
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to delete terms.' );

		// Attempt to delete the term
		$result = wp_delete_term( $term_id, 'decker_label' );

		// Verify that the term still exists
		$this->assertNotNull( get_term( $term_id, 'decker_label' ), 'The term should still exist because deletion is not allowed.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests the creation and deletion of multiple terms.
	 */
	public function test_create_and_delete_multiple_terms() {

		// Set current user as editor
		wp_set_current_user( $this->editor );

		$terms = array( 'Label A', 'Label B', 'Label C' );
		$term_ids = array();

		// Create multiple terms
		foreach ( $terms as $term_name ) {
			$term = self::factory()->label->create_and_get( array( 'name' => $term_name ) );

			$this->assertNotWPError( $term, "The term '{$term_name}' should be created without errors." );
			$this->assertInstanceOf( WP_Term::class, $term, 'The term should be a valid WP_Term object.' );
			$this->assertGreaterThan( 0, $term->term_id, 'The term name should match.' );

			$term_ids[] = $term->term_id;
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
		// Set current user as editor
		wp_set_current_user( $this->editor );

		$term = self::factory()->label->create_and_get();

		$this->assertNotWPError( $term, 'The term should be created without errors.' );

		// Simulate editing the term via factory update
		$updated_term_id = self::factory()->label->update_object(
			$term->term_id,
			array(
				'color' => '#ff0000',
			)
		);

		// Verify that the color has been saved correctly
		$color = get_term_meta( $term->term_id, 'term-color', true );
		$this->assertEquals( '#ff0000', $color, 'The term color should be #ff0000.' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that users without permissions cannot save color metadata.
	 */
	public function test_subscriber_cannot_save_color_meta() {

		// Set current user as editor
		wp_set_current_user( $this->editor );

		// Create a term using the factory
		$term_id = self::factory()->label->create(
			array(
				'name'  => 'Sprint 6',
				'color' => '#00ff00',
			)
		);

		// Verify that the term was created with the correct metadata
		$this->assertNotWPError( $term_id, 'The term should be created without errors.' );
		$term = get_term( $term_id, 'decker_label' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should be a valid WP_Term object.' );
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#00ff00', $color, 'The term color should match the initial value.' );

		// Switch to subscriber user
		wp_set_current_user( $this->subscriber );

		// Attempt to update the term's color via factory update
		$updated_term_id = self::factory()->label->update_object(
			$term_id,
			array(
				'color' => '#0000ff',
			)
		);

		// Verify that the update fails
		// In this case, we need to check permissions outside the factory logic
		if ( current_user_can( 'edit_terms', $term_id ) ) {
			$this->fail( 'A subscriber should not be able to edit terms.' );
		}

		// Verify that the color has not changed
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#00ff00', $color, 'The term color should not have changed for a subscriber.' );

		// Clean up
		wp_set_current_user( 0 );
	}
}
