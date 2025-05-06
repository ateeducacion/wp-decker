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

	/**
	 * Test retrieving tasks by status.
	 */
	public function test_get_tasks_by_status() {
		// Create a published task
		$published_task_id = self::factory()->task->create(
			array(
				'post_status' => 'publish',
				'board' => $this->board->term_id,
			)
		);

		// Test published tasks
		$published_tasks = $this->task_manager->get_tasks_by_status( 'publish' );
		$this->assertIsArray( $published_tasks );
		$this->assertGreaterThanOrEqual( 1, count( $published_tasks ) );
		
		// Find our specific task in the results
		$found = false;
		foreach ( $published_tasks as $task ) {
			if ( $task->ID === $published_task_id ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Published task not found in results' );
	}

	/**
	 * Test checking if user has tasks for today.
	 */
	public function test_has_user_today_tasks() {
		// Create a task
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		// Assign the task to the current user
		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		// Initially, user should not have tasks marked for today
		$this->assertFalse( $this->task_manager->has_user_today_tasks() );

		// Add a user-date relation for today
		$today = ( new DateTime() )->format( 'Y-m-d' );
		$relations = array(
			array(
				'user_id' => $this->editor,
				'date'    => $today,
			),
		);
		update_post_meta( $task_id, '_user_date_relations', $relations );

		// Now user should have tasks for today
		$this->assertTrue( $this->task_manager->has_user_today_tasks() );
	}

	/**
	 * Test retrieving tasks marked for today for previous days.
	 */
	public function test_get_user_tasks_marked_for_today_for_previous_days() {
		// Create a task
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		// Assign the task to the current user
		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		// Add a user-date relation for yesterday
		$yesterday = ( new DateTime() )->modify( '-1 day' )->format( 'Y-m-d' );
		$relations = array(
			array(
				'user_id' => $this->editor,
				'date'    => $yesterday,
			),
		);
		update_post_meta( $task_id, '_user_date_relations', $relations );

		// Get tasks for the last 2 days
		$tasks = $this->task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$this->editor,
			2,
			true
		);

		$this->assertIsArray( $tasks );
		$this->assertCount( 1, $tasks );
		$this->assertEquals( $task_id, $tasks[0]->ID );

		// Test with specific date
		$specific_date = new DateTime( $yesterday );
		$tasks_specific_date = $this->task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$this->editor,
			0, // Days parameter should be ignored when specific_date is provided
			true,
			$specific_date
		);

		$this->assertIsArray( $tasks_specific_date );
		$this->assertCount( 1, $tasks_specific_date );
		$this->assertEquals( $task_id, $tasks_specific_date[0]->ID );
	}

	/**
	 * Test getting the latest date when a user marked tasks.
	 */
	public function test_get_latest_user_task_date() {
		// Create a task
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		// Assign the task to the current user
		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		// Initially, there should be no latest date
		$this->assertNull( $this->task_manager->get_latest_user_task_date( $this->editor ) );

		// Add user-date relations for different days
		$yesterday = ( new DateTime() )->modify( '-1 day' )->format( 'Y-m-d' );
		$two_days_ago = ( new DateTime() )->modify( '-2 days' )->format( 'Y-m-d' );
		
		$relations = array(
			array(
				'user_id' => $this->editor,
				'date'    => $yesterday,
			),
			array(
				'user_id' => $this->editor,
				'date'    => $two_days_ago,
			),
		);
		update_post_meta( $task_id, '_user_date_relations', $relations );

		// Get the latest date
		$latest_date = $this->task_manager->get_latest_user_task_date( $this->editor );

		$this->assertInstanceOf( DateTime::class, $latest_date );
		$this->assertEquals( $yesterday, $latest_date->format( 'Y-m-d' ) );
	}

	/**
	 * Test getting available dates when a user marked tasks in the past.
	 */
	public function test_get_user_task_dates() {
		// Create a task
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
			)
		);

		// Assign the task to the current user
		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		// Initially, there should be no dates
		$this->assertEmpty( $this->task_manager->get_user_task_dates( $this->editor ) );

		// Add user-date relations for different days
		$yesterday = ( new DateTime() )->modify( '-1 day' )->format( 'Y-m-d' );
		$two_days_ago = ( new DateTime() )->modify( '-2 days' )->format( 'Y-m-d' );
		$three_days_ago = ( new DateTime() )->modify( '-3 days' )->format( 'Y-m-d' );
		
		$relations = array(
			array(
				'user_id' => $this->editor,
				'date'    => $yesterday,
			),
			array(
				'user_id' => $this->editor,
				'date'    => $two_days_ago,
			),
			array(
				'user_id' => $this->editor,
				'date'    => $three_days_ago,
			),
		);
		update_post_meta( $task_id, '_user_date_relations', $relations );

		// Get dates with default max_days_back (7)
		$dates = $this->task_manager->get_user_task_dates( $this->editor );

		$this->assertIsArray( $dates );
		$this->assertCount( 3, $dates );
		$this->assertEquals( $yesterday, $dates[0] ); // Dates should be sorted newest first

		// Test with limited max_days_back
		$dates_limited = $this->task_manager->get_user_task_dates( $this->editor, 2 );
		
		$this->assertIsArray( $dates_limited );
		// Check that we have at least the yesterday date
		$this->assertContains( $yesterday, $dates_limited );
		// Check that three_days_ago is not included due to the 2-day limit
		$this->assertNotContains( $three_days_ago, $dates_limited );
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
