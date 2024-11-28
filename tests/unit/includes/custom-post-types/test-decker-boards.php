<?php
/**
 * Class Test_Decker_Boards
 *
 * @package Decker
 */


class Test_Decker_Boards extends WP_UnitTestCase {

	protected $post_type = 'decker_task';

	public function set_up() {
		parent::set_up();

		// Ensure taxonomies are registered
		do_action( 'init' );
	}

	public function test_taxonomies_exist() {
		$this->assertTrue( taxonomy_exists( 'decker_board' ) );
	}

	public function test_taxonomy_relationships() {
		$board_object = get_taxonomy( 'decker_board' );

		$this->assertTrue( in_array( $this->post_type, $board_object->object_type ) );
	}

	public function test_create_terms() {
		// Set up nonces
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Test Board taxonomy
		$board = wp_insert_term( 'Sprint 1', 'decker_board' );
		$this->assertNotWPError( $board );
		$this->assertIsArray( $board );

		// Clean up nonces
		unset( $_POST['decker_term_nonce'] );
	}

	public function test_assign_terms_to_task() {
		// Set up nonces
		$_POST['decker_task_nonce'] = wp_create_nonce( 'decker_task_action' );
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );

		// Create a task
		$task_id = wp_insert_post(
			array(
				'post_title' => 'Test Task',
				'post_type' => $this->post_type,
				'post_status' => 'publish',
			)
		);

		// Create and assign terms
		$board = wp_insert_term( 'Backlog', 'decker_board' );

		wp_set_object_terms( $task_id, $board['term_id'], 'decker_board' );

		// Verify assignments
		$task_boards = wp_get_object_terms( $task_id, 'decker_board' );

		$this->assertCount( 1, $task_boards );
		$this->assertEquals( 'Backlog', $task_boards[0]->name );

		// Clean up nonces
		unset( $_POST['decker_task_nonce'] );
		unset( $_POST['decker_term_nonce'] );

	}
}
