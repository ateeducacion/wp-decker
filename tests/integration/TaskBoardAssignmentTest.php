<?php

namespace Decker\Tests\Integration;

use Decker_Tasks;
use Decker_Test_Base;

/**
 * Test class for verifying task board assignment behavior
 */
class TaskBoardAssignmentTest extends Decker_Test_Base {

	private $taskManager;
	private $testTaskId;
	private $originalBoardId;
	private $newBoardId;

	protected function setUp(): void {
		parent::setUp();

		// Create a user and set as current user.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// Create test boards using the factory
		$this->originalBoardId = self::factory()->board->create( array( 'name' => 'Test Board 1' ) );
		$this->newBoardId = self::factory()->board->create( array( 'name' => 'Test Board 2' ) );

		// Create a test task using the factory
		$this->testTaskId = self::factory()->task->create(
			array(
				'post_title' => 'Original Title',
				'post_content' => 'Original Description',
				'meta_input' => array(
					'stack' => 'to-do',
					'max_priority' => false,
				),
				'tax_input' => array(
					'decker_board' => array( $this->originalBoardId ),
				),
			)
		);
	}

	protected function tearDown(): void {
		// Clean up test data
		wp_delete_post( $this->testTaskId, true );
		wp_delete_term( $this->originalBoardId, 'decker_board' );
		wp_delete_term( $this->newBoardId, 'decker_board' );

		parent::tearDown();
	}

	public function testExplicitlyChangingBoardShouldWork() {
		// Create three tasks in the original board's to-do stack
		for ( $i = 1; $i <= 3; $i++ ) {
			self::factory()->task->create(
				array(
					'post_title' => "Task $i",
					'meta_input' => array(
						'stack' => 'to-do',
						'max_priority' => false,
					),
					'tax_input' => array(
						'decker_board' => array( $this->originalBoardId ),
					),
				)
			);
		}

		// Create two tasks in the new board's to-do stack
		for ( $i = 1; $i <= 2; $i++ ) {
			self::factory()->task->create(
				array(
					'post_title' => "New Board Task $i",
					'meta_input' => array(
						'stack' => 'to-do',
						'max_priority' => false,
					),
					'tax_input' => array(
						'decker_board' => array( $this->newBoardId ),
					),
				)
			);
		}

		// Update the board explicitly using the factory
		self::factory()->task->update_object(
			$this->testTaskId,
			array(
				'post_title' => 'Original Title',
				'post_content' => 'Original Description',
				'meta_input' => array(
					'stack' => 'to-do',
					'max_priority' => false,
				),
				'tax_input' => array(
					'decker_board' => array( $this->newBoardId ),
				),
			)
		);

		// Verify initial menu_order is 1 (first task in original board)
		$initial_menu_order = get_post_field( 'menu_order', $this->testTaskId );
		$this->assertEquals( 1, $initial_menu_order, 'Initial task should have menu_order 1' );

		// Get the current board ID and verify board changed
		$currentBoardId = wp_get_post_terms( $this->testTaskId, 'decker_board' )[0]->term_id;
		$this->assertEquals(
			$this->newBoardId,
			$currentBoardId,
			'Board should change when explicitly updated'
		);

		// Get all tasks in the new board's to-do stack
		$tasks = get_posts(
			array(
				'post_type' => 'decker_task',
				'orderby' => 'menu_order',
				'order' => 'ASC',
				'tax_query' => array(
					array(
						'taxonomy' => 'decker_board',
						'field' => 'term_id',
						'terms' => $this->newBoardId,
					),
				),
				'meta_query' => array(
					array(
						'key' => 'stack',
						'value' => 'to-do',
						'compare' => '=',
					),
				),
				'posts_per_page' => -1,
				'fields' => 'ids',
			)
		);

		// Verify the moved task is the last one in the list
		$this->assertEquals(
			$this->testTaskId,
			end( $tasks ),
			'Task should be placed at the end of the stack in the new board'
		);

		// Verify final menu_order is 3 (after 2 existing tasks in new board)
		$final_menu_order = get_post_field( 'menu_order', $this->testTaskId );
		$this->assertEquals( 3, $final_menu_order, 'Task should have menu_order 3 after moving to new board' );
	}
}
