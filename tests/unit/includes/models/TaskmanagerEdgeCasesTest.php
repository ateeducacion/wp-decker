<?php
/**
 * Edge case tests for TaskManager.
 *
 * @package Decker
 */

/**
 * Test TaskManager behavior with malformed, duplicate, and ambiguous metadata.
 */
class DeckerTaskManagerEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Task manager instance.
	 *
	 * @var TaskManager
	 */
	private $task_manager;

	/**
	 * Editor used as the current user.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Board used by task fixtures.
	 *
	 * @var WP_Term
	 */
	private $board;

	/**
	 * Set up the test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'init' );

		$this->task_manager = new TaskManager();
		$this->editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( 1 );
		$this->board = self::factory()->board->create_and_get(
			array(
				'name' => 'Edge Case Board',
				'slug' => 'edge-case-board',
			)
		);

		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Ensure serialized LIKE matching does not return another user's task.
	 */
	public function test_get_tasks_by_user_filters_serialized_id_false_positives() {
		$target_user_id = 1;
		$other_user_id  = 0;

		while ( false === strpos( (string) $other_user_id, (string) $target_user_id ) ) {
			$other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}

		$task_id = self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'responsable'    => $this->editor_id,
				'assigned_users' => array( $other_user_id ),
			)
		);

		$this->assertNotWPError( $task_id );
		$this->assertSame( array(), $this->task_manager->get_tasks_by_user( $target_user_id ) );
	}

	/**
	 * Ensure a responsible user receives a task without being assigned explicitly.
	 */
	public function test_get_tasks_by_user_includes_responsable_only_task() {
		$responsable_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$task_id        = self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'responsable'    => $responsable_id,
				'assigned_users' => array(),
			)
		);

		$tasks = $this->task_manager->get_tasks_by_user( $responsable_id );

		$this->assertCount( 1, $tasks );
		$this->assertSame( $task_id, $tasks[0]->ID );
	}

	/**
	 * Ensure malformed relations do not produce a task match.
	 */
	public function test_marked_tasks_ignore_malformed_relations() {
		$task_id = self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array( 'user_id' => $this->editor_id ),
				array( 'date' => wp_date( 'Y-m-d' ) ),
				array(
					'user_id' => $this->editor_id,
					'date'    => 'not-a-date',
				),
				'not-an-array',
			)
		);

		$tasks = $this->task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$this->editor_id,
			7
		);

		$this->assertSame( array(), $tasks );
	}

	/**
	 * Ensure duplicate dates from multiple tasks are returned only once.
	 */
	public function test_get_user_task_dates_removes_duplicates_across_tasks() {
		$yesterday = ( new DateTime( 'yesterday' ) )->format( 'Y-m-d' );

		for ( $index = 0; $index < 2; ++$index ) {
			$task_id = self::factory()->task->create(
				array(
					'board'          => $this->board->term_id,
					'assigned_users' => array( $this->editor_id ),
				)
			);

			update_post_meta(
				$task_id,
				'_user_date_relations',
				array(
					array(
						'user_id' => $this->editor_id,
						'date'    => $yesterday,
					),
				)
			);
		}

		$this->assertSame( array( $yesterday ), $this->task_manager->get_user_task_dates( $this->editor_id ) );
	}

	/**
	 * Ensure invalid, current, future, and expired dates are excluded.
	 */
	public function test_get_user_task_dates_excludes_out_of_range_and_invalid_dates() {
		$valid_date   = ( new DateTime( '-2 days' ) )->format( 'Y-m-d' );
		$expired_date = ( new DateTime( '-8 days' ) )->format( 'Y-m-d' );
		$today        = ( new DateTime() )->format( 'Y-m-d' );
		$tomorrow     = ( new DateTime( '+1 day' ) )->format( 'Y-m-d' );
		$another_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$task_id      = self::factory()->task->create(
			array(
				'board'          => $this->board->term_id,
				'assigned_users' => array( $this->editor_id ),
			)
		);

		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor_id,
					'date'    => $valid_date,
				),
				array(
					'user_id' => $this->editor_id,
					'date'    => $expired_date,
				),
				array(
					'user_id' => $this->editor_id,
					'date'    => $today,
				),
				array(
					'user_id' => $this->editor_id,
					'date'    => $tomorrow,
				),
				array(
					'user_id' => $this->editor_id,
					'date'    => 'invalid',
				),
				array(
					'user_id' => $another_user,
					'date'    => ( new DateTime( '-1 day' ) )->format( 'Y-m-d' ),
				),
			)
		);

		$this->assertSame( array( $valid_date ), $this->task_manager->get_user_task_dates( $this->editor_id ) );
	}

	/**
	 * Ensure an empty or sanitization-only stack list produces no counts.
	 */
	public function test_board_task_counts_return_empty_for_invalid_stack_input() {
		$this->assertSame( array(), $this->task_manager->get_board_task_counts_by_stack( array() ) );
		$this->assertSame(
			array(),
			$this->task_manager->get_board_task_counts_by_stack( array( '', '@@@', '   ' ) )
		);
	}

	/**
	 * Ensure duplicate stack input does not multiply aggregate counts.
	 */
	public function test_board_task_counts_deduplicate_stack_filters() {
		self::factory()->task->create(
			array(
				'board' => $this->board->term_id,
				'stack' => 'to-do',
			)
		);

		$counts = $this->task_manager->get_board_task_counts_by_stack(
			array( 'to-do', 'to-do', 'TO DO' )
		);

		$this->assertSame( 1, $counts[ $this->board->slug ]['to-do'] );
	}
}
