<?php
/**
 * Class Test_Decker_Boards
 *
 * @package Decker
 */

class DeckerBoardsTest extends Decker_Test_Base {

	/**
	 * Users for testing.
	 */
	private int $editor;
	private int $subscriber;
	private int $administrator;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that taxonomies are registered.
		do_action( 'init' );

		// Create users with default WordPress roles for testing.
		$this->editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->administrator = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}


	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		// Delete users created for testing.
		if ( $this->editor ) {
			wp_delete_user( $this->editor );
		}
		if ( $this->subscriber ) {
			wp_delete_user( $this->subscriber );
		}
		if ( $this->administrator ) {
			wp_delete_user( $this->administrator );
		}

		parent::tear_down();
	}

	/**
	 * Tests that the 'decker_board' taxonomy exists.
	 */
	public function test_taxonomies_exist() {
		$this->assertTrue( taxonomy_exists( 'decker_board' ), 'The taxonomy decker_board should exist.' );
	}

	/**
	 * Tests that the 'decker_board' taxonomy is associated with the 'decker_task' post type.
	 */
	public function test_taxonomy_relationships() {
		$board_object = get_taxonomy( 'decker_board' );

		$this->assertTrue( in_array( 'decker_task', (array) $board_object->object_type ), 'decker_board should be associated with decker_task.' );
	}

	/**
	 * Tests that an editor can create terms using the factory.
	 */
	public function test_editor_can_create_terms() {
		wp_set_current_user( $this->editor );

		// Create a term using the factory with color
		$term_id = self::factory()->board->create(
			array(
				'name' => 'Sprint 1',
				'color' => '#ff5733',
			)
		);

		// Verify that the term was created successfully
		$term = get_term( $term_id, 'decker_board' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should be a valid WP_Term object.' );
		$this->assertEquals( 'Sprint 1', $term->name, 'The term name should match.' );

		// Verify the color meta was saved
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#ff5733', $color, 'The term color should match.' );
		
		// Verify the visibility settings default to true
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb = get_term_meta( $term_id, 'term-show-in-kb', true );
		$this->assertTrue(empty($show_in_boards) || $show_in_boards === '1', 'Show in boards should default to true.');
		$this->assertTrue(empty($show_in_kb) || $show_in_kb === '1', 'Show in knowledge base should default to true.');

		wp_set_current_user( 0 );
	}


	/**
	 * Tests that a subscriber cannot create terms.
	 */
	public function test_subscriber_cannot_create_terms() {
		wp_set_current_user( $this->subscriber );

		// Attempt to create a term.
		$term = self::factory()->board->create_and_get( array( 'name' => 'Sprint 2' ) );

		// Verify that creation fails.
		$this->assertWPError( $term, 'The term should not be created by a subscriber.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a user with permissions can delete terms created with the factory.
	 */
	public function test_editor_can_delete_terms() {
		wp_set_current_user( $this->editor );

		// Create a term using the factory.
		$term_id = self::factory()->board->create( array( 'name' => 'Sprint 3' ) );

		// Verify that the term exists
		$this->assertNotNull( get_term( $term_id, 'decker_board' ), 'The term should exist before being deleted.' );

		// Delete the term
		$result = wp_delete_term( $term_id, 'decker_board' );

		// Verify that deletion was successful
		$this->assertTrue( $result, 'The term should be deleted successfully.' );
		$this->assertNull( get_term( $term_id, 'decker_board' ), 'The term should not exist after being deleted.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that an administrator can delete terms.
	 */
	public function test_administrator_can_delete_terms() {
		wp_set_current_user( $this->administrator );

		// Create a term using the factory
		$term_id = self::factory()->board->create(
			array(
				'name' => 'Sprint 3',
				'color' => '#33ff57',
			)
		);

		// Verify the term exists
		$term = get_term( $term_id, 'decker_board' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should exist before deletion.' );

		// Delete the term
		$result = wp_delete_term( $term_id, 'decker_board' );

		// Verify that deletion was successful
		$this->assertTrue( $result, 'The term should be deleted successfully.' );
		$this->assertNull( get_term( $term_id, 'decker_board' ), 'The term should not exist after being deleted.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that a subscriber cannot delete terms.
	 */
	public function test_subscriber_cannot_delete_terms() {
		wp_set_current_user( $this->administrator );

		// Create a term using the factory
		$term_id = self::factory()->board->create(
			array(
				'name' => 'Sprint 4',
				'color' => '#5733ff',
			)
		);

		// Verify the term exists
		$term = get_term( $term_id, 'decker_board' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should exist before attempted deletion.' );

		wp_set_current_user( $this->subscriber );

		// Expect the WPDieException to be thrown.
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to delete terms.' );

		// Attempt to delete the term.
		$result = wp_delete_term( $term_id, 'decker_board' );

		// Verify that the term still exists after the exception is thrown.
		$this->assertNotNull( get_term( $term_id, 'decker_board' ), 'The term should still exist because deletion is not allowed.' );

		// Verify that deletion fails.
		$this->assertFalse( $result, 'The term should not be deleted by a subscriber.' );

		wp_set_current_user( 0 );
	}

	/**
	 * Tests the creation and deletion of multiple terms using the factory.
	 */
	public function test_create_and_delete_multiple_terms() {
		wp_set_current_user( $this->editor );

		$terms = array( 'Board A', 'Board B', 'Board C' );
		$term_ids = array();

		// Create multiple terms using the factory
		foreach ( $terms as $term_name ) {
			$term_id = self::factory()->board->create( array( 'name' => $term_name ) );
			$term_ids[] = $term_id;

			$term = get_term( $term_id, 'decker_board' );
			$this->assertInstanceOf( WP_Term::class, $term, "The term '{$term_name}' should be a valid WP_Term object." );
			$this->assertEquals( $term_name, $term->name, "The term name '{$term_name}' should match." );
		}

		// Verify that all terms exist
		foreach ( $term_ids as $term_id ) {
			$this->assertNotNull( get_term( $term_id, 'decker_board' ), "The term with ID {$term_id} should exist." );
		}

		// Delete all terms
		foreach ( $term_ids as $term_id ) {
			$result = wp_delete_term( $term_id, 'decker_board' );
			$this->assertTrue( $result, "The term with ID {$term_id} should be deleted successfully." );
			$this->assertNull( get_term( $term_id, 'decker_board' ), "The term with ID {$term_id} should not exist after being deleted." );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Tests that only users with edit permissions can save color metadata.
	 */
	public function test_editor_can_save_color_meta() {
		wp_set_current_user( $this->editor );

		// Create a term using the factory
		$term_id = self::factory()->board->create(
			array(
				'name'  => 'Sprint 5',
				'color' => '#ff0000',
			)
		);

		// Verify that the color has been saved correctly
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#ff0000', $color, 'The term color should be #ff0000.' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Tests that users without permissions cannot save color metadata.
	 */
	public function test_subscriber_cannot_save_color_meta() {
		// Set up as editor to create the term
		wp_set_current_user( $this->editor );

		// Create a term using the factory
		$term_id = self::factory()->board->create(
			array(
				'name'  => 'Sprint 6',
				'color' => '#00ff00',
			)
		);

		// Verify that the term was created with the correct metadata
		$this->assertNotWPError( $term_id, 'The term should be created without errors.' );
		$term = get_term( $term_id, 'decker_board' );
		$this->assertInstanceOf( WP_Term::class, $term, 'The term should be a valid WP_Term object.' );
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#00ff00', $color, 'The term color should match the initial value.' );

		// Switch to subscriber user
		wp_set_current_user( $this->subscriber );

		// Simulate editing the term via factory update
		$updated_term_id = self::factory()->board->update_object(
			$term_id,
			array(
				'color' => '#0000ff',
			)
		);

		// Verify that the update fails
		// In this case, we need to check permissions outside the factory logic, as `update_term_meta` does not enforce them.
		if ( current_user_can( 'edit_terms', $term_id ) ) {
			$this->fail( 'A subscriber should not be able to edit terms.' );
		}

		// Verify that the color has not changed
		$color = get_term_meta( $term_id, 'term-color', true );
		$this->assertEquals( '#00ff00', $color, 'The term color should not have changed for a subscriber.' );

		// Clean up
		wp_set_current_user( 0 );
	}
	
	/**
	 * Tests that visibility settings can be set and updated.
	 */
	public function test_board_visibility_settings() {
		wp_set_current_user( $this->editor );
		
		// Create a board with explicit visibility settings
		$term_id = self::factory()->board->create(
			array(
				'name' => 'Visibility Test Board',
				'slug' => 'visibility-test-board',
				'color' => '#123456',
				'show_in_boards' => false,
				'show_in_kb' => true,
			)
		);
		
		// Verify the visibility settings were saved correctly
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb = get_term_meta( $term_id, 'term-show-in-kb', true );
		$this->assertEquals( '0', $show_in_boards, 'Show in boards should be false.' );
		$this->assertEquals( '1', $show_in_kb, 'Show in knowledge base should be true.' );
		
		// Update the visibility settings
		self::factory()->board->update_object(
			$term_id,
			array(
				'show_in_boards' => true,
				'show_in_kb' => false,
			)
		);
		
		// Verify the updated settings
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb = get_term_meta( $term_id, 'term-show-in-kb', true );
		$this->assertEquals( '1', $show_in_boards, 'Show in boards should now be true.' );
		$this->assertEquals( '0', $show_in_kb, 'Show in knowledge base should now be false.' );
		
		// Clean up
		wp_delete_term( $term_id, 'decker_board' );
	}
	
	/**
	 * Tests that Board objects correctly reflect visibility settings.
	 */
	public function test_board_object_visibility() {
		wp_set_current_user( $this->editor );
		
		// Create boards with different visibility combinations
		$board1_id = self::factory()->board->create(
			array(
				'name' => 'Board Both Visible',
				'slug' => 'board-both-visible',
				'color' => '#aabbcc',
				'show_in_boards' => true,
				'show_in_kb' => true,
			)
		);
		
		// Force a reset of the BoardManager to ensure it reloads from DB
		BoardManager::reset_instance();
		
		$board2_id = self::factory()->board->create(
			array(
				'name' => 'Board Both Hidden',
				'slug' => 'board-both-hidden',
				'color' => '#ddeeff',
				'show_in_boards' => false,
				'show_in_kb' => false,
			)
		);
		
		// Force a reset of the BoardManager to ensure it reloads from DB
		BoardManager::reset_instance();
		
		$board3_id = self::factory()->board->create(
			array(
				'name' => 'Board Mixed Visibility',
				'slug' => 'board-mixed-visibility',
				'color' => '#112233',
				'show_in_boards' => true,
				'show_in_kb' => false,
			)
		);
		
		// Force a reset of the BoardManager to ensure it reloads from DB
		BoardManager::reset_instance();
		
		// Get Board objects and verify visibility properties
		$board1 = BoardManager::get_board_by_slug('board-both-visible');
		$this->assertTrue($board1->show_in_boards, 'Board 1 should be visible in boards.');
		$this->assertTrue($board1->show_in_kb, 'Board 1 should be visible in knowledge base.');
		
		$board2 = BoardManager::get_board_by_slug('board-both-hidden');
		$this->assertFalse($board2->show_in_boards, 'Board 2 should be hidden in boards.');
		$this->assertFalse($board2->show_in_kb, 'Board 2 should be hidden in knowledge base.');
		
		$board3 = BoardManager::get_board_by_slug('board-mixed-visibility');
		$this->assertTrue($board3->show_in_boards, 'Board 3 should be visible in boards.');
		$this->assertFalse($board3->show_in_kb, 'Board 3 should be hidden in knowledge base.');
		
		// Clean up
		wp_delete_term( $board1_id, 'decker_board' );
		wp_delete_term( $board2_id, 'decker_board' );
		wp_delete_term( $board3_id, 'decker_board' );
	}
}
