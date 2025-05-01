<?php
/**
 * Class Test_Decker_BoardManager
 *
 * @package Decker
 */

class DeckerBoardManagerTest extends Decker_Test_Base {
	private $test_board_id;
	private $editor;

	public function setUp(): void {
		parent::setUp();

		// // Manually trigger the 'init' action to ensure taxonomies are registered.
		// do_action( 'init' );

		// Create an editor user
		$this->editor = self::factory()->user->create(
			array(
				'role' => 'editor',
			)
		);

		wp_set_current_user( $this->editor );

		$result = $this->factory->board->create(
			array(
				'name' => 'Test Board',
				'slug' => 'test-board',
				'color' => '#ff0000',
			)
		);

		if ( is_wp_error( $result ) ) {
			var_dump( $result->get_error_message() );
		} else {
			$this->test_board_id = $result;
		}
	}

	public function tearDown(): void {
		// Clean up test data
		wp_set_current_user( $this->editor );
		wp_delete_term( $this->test_board_id, 'decker_board' );
		wp_delete_user( $this->editor );
		parent::tearDown();
	}

	public function test_get_board_by_slug() {
		$board = BoardManager::get_board_by_slug( 'test-board' );

		$this->assertInstanceOf( Board::class, $board );
		$this->assertEquals( 'Test Board', $board->name );
		$this->assertEquals( 'test-board', $board->slug );
		$this->assertEquals( '#ff0000', $board->color );
	}

	public function test_get_all_boards() {
		$boards = BoardManager::get_all_boards();

		$this->assertIsArray( $boards );
		$this->assertGreaterThan( 0, count( $boards ) );
		$this->assertInstanceOf( Board::class, $boards[0] );
	}

	public function test_save_board_without_permission() {
		// Ensure no user is logged in
		wp_set_current_user( 0 );

		$new_board_data = array(
			'name' => 'New Test Board',
			'slug' => 'new-test-board',
			'color' => '#00ff00',
		);

		$result = BoardManager::save_board( $new_board_data, 0 );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'You do not have permission to create terms.', $result['message'] );
	}

	public function test_delete_board_without_permission() {
		// Ensure no user is logged in
		wp_set_current_user( 0 );

		$result = BoardManager::delete_board( $this->test_board_id );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 'You do not have permission to delete boards', $result['message'] );
	}

	public function test_save_board_create() {
		wp_set_current_user( $this->editor );
		$new_board_data = array(
			'name' => 'New Test Board',
			'slug' => 'new-test-board',
			'color' => '#00ff00',
			'show_in_boards' => true,
			'show_in_kb' => false,
		);

		$result = BoardManager::save_board( $new_board_data, 0 );

		$this->assertTrue( $result['success'] );

		// Verify the board was created
		$term = get_term_by( 'name', 'New Test Board', 'decker_board' );
		$this->assertNotFalse( $term );
		$this->assertEquals( 'new-test-board', $term->slug );
		$this->assertEquals( '#00ff00', get_term_meta( $term->term_id, 'term-color', true ) );
		$this->assertEquals( '1', get_term_meta( $term->term_id, 'term-show-in-boards', true ) );
		$this->assertEquals( '0', get_term_meta( $term->term_id, 'term-show-in-kb', true ) );

		// Clean up
		wp_delete_term( $term->term_id, 'decker_board' );
	}

	public function test_save_board_update() {
		wp_set_current_user( $this->editor );
		$updated_data = array(
			'name' => 'Updated Test Board',
			'slug' => 'updated-test-board',
			'color' => '#0000ff',
			'show_in_boards' => false,
			'show_in_kb' => true,
		);

		$result = BoardManager::save_board( $updated_data, $this->test_board_id );

		$this->assertTrue( $result['success'] );

		// Verify the board was updated
		$term = get_term( $this->test_board_id, 'decker_board' );
		$this->assertEquals( 'Updated Test Board', $term->name );
		$this->assertEquals( 'updated-test-board', $term->slug );
		$this->assertEquals( '#0000ff', get_term_meta( $term->term_id, 'term-color', true ) );
		$this->assertEquals( '0', get_term_meta( $term->term_id, 'term-show-in-boards', true ) );
		$this->assertEquals( '1', get_term_meta( $term->term_id, 'term-show-in-kb', true ) );
	}

	public function test_delete_board() {
		wp_set_current_user( $this->editor );
		$result = BoardManager::delete_board( $this->test_board_id );

		$this->assertTrue( $result['success'] );
		$this->assertNull( get_term( $this->test_board_id, 'decker_board' ) );
	}

	public function test_get_nonexistent_board() {
		$this->assertNull( BoardManager::get_board_by_slug( 'nonexisting-board' ) );
	}
}
