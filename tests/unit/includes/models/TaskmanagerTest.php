<?php
/**
 * Class Test_Decker_TaskManager
 *
 * @package Decker
 */

class DeckerTaskManagerTest extends Decker_Test_Base {

	/**
	 * @var TaskManager
	 */
	protected $task_manager;

	private $created_tasks = array();
	/**
	 * @var int
	 */
	protected $editor;

	protected $board;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialize the TaskManager instance.
		$this->task_manager = new TaskManager();

		// Ensure the custom post type and taxonomy are registered.
		do_action( 'init' );

		// Create an editor user for testing.
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Create a board term for the tasks.
		wp_set_current_user( $this->editor );

		// switch to admin to create the board
		wp_set_current_user( 1 );

		$this->board = self::factory()->board->create_and_get(
			array(
				'name'     => 'Test Board',
				'slug'     => 'test-board',
			)
		);
		$this->assertInstanceOf( 'WP_Term', $this->board, 'Board factory failed, expected WP_Term instance' );

		// back to editor for the rest of the tests
		wp_set_current_user( $this->editor );
	}

	/**
	 * Test retrieving a task by ID.
	 */
	public function test_get_task_by_id() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Test Task',
				'board' => $this->board->term_id,
			)
		);

		$task = $this->task_manager->get_task( $task_id );

		$this->assertInstanceOf( Task::class, $task );
		$this->assertEquals( 'Test Task', $task->title );
	}

	/**
	 * Test retrieving tasks by stack.
	 */
	public function test_get_tasks_by_stack() {

		$task_id_1 = self::factory()->task->create(
			array(
				'stack' => 'to-do',
				'board' => $this->board->term_id,
			)
		);
		$task_id_2 = self::factory()->task->create(
			array(
				'stack' => 'in-progress',
				'board' => $this->board->term_id,
			)
		);

		$tasks_to_do = $this->task_manager->get_tasks_by_stack( 'to-do' );

		$this->assertIsArray( $tasks_to_do );
		$this->assertCount( 1, $tasks_to_do );
		$this->assertEquals( $task_id_1, $tasks_to_do[0]->ID );

		$tasks_in_progress = $this->task_manager->get_tasks_by_stack( 'in-progress' );

		$this->assertIsArray( $tasks_in_progress );
		$this->assertCount( 1, $tasks_in_progress );
		$this->assertEquals( $task_id_2, $tasks_in_progress[0]->ID );
	}

	/**
	 * Test retrieving tasks by board.
	 */
	public function test_get_tasks_by_board() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		$board = BoardManager::get_board_by_slug( $this->board->slug );

		$tasks = $this->task_manager->get_tasks_by_board( $board );

		$this->assertIsArray( $tasks );
		$this->assertCount( 1, $tasks );
		$this->assertEquals( $task_id, $tasks[0]->ID );
	}

	/**
	 * Test retrieving tasks assigned to a specific user.
	 */
	public function test_get_tasks_by_user() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		$tasks = $this->task_manager->get_tasks_by_user( $this->editor );

		$this->assertIsArray( $tasks );
		$this->assertCount( 1, $tasks );
		$this->assertEquals( $task_id, $tasks[0]->ID );
	}

	/**
	 * Test retrieving upcoming tasks by date.
	 */
	public function test_get_upcoming_tasks_by_date() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		$from  = new DateTime( '-1 day' );
		$until = new DateTime( '+1 day' );

		update_post_meta( $task_id, 'duedate', $until->format( 'Y-m-d' ) );

		$tasks = $this->task_manager->get_upcoming_tasks_by_date( $from, $until );

		$this->assertIsArray( $tasks );
		$this->assertCount( 1, $tasks );
		$this->assertEquals( $task_id, $tasks[0]->ID );
	}


	public function tear_down() {

		foreach ( $this->created_tasks as $post_id ) {
			wp_delete_post( $post_id, true ); // true to delete permanently
		}

		// Clean array for next test
		$this->created_tasks = array();

		parent::tear_down();
	}
}
