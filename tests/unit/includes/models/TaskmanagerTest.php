<?php
/**
 * Class Test_Decker_TaskManager
 *
 * @package Decker
 */

class DeckerTaskManagerTest extends WP_UnitTestCase {

	/**
	 * @var TaskManager
	 */
	protected $task_manager;

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

		$this->board = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'decker_board',
				'name'     => 'Test Board',
				'slug'     => 'test-board',
			)
		);
	}

	protected function create_test_task_with_board( string $stack = 'to-do' ): int {
		$task_id = Decker_Tasks::create_or_update_task(
			0, // Create a new task.
			'Test Task',
			'Test Task Description',
			$stack,
			$this->board->term_id,
			false,
			null,
			get_current_user_id(),
			array(),
			array(),
		);

		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		return $task_id;
	}

	/**
	 * Test retrieving a task by ID.
	 */
	public function test_get_task_by_id() {
		$task_id = $this->create_test_task_with_board();

		$task = $this->task_manager->get_task( $task_id );

		$this->assertInstanceOf( Task::class, $task );
		$this->assertEquals( 'Test Task', $task->title );
	}

	/**
	 * Test retrieving tasks by stack.
	 */
	public function test_get_tasks_by_stack() {
		$task_id_1 = $this->create_test_task_with_board( 'to-do' );
		$task_id_2 = $this->create_test_task_with_board( 'in-progress' );

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
		$task_id = $this->create_test_task_with_board();

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
		$task_id = $this->create_test_task_with_board();

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
		$task_id = $this->create_test_task_with_board();

		$from  = new DateTime( '-1 day' );
		$until = new DateTime( '+1 day' );

		update_post_meta( $task_id, 'duedate', $until->format( 'Y-m-d' ) );

		$tasks = $this->task_manager->get_upcoming_tasks_by_date( $from, $until );

		$this->assertIsArray( $tasks );
		$this->assertCount( 1, $tasks );
		$this->assertEquals( $task_id, $tasks[0]->ID );
	}
}
