<?php
/**
 * Class Test_Decker_Taxonomies
 *
 * @package Decker
 */

class Test_Decker_Taxonomies extends WP_UnitTestCase {
	protected $post_type = 'decker_task';

	public function set_up() {
		parent::set_up();

		// Ensure taxonomies are registered
		do_action( 'init' );
	}

	public function test_taxonomies_exist() {
		$this->assertTrue( taxonomy_exists( 'decker_label' ) );
		$this->assertTrue( taxonomy_exists( 'decker_board' ) );
	}

	public function test_taxonomy_relationships() {
		$label_object = get_taxonomy( 'decker_label' );
		$board_object = get_taxonomy( 'decker_board' );

		$this->assertTrue( in_array( $this->post_type, $label_object->object_type ) );
		$this->assertTrue( in_array( $this->post_type, $board_object->object_type ) );
	}

	public function test_create_terms() {
		// Test Label taxonomy
		$label = wp_insert_term( 'Important', 'decker_label' );
		$this->assertNotWPError( $label );
		$this->assertIsArray( $label );

		// Test Board taxonomy
		$board = wp_insert_term( 'Sprint 1', 'decker_board' );
		$this->assertNotWPError( $board );
		$this->assertIsArray( $board );
	}

	public function test_assign_terms_to_task() {
		// Create a task
		$task_id = wp_insert_post(
			array(
				'post_title' => 'Test Task',
				'post_type' => $this->post_type,
				'post_status' => 'publish',
			)
		);

		// Create and assign terms
		$label = wp_insert_term( 'Priority', 'decker_label' );
		$board = wp_insert_term( 'Backlog', 'decker_board' );

		wp_set_object_terms( $task_id, $label['term_id'], 'decker_label' );
		wp_set_object_terms( $task_id, $board['term_id'], 'decker_board' );

		// Verify assignments
		$task_labels = wp_get_object_terms( $task_id, 'decker_label' );
		$task_boards = wp_get_object_terms( $task_id, 'decker_board' );

		$this->assertCount( 1, $task_labels );
		$this->assertCount( 1, $task_boards );
		$this->assertEquals( 'Priority', $task_labels[0]->name );
		$this->assertEquals( 'Backlog', $task_boards[0]->name );
	}
}
