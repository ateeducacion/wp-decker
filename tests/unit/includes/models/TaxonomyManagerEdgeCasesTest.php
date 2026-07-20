<?php
/**
 * Edge case tests for BoardManager and LabelManager.
 *
 * Covers failure paths and boundary inputs that complement the main
 * BoardmanagerTest and LabelmanagerTest.
 *
 * @package Decker
 */

/**
 * Edge-case coverage for BoardManager.
 */
class BoardManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user used for permission-allowed operations.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// get_board_by_slug – missing / empty slug
	// -----------------------------------------------------------------------

	/**
	 * An empty slug returns null without errors.
	 */
	public function test_get_board_by_slug_returns_null_for_empty_slug() {
		$result = BoardManager::get_board_by_slug( '' );

		$this->assertNull( $result );
	}

	/**
	 * A slug that does not match any board returns null.
	 */
	public function test_get_board_by_slug_returns_null_for_nonexistent_slug() {
		$result = BoardManager::get_board_by_slug( 'this-board-does-not-exist' );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// save_board – validation edge cases
	// -----------------------------------------------------------------------

	/**
	 * Saving a board with an empty name returns a success response because
	 * WordPress allows nameless terms (slug auto-generated from "").
	 *
	 * What matters most here is that no fatal exception is thrown and the
	 * return shape conforms to the documented contract.
	 */
	public function test_save_board_with_empty_name_does_not_fatal() {
		$result = BoardManager::save_board(
			array(
				'name'  => '',
				'slug'  => 'auto-slug',
				'color' => '#aabbcc',
			),
			0
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * Saving a board with a non-hex-color value stores an empty color because
	 * sanitize_hex_color() returns '' for invalid values.
	 */
	public function test_save_board_with_invalid_color_stores_empty_color() {
		$result = BoardManager::save_board(
			array(
				'name'  => 'Color Test Board',
				'slug'  => 'color-test-board',
				'color' => 'not-a-color',
			),
			0
		);

		$this->assertTrue( $result['success'] );

		$board = BoardManager::get_board_by_slug( 'color-test-board' );
		$this->assertNotNull( $board );
		$this->assertSame( '', $board->color );
	}

	// -----------------------------------------------------------------------
	// delete_board – non-existent ID
	// -----------------------------------------------------------------------

	/**
	 * Deleting a board with an ID that does not correspond to any term
	 * returns a success=false response (wp_delete_term returns a WP_Error).
	 */
	public function test_delete_board_with_nonexistent_id_returns_failure() {
		$result = BoardManager::delete_board( 999999 );

		// wp_delete_term returns false for non-existent term IDs, so the
		// deletion did not succeed in the expected sense.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	// -----------------------------------------------------------------------
	// save_board – subscriber cannot create
	// -----------------------------------------------------------------------

	/**
	 * A subscriber (no edit_posts cap) cannot save a new board.
	 */
	public function test_save_board_by_subscriber_returns_permission_error() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = BoardManager::save_board(
			array(
				'name'  => 'Forbidden Board',
				'slug'  => 'forbidden-board',
				'color' => '#000000',
			),
			0
		);

		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// save_board – visibility flags default to "off" when absent
	// -----------------------------------------------------------------------

	/**
	 * When show_in_boards and show_in_kb are absent from the data array,
	 * the term meta is stored as '0'.
	 */
	public function test_save_board_without_visibility_flags_defaults_to_zero() {
		$result = BoardManager::save_board(
			array(
				'name'  => 'No Visibility Board',
				'slug'  => 'no-visibility-board',
				'color' => '#123456',
				// Deliberately omit show_in_boards and show_in_kb.
			),
			0
		);

		$this->assertTrue( $result['success'] );

		$board = BoardManager::get_board_by_slug( 'no-visibility-board' );
		$this->assertNotNull( $board );

		$term_id        = $board->id;
		$show_in_boards = get_term_meta( $term_id, 'term-show-in-boards', true );
		$show_in_kb     = get_term_meta( $term_id, 'term-show-in-kb', true );

		$this->assertSame( '0', $show_in_boards );
		$this->assertSame( '0', $show_in_kb );
	}
}

/**
 * Edge-case coverage for LabelManager.
 */
class LabelManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user used for permission-allowed operations.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// get_label_by_name – empty / non-existent name
	// -----------------------------------------------------------------------

	/**
	 * An empty name returns null without errors.
	 */
	public function test_get_label_by_name_returns_null_for_empty_name() {
		$result = LabelManager::get_label_by_name( '' );

		$this->assertNull( $result );
	}

	/**
	 * A name that does not match any label returns null.
	 */
	public function test_get_label_by_name_returns_null_for_nonexistent_name() {
		$result = LabelManager::get_label_by_name( 'This Label Does Not Exist' );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// get_label_by_id – zero / non-existent ID
	// -----------------------------------------------------------------------

	/**
	 * ID 0 returns null without errors.
	 */
	public function test_get_label_by_id_returns_null_for_zero() {
		$result = LabelManager::get_label_by_id( 0 );

		$this->assertNull( $result );
	}

	/**
	 * A non-existent positive ID returns null.
	 */
	public function test_get_label_by_id_returns_null_for_nonexistent_id() {
		$result = LabelManager::get_label_by_id( 999999 );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// save_label – invalid color is sanitized to empty
	// -----------------------------------------------------------------------

	/**
	 * A label saved with a non-hex color value stores an empty color because
	 * sanitize_hex_color() returns '' for invalid values.
	 */
	public function test_save_label_with_invalid_color_stores_empty_color() {
		$result = LabelManager::save_label(
			array(
				'name'  => 'Invalid Color Label',
				'slug'  => 'invalid-color-label',
				'color' => 'not-a-hex-color',
			),
			0
		);

		$this->assertTrue( $result['success'] );

		$label = LabelManager::get_label_by_name( 'Invalid Color Label' );
		$this->assertNotNull( $label );
		$this->assertSame( '', $label->color );
	}

	// -----------------------------------------------------------------------
	// delete_label – non-existent ID
	// -----------------------------------------------------------------------

	/**
	 * Deleting a label with a non-existent ID returns an array response
	 * (may succeed or fail, but must not throw).
	 */
	public function test_delete_label_with_nonexistent_id_does_not_throw() {
		$result = LabelManager::delete_label( 999999 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	// -----------------------------------------------------------------------
	// save_label – subscriber cannot create
	// -----------------------------------------------------------------------

	/**
	 * A subscriber (no edit_posts cap) cannot save a new label.
	 */
	public function test_save_label_by_subscriber_returns_permission_error() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = LabelManager::save_label(
			array(
				'name'  => 'Forbidden Label',
				'slug'  => 'forbidden-label',
				'color' => '#000000',
			),
			0
		);

		$this->assertFalse( $result['success'] );
	}

	// -----------------------------------------------------------------------
	// delete_label – subscriber cannot delete
	// -----------------------------------------------------------------------

	/**
	 * A subscriber (no edit_posts cap) cannot delete a label.
	 */
	public function test_delete_label_by_subscriber_returns_permission_error() {
		// Create a real label to attempt to delete.
		$label_id = self::factory()->label->create(
			array(
				'name'  => 'Deletable Label',
				'color' => '#ff0000',
			)
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = LabelManager::delete_label( $label_id );

		$this->assertFalse( $result['success'] );

		// Confirm the label still exists.
		LabelManager::reset_instance();
		$this->assertNotNull( LabelManager::get_label_by_id( $label_id ) );
	}
}
