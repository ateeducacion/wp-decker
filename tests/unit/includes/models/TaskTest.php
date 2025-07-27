<?php
/**
 * Class Test_Decker_Task
 *
 * @package Decker
 */


class DeckerTaskTest extends Decker_Test_Base {

	private $editor;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->created_tasks = array();
		$this->created_users = array();

		// Create an editor user
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Set current user as editor right away
		wp_set_current_user( $this->editor );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		parent::tear_down();
	}

	/**
	 * Test task creation with valid data.
	 */
	public function test_task_creation() {
		$task_id = self::factory()->task->create(
			array(
				'post_title'   => 'Test Task',
				'post_content' => 'This is a test task description.',
			)
		);

		$this->assertNotEmpty( $task_id, 'Task ID should not be empty.' );

		$task = new Task( $task_id );
		$this->assertInstanceOf( Task::class, $task, 'Task should be an instance of the Task class.' );
		$this->assertEquals( 'Test Task', $task->title, 'Task title does not match.' );
		$this->assertEquals( 'This is a test task description.', $task->description, 'Task description does not match.' );
	}

	/**
	 * Test metadata retrieval for a task.
	 */
	public function test_task_metadata() {
		$task_id = self::factory()->task->create(
			array(
				'stack'        => 'in-progress',
				'max_priority' => true,
			)
		);

		$task = new Task( $task_id );
		$this->assertEquals( 'in-progress', $task->stack, 'Stack metadata does not match.' );
		$this->assertTrue( $task->max_priority, 'Max priority metadata does not match.' );
	}

	/**
	 * Test task duedate.
	 */
	public function test_task_duedate() {
		$duedate = '2024-12-31 00:00:00';

		$task_id = self::factory()->task->create(
			array(
				'duedate' => $duedate,
			)
		);

		$task = new Task( $task_id );
		$this->assertInstanceOf( DateTime::class, $task->duedate, 'Duedate should be an instance of DateTime.' );
		$this->assertEquals( $duedate, $task->duedate->format( 'Y-m-d H:i:s' ), 'Duedate does not match the expected value.' );
	}

	/**
	 * Test task author.
	 */
	public function test_task_author() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$task_id = self::factory()->task->create(
			array(
				'author' => $user_id,
			)
		);

		$task = new Task( $task_id );
		$this->assertEquals( $user_id, $task->author, 'Task author does not match the expected value.' );
	}


	/**
	 * Test task board and labels.
	 */
	public function test_task_labels() {

		$board_id = self::factory()->board->create();

		$label_ids = array(
			self::factory()->label->create(),
			self::factory()->label->create(),
		);

		$task_id = self::factory()->task->create(
			array(
				'board'  => $board_id,
				'labels' => $label_ids,
			)
		);

		$task = new Task( $task_id );

		// Check board.
		$this->assertNotNull( $task->board, 'Task board should not be null.' );
		$this->assertEquals( $board_id, $task->board->id, 'Task board ID does not match.' );

		// Check labels.
		$label_ids_from_task = array_map(
			function ( $label ) {
				return $label->id;
			},
			$task->labels
		);

		foreach ( $label_ids as $label_id ) {
			$this->assertContains( $label_id, $label_ids_from_task, 'Task is missing an expected label.' );
		}
	}

	/**
	 * Test task attachments.
	 */
	public function test_task_attachments() {

		$task_id       = self::factory()->task->create();
		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/../../../fixtures/sample-1.pdf', 0 );

		update_post_meta( $task_id, 'attachments', array( $attachment_id ) );

		$task = new Task( $task_id );

		$this->assertCount( 1, $task->attachments, 'Task should have exactly one attachment.' );
		// TO-DO: Better test here
		// $this->assertEquals( $attachment_id, $task->attachments[0], 'Attachment ID does not match.' );
	}
}
