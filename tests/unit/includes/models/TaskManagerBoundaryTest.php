<?php
/**
 * Additional edge case tests for TaskManager.
 *
 * @package Decker
 */

/**
 * Supplementary boundary coverage for TaskManager.
 */
class TaskManagerBoundaryTest extends Decker_Test_Base {

	/**
	 * Task manager instance.
	 *
	 * @var TaskManager
	 */
	private $task_manager;

	/**
	 * Board fixture.
	 *
	 * @var WP_Term
	 */
	private $board;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up fixtures.
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

	/**
	 * Return a Task value object for an unresolved positive ID without throwing.
	 */
	public function test_get_task_with_nonexistent_id_returns_empty_task() {
		$result = $this->task_manager->get_task( 999999 );

		$this->assertInstanceOf( Task::class, $result );
		$this->assertSame( 0, $result->ID );
	}

	/**
	 * Return no tasks for an empty stack value.
	 */
	public function test_get_tasks_by_stack_returns_empty_for_empty_string() {
		self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
				'stack' => 'to-do',
			)
		);

		$this->assertSame( array(), $this->task_manager->get_tasks_by_stack( '' ) );
	}

	/**
	 * Return no tasks for an unknown stack value.
	 */
	public function test_get_tasks_by_stack_returns_empty_for_unknown_stack() {
		self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
				'stack' => 'to-do',
			)
		);

		$this->assertSame(
			array(),
			$this->task_manager->get_tasks_by_stack( 'non-existent-stack' )
		);
	}

	/**
	 * Return no tasks for an empty board.
	 */
	public function test_get_tasks_by_board_returns_empty_when_no_tasks() {
		wp_set_current_user( 1 );
		self::factory()->board->create(
			array(
				'name' => 'Empty Board',
				'slug' => 'empty-board',
			)
		);
		wp_set_current_user( $this->editor_id );

		$board = BoardManager::get_board_by_slug( 'empty-board' );

		$this->assertNotNull( $board );
		$this->assertSame( array(), $this->task_manager->get_tasks_by_board( $board ) );
	}

	/**
	 * Return no tasks for user ID zero.
	 */
	public function test_get_tasks_by_user_returns_empty_for_user_id_zero() {
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		$this->assertSame( array(), $this->task_manager->get_tasks_by_user( 0 ) );
	}

	/**
	 * Return no tasks for a missing user.
	 */
	public function test_get_tasks_by_user_returns_empty_for_nonexistent_user() {
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		$this->assertSame( array(), $this->task_manager->get_tasks_by_user( 999999 ) );
	}

	/**
	 * Return no tasks for an inverted date range.
	 */
	public function test_get_upcoming_tasks_by_date_returns_empty_for_inverted_range() {
		$task_id = self::factory()->task->create(
			array( 'board' => $this->board->term_id )
		);
		update_post_meta( $task_id, 'duedate', ( new DateTime( '+1 day' ) )->format( 'Y-m-d' ) );

		$result = $this->task_manager->get_upcoming_tasks_by_date(
			new DateTime( '+5 days' ),
			new DateTime( '-5 days' )
		);

		$this->assertSame( array(), $result );
	}

	/**
	 * Include a task whose due date equals both range boundaries.
	 */
	public function test_get_upcoming_tasks_by_date_includes_task_on_boundary_date() {
		$boundary = new DateTime( '+1 day' );
		$task_id  = self::factory()->task->create(
			array( 'board' => $this->board->term_id )
		);
		update_post_meta( $task_id, 'duedate', $boundary->format( 'Y-m-d' ) );

		$tasks = $this->task_manager->get_upcoming_tasks_by_date( $boundary, $boundary );
		$ids   = wp_list_pluck( $tasks, 'ID' );

		$this->assertContains( $task_id, $ids );
	}

	/**
	 * Return null when the user has no task-date history.
	 */
	public function test_get_latest_user_task_date_returns_null_for_user_with_no_tasks() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertNull( $this->task_manager->get_latest_user_task_date( $user_id ) );
	}

	/**
	 * Return false when there is no authenticated user.
	 */
	public function test_has_user_today_tasks_returns_false_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->task_manager->has_user_today_tasks() );
	}

	/**
	 * Always return an array for an unknown post status.
	 *
	 * WordPress may normalize an unregistered status internally, so this test
	 * verifies the public return type rather than assuming an empty query.
	 */
	public function test_get_tasks_by_status_handles_unknown_status() {
		self::factory()->task->create(
			array(
				'board'       => $this->board->term_id,
				'post_status' => 'publish',
			)
		);

		$result = $this->task_manager->get_tasks_by_status( 'this-status-does-not-exist' );

		$this->assertIsArray( $result );
	}

	/**
	 * Return no dates when the user has no date relations.
	 */
	public function test_get_user_task_dates_returns_empty_when_no_relations() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $user_id ),
			)
		);

		$this->assertSame( array(), $this->task_manager->get_user_task_dates( $user_id ) );
	}
}
