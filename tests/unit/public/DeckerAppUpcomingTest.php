<?php
/**
 * Characterization tests for the Upcoming Tasks page template.
 *
 * The page buckets tasks into delayed / today / tomorrow / next-7-days by due
 * date. These pin the bucket boundaries, the counters and the exclusion of
 * hidden tasks and of tasks that fall outside the queried window.
 *
 * @package Decker
 */

class DeckerAppUpcomingTest extends Decker_Test_Base {

	/**
	 * Editor used as the current user.
	 *
	 * @var int
	 */
	protected $editor;

	/**
	 * Board the fixtures are attached to.
	 *
	 * @var WP_Term
	 */
	protected $board;

	/**
	 * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		wp_set_current_user( 1 );
		$this->board = self::factory()->board->create_and_get(
			array(
				'name' => 'Upcoming Board',
				'slug' => 'upcoming-board',
			)
		);

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Render the Upcoming page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_upcoming_page(): string {
		set_query_var( 'decker_page', 'upcoming' );

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-upcoming.php';
		return ob_get_clean();
	}

	/**
	 * Create a task on the fixture board with a due date.
	 *
	 * @param string $title    Task title.
	 * @param string $modifier Relative modifier for the due date.
	 * @param array  $extra    Extra task factory arguments.
	 * @return int The task ID.
	 */
	private function create_task_due( string $title, string $modifier, array $extra = array() ): int {
		return self::factory()->task->create(
			array_merge(
				array(
					'post_title' => $title,
					'board'      => $this->board->term_id,
					'duedate'    => ( new DateTime( $modifier ) )->format( 'Y-m-d' ),
				),
				$extra
			)
		);
	}

	/**
	 * Each due date lands in the matching bucket and the counters follow.
	 */
	public function test_tasks_are_bucketed_by_due_date() {
		$delayed_id  = $this->create_task_due( 'Overdue Item', '-2 days' );
		$today_id    = $this->create_task_due( 'Today Item', 'today' );
		$tomorrow_id = $this->create_task_due( 'Tomorrow Item', '+1 day' );
		$week_id     = $this->create_task_due( 'This Week Item', '+3 days' );

		$output = $this->render_upcoming_page();

		$this->assertStringContainsString( 'DELAYED (1)', $output );
		$this->assertStringContainsString( 'Today (1)', $output );
		$this->assertStringContainsString( 'Tomorrow (1)', $output );
		$this->assertStringContainsString( 'Next 7 Days (1)', $output );

		foreach ( array( $delayed_id, $today_id, $tomorrow_id, $week_id ) as $task_id ) {
			$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $output );
		}
	}

	/**
	 * A task due beyond the seven-day window is queried but lands in no bucket.
	 */
	public function test_task_due_beyond_the_window_is_not_bucketed() {
		$far_id = $this->create_task_due( 'Far Future Item', '+30 days' );

		$output = $this->render_upcoming_page();

		$this->assertStringContainsString( 'DELAYED (0)', $output );
		$this->assertStringContainsString( 'Today (0)', $output );
		$this->assertStringContainsString( 'Tomorrow (0)', $output );
		$this->assertStringContainsString( 'Next 7 Days (0)', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $far_id . '"', $output );
	}

	/**
	 * Hidden tasks are excluded from the upcoming query entirely.
	 */
	public function test_hidden_tasks_are_excluded() {
		$visible_id = $this->create_task_due( 'Visible Soon', 'today' );
		$hidden_id  = $this->create_task_due( 'Hidden Soon', 'today', array( 'hidden' => true ) );

		$output = $this->render_upcoming_page();

		$this->assertStringContainsString( 'data-task-id="' . $visible_id . '"', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $hidden_id . '"', $output );
		$this->assertStringContainsString( 'Today (1)', $output );
	}

	/**
	 * The user filter dropdown lists every user by display name.
	 */
	public function test_user_filter_lists_every_user() {
		self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ursula Upcoming',
			)
		);

		$output = $this->render_upcoming_page();

		$this->assertStringContainsString( 'id="boardUserFilter"', $output );
		$this->assertStringContainsString( '<option value="Ursula Upcoming">Ursula Upcoming</option>', $output );
	}
}
