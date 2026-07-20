<?php
/**
 * Additional edge case tests for TaskManager.
 *
 * Exercises failure/boundary paths in TaskManager that are not yet covered
 * by DeckerTaskManagerTest or DeckerTaskManagerEdgeCasesTest.
 *
 * @package Decker
 */

/**
 * Supplementary edge-case coverage for TaskManager.
 */
class TaskManagerBoundaryTest extends Decker_Test_Base {

	/**
	 * TaskManager instance under test.
	 *
	 * @var TaskManager
	 */
	private $task_manager;

	/**
	 * Board used by task fixtures.
	 *
	 * @var WP_Term
	 */
	private $board;

	/**
	 * Editor user created for the test run.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up fixtures before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );

		$this->task_manager = new TaskManager();

		wp_set_current_user( 1 );
		$this->board = self::factory()->board->create_and_get(
			array(
				'name' => 'Boundary Board',
				'slug' => 'boundary-board',
			)
		);

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	// -----------------------------------------------------------------------
	// get_task – boundary IDs
	// -----------------------------------------------------------------------

	/**
	 * get_task() with a non-existent positive ID returns null without throwing.
	 */
	public function test_get_task_with_nonexistent_id_returns_null() {
		$result = $this->task_manager->get_task( 999999 );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// get_tasks_by_stack – empty / unknown stack
	// -----------------------------------------------------------------------

	/**
	 * An empty stack string returns an empty array.
	 */
	public function test_get_tasks_by_stack_returns_empty_for_empty_string() {
		self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
				'stack' => 'to-do',
			)
		);

		$result = $this->task_manager->get_tasks_by_stack( '' );

		$this->assertSame( array(), $result );
	}

	/**
	 * An unknown stack value that matches no task returns an empty array.
	 */
	public function test_get_tasks_by_stack_returns_empty_for_unknown_stack() {
		self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
				'stack' => 'to-do',
			)
		);

		$result = $this->task_manager->get_tasks_by_stack( 'non-existent-stack' );

		$this->assertSame( array(), $result );
	}

	// -----------------------------------------------------------------------
	// get_tasks_by_board – empty board
	// -----------------------------------------------------------------------

	/**
	 * A board that has no tasks associated returns an empty array.
	 */
	public function test_get_tasks_by_board_returns_empty_when_no_tasks() {
		wp_set_current_user( 1 );
		$empty_board = self::factory()->board->create_and_get(
			array(
				'name' => 'Empty Board',
				'slug' => 'empty-board',
			)
		);
		wp_set_current_user( $this->editor_id );

		$board_obj = BoardManager::get_board_by_slug( 'empty-board' );
		$this->assertNotNull( $board_obj );

		$result = $this->task_manager->get_tasks_by_board( $board_obj );

		$this->assertSame( array(), $result );
	}

	// -----------------------------------------------------------------------
	// get_tasks_by_user – zero / ghost user IDs
	// -----------------------------------------------------------------------

	/**
	 * Requesting tasks for user_id 0 returns an empty array (no tasks are
	 * ever assigned to user 0 in normal operation).
	 */
	public function test_get_tasks_by_user_returns_empty_for_user_id_zero() {
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		$result = $this->task_manager->get_tasks_by_user( 0 );

		$this->assertSame( array(), $result );
	}

	/**
	 * Requesting tasks for a non-existent user ID returns an empty array.
	 */
	public function test_get_tasks_by_user_returns_empty_for_nonexistent_user() {
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		$result = $this->task_manager->get_tasks_by_user( 999999 );

		$this->assertSame( array(), $result );
	}

	// -----------------------------------------------------------------------
	// get_upcoming_tasks_by_date – inverted range
	// -----------------------------------------------------------------------

	/**
	 * When $from is after $until the query range is effectively empty and no
	 * tasks are returned even if a matching task exists.
	 */
	public function test_get_upcoming_tasks_by_date_returns_empty_for_inverted_range() {
		$task_id  = self::factory()->task->create(
			array( 'board' => $this->board->term_id )
		);
		$due_date = ( new DateTime( '+1 day' ) )->format( 'Y-m-d' );
		update_post_meta( $task_id, 'duedate', $due_date );

		$from  = new DateTime( '+5 days' );
		$until = new DateTime( '-5 days' );

		$result = $this->task_manager->get_upcoming_tasks_by_date( $from, $until );

		$this->assertSame( array(), $result );
	}

	/**
	 * When $from equals $until only tasks due on that exact day are included.
	 */
	public function test_get_upcoming_tasks_by_date_includes_task_on_boundary_date() {
		$boundary = new DateTime( '+1 day' );

		$task_id = self::factory()->task->create(
			array( 'board' => $this->board->term_id )
		);
		update_post_meta( $task_id, 'duedate', $boundary->format( 'Y-m-d' ) );

		$result = $this->task_manager->get_upcoming_tasks_by_date( $boundary, $boundary );

		$ids = array_map( fn( $t ) => $t->ID, $result );
		$this->assertContains( $task_id, $ids );
	}

	// -----------------------------------------------------------------------
	// get_latest_user_task_date – no tasks at all
	// -----------------------------------------------------------------------

	/**
	 * A user who has never had any tasks returns null from get_latest_user_task_date().
	 */
	public function test_get_latest_user_task_date_returns_null_for_user_with_no_tasks() {
		$new_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->task_manager->get_latest_user_task_date( $new_user );

		$this->assertNull( $result );
	}

	// -----------------------------------------------------------------------
	// has_user_today_tasks – no user set
	// -----------------------------------------------------------------------

	/**
	 * When no user is logged in, has_user_today_tasks() returns false.
	 */
	public function test_has_user_today_tasks_returns_false_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->task_manager->has_user_today_tasks() );
	}

	// -----------------------------------------------------------------------
	// get_tasks_by_status – unknown status
	// -----------------------------------------------------------------------

	/**
	 * An unknown post status returns an empty array without errors.
	 */
	public function test_get_tasks_by_status_returns_empty_for_unknown_status() {
		self::factory()->task->create(
			array(
				'board'       => $this->board->term_id,
				'post_status' => 'publish',
			)
		);

		$result = $this->task_manager->get_tasks_by_status( 'this-status-does-not-exist' );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result );
	}

	// -----------------------------------------------------------------------
	// get_user_task_dates – empty result for user with no date relations
	// -----------------------------------------------------------------------

	/**
	 * A user who has tasks but no _user_date_relations meta returns an empty
	 * array from get_user_task_dates().
	 */
	public function test_get_user_task_dates_returns_empty_when_no_relations() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $user_id ),
			)
		);

		$result = $this->task_manager->get_user_task_dates( $user_id );

		$this->assertSame( array(), $result );
	}
}
