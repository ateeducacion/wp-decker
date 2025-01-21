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

	public function testEditingTitleShouldNotChangeBoard() {
		// Update only the title using the factory
		self::factory()->task->update_object(
			$this->testTaskId,
			array(
				'post_title' => 'Updated Title',
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

		// Get the current board ID
		$currentBoardId = wp_get_post_terms( $this->testTaskId, 'decker_board' )[0]->term_id;

		$this->assertEquals(
			$this->originalBoardId,
			$currentBoardId,
			'Board should not change when only updating title'
		);
	}

	public function testEditingDescriptionShouldNotChangeBoard() {
		// Update only the description using the factory
		self::factory()->task->update_object(
			$this->testTaskId,
			array(
				'post_title' => 'Original Title',
				'post_content' => 'Updated Description',
				'meta_input' => array(
					'stack' => 'to-do',
					'max_priority' => false,
				),
				'tax_input' => array(
					'decker_board' => array( $this->originalBoardId ),
				),
			)
		);

		// Get the current board ID
		$currentBoardId = wp_get_post_terms( $this->testTaskId, 'decker_board' )[0]->term_id;

		$this->assertEquals(
			$this->originalBoardId,
			$currentBoardId,
			'Board should not change when only updating description'
		);
	}

	public function testExplicitlyChangingBoardShouldWork() {
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

		// Get the current board ID
		$currentBoardId = wp_get_post_terms( $this->testTaskId, 'decker_board' )[0]->term_id;

		$this->assertEquals(
			$this->newBoardId,
			$currentBoardId,
			'Board should change when explicitly updated'
		);
	}
}
