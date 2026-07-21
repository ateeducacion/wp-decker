<?php
/**
 * Edge case tests for BoardManager and LabelManager.
 *
 * @package Decker
 */

/**
 * Edge-case coverage for BoardManager.
 */
class BoardManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Set up an editor user.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Restore the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Return null for an empty board slug.
	 */
	public function test_get_board_by_slug_returns_null_for_empty_slug() {
		$this->assertNull( BoardManager::get_board_by_slug( '' ) );
	}

	/**
	 * Return null for a missing board slug.
	 */
	public function test_get_board_by_slug_returns_null_for_nonexistent_slug() {
		$this->assertNull( BoardManager::get_board_by_slug( 'this-board-does-not-exist' ) );
	}

	/**
	 * Handle an empty board name without throwing.
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
	 * Expose a sanitized invalid color as null in the board model.
	 */
	public function test_save_board_with_invalid_color_exposes_null_color() {
		$result = BoardManager::save_board(
			array(
				'name'  => 'Color Test Board',
				'slug'  => 'color-test-board',
				'color' => 'not-a-color',
			),
			0
		);
		$board = BoardManager::get_board_by_slug( 'color-test-board' );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $board );
		$this->assertNull( $board->color );
	}

	/**
	 * Return a structured response when deleting a missing board.
	 */
	public function test_delete_board_with_nonexistent_id_returns_response() {
		$result = BoardManager::delete_board( 999999 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * Prevent subscribers from creating boards.
	 */
	public function test_save_board_by_subscriber_returns_permission_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
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

	/**
	 * Default missing visibility flags to zero.
	 */
	public function test_save_board_without_visibility_flags_defaults_to_zero() {
		$result = BoardManager::save_board(
			array(
				'name'  => 'No Visibility Board',
				'slug'  => 'no-visibility-board',
				'color' => '#123456',
			),
			0
		);
		$board = BoardManager::get_board_by_slug( 'no-visibility-board' );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $board );
		$this->assertSame( '0', get_term_meta( $board->id, 'term-show-in-boards', true ) );
		$this->assertSame( '0', get_term_meta( $board->id, 'term-show-in-kb', true ) );
	}
}

/**
 * Edge-case coverage for LabelManager.
 */
class LabelManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Set up an editor user.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Restore the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Return null for an empty label name.
	 */
	public function test_get_label_by_name_returns_null_for_empty_name() {
		$this->assertNull( LabelManager::get_label_by_name( '' ) );
	}

	/**
	 * Return null for a missing label name.
	 */
	public function test_get_label_by_name_returns_null_for_nonexistent_name() {
		$this->assertNull( LabelManager::get_label_by_name( 'This Label Does Not Exist' ) );
	}

	/**
	 * Return null for label ID zero.
	 */
	public function test_get_label_by_id_returns_null_for_zero() {
		$this->assertNull( LabelManager::get_label_by_id( 0 ) );
	}

	/**
	 * Return null for a missing positive label ID.
	 */
	public function test_get_label_by_id_returns_null_for_nonexistent_id() {
		$this->assertNull( LabelManager::get_label_by_id( 999999 ) );
	}

	/**
	 * Expose a sanitized invalid color as null in the label model.
	 */
	public function test_save_label_with_invalid_color_exposes_null_color() {
		$result = LabelManager::save_label(
			array(
				'name'  => 'Invalid Color Label',
				'slug'  => 'invalid-color-label',
				'color' => 'not-a-hex-color',
			),
			0
		);
		$label = LabelManager::get_label_by_name( 'Invalid Color Label' );

		$this->assertTrue( $result['success'] );
		$this->assertNotNull( $label );
		$this->assertNull( $label->color );
	}

	/**
	 * Return a structured response when deleting a missing label.
	 */
	public function test_delete_label_with_nonexistent_id_does_not_throw() {
		$result = LabelManager::delete_label( 999999 );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
	}

	/**
	 * Prevent subscribers from creating labels.
	 */
	public function test_save_label_by_subscriber_returns_permission_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
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

	/**
	 * Prevent subscribers from deleting labels.
	 */
	public function test_delete_label_by_subscriber_returns_permission_error() {
		$label_id = self::factory()->label->create(
			array(
				'name'  => 'Deletable Label',
				'color' => '#ff0000',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = LabelManager::delete_label( $label_id );

		$this->assertFalse( $result['success'] );
		LabelManager::reset_instance();
		$this->assertNotNull( LabelManager::get_label_by_id( $label_id ) );
	}
}
