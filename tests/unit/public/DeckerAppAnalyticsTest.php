<?php
/**
 * Characterization tests for the Analytics dashboard template.
 *
 * The page renders four headline counters plus four Chart.js datasets built
 * server-side and emitted as JSON. These pin the counters and the shape and
 * values of the JSON the charts consume.
 *
 * @package Decker
 */

class DeckerAppAnalyticsTest extends Decker_Test_Base {

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
				'name'  => 'Analytics Board',
				'slug'  => 'analytics-board',
				'color' => '#0099ff',
			)
		);
		BoardManager::reset_instance();

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ana Analytics',
			)
		);
		wp_set_current_user( $this->editor );
	}

	/**
	 * Render the Analytics page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_analytics_page(): string {
		set_query_var( 'decker_page', 'analytics' );

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-analytics.php';
		return ob_get_clean();
	}

	/**
	 * Create a task on the fixture board.
	 *
	 * @param array $args Overrides for the task factory.
	 * @return int The task ID.
	 */
	private function create_task( array $args = array() ): int {
		return self::factory()->task->create(
			array_merge(
				array( 'board' => $this->board->term_id ),
				$args
			)
		);
	}

	/**
	 * The headline counters report published and archived tasks separately.
	 */
	public function test_headline_counters_split_active_and_archived_tasks() {
		$this->create_task( array( 'post_title' => 'Active One' ) );
		$this->create_task( array( 'post_title' => 'Active Two' ) );

		$archived_id = $this->create_task( array( 'post_title' => 'Archived One' ) );
		wp_update_post(
			array(
				'ID'          => $archived_id,
				'post_status' => 'archived',
			)
		);

		$output = $this->render_analytics_page();

		$this->assertStringContainsString( 'id="active-tasks-count">2<', $output );
		$this->assertStringContainsString( 'id="archived-tasks-count">1<', $output );
		$this->assertStringContainsString( 'id="total-boards-count">1<', $output );
		$this->assertStringContainsString( 'id="total-users-count">', $output );
	}

	/**
	 * The per-board dataset counts each stack for every board.
	 */
	public function test_tasks_by_board_dataset_counts_each_stack() {
		$this->create_task(
			array(
				'post_title' => 'Board Todo',
				'stack'      => 'to-do',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Board Progress A',
				'stack'      => 'in-progress',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Board Progress B',
				'stack'      => 'in-progress',
			)
		);

		$output = $this->render_analytics_page();

		// Labels and per-stack series for the single board.
		$this->assertStringContainsString( 'labels: ["Analytics Board"]', $output );
		$this->assertStringContainsString( 'data: [1],', $output );
		$this->assertStringContainsString( 'data: [2],', $output );

		// The done series is present and empty for this board.
		$this->assertStringContainsString( 'data: [0],', $output );
	}

	/**
	 * The per-stack dataset totals every task regardless of board.
	 */
	public function test_tasks_by_stack_dataset_totals_all_tasks() {
		$this->create_task(
			array(
				'post_title' => 'Stack Todo',
				'stack'      => 'to-do',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Stack Done A',
				'stack'      => 'done',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Stack Done B',
				'stack'      => 'done',
			)
		);

		$output = $this->render_analytics_page();

		// to-do, in-progress, done in that order.
		$this->assertStringContainsString( wp_json_encode( array( 1, 0, 2 ) ), $output );
	}

	/**
	 * The current-user chart only counts tasks the user is assigned to.
	 */
	public function test_current_user_dataset_counts_only_assigned_tasks() {
		$this->create_task(
			array(
				'post_title'     => 'Mine',
				'stack'          => 'in-progress',
				'assigned_users' => array( $this->editor ),
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Not Mine',
				'stack'      => 'in-progress',
			)
		);

		$output = $this->render_analytics_page();

		// The user labels list includes the editor's display name.
		$this->assertStringContainsString( 'Ana Analytics', $output );

		// The current-user series counts a single in-progress task.
		$this->assertStringContainsString( wp_json_encode( array( 0, 1, 0 ) ), $output );
	}
}
