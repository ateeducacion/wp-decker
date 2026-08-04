<?php
/**
 * Characterization tests for the board and "my board" kanban templates.
 *
 * Both pages split a task collection into the three stack columns and render
 * every task through Decker_Task_Card_Renderer. These pin the column split,
 * the per-column counters, the card payloads kanban.js drags around and the
 * hard failure when the requested board slug does not exist.
 *
 * @package Decker
 */

class DeckerAppKanbanTest extends Decker_Test_Base {

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
				'name'  => 'Sprint Board',
				'slug'  => 'sprint-board',
				'color' => '#654321',
			)
		);
		BoardManager::reset_instance();

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Kai Kanban',
			)
		);
		wp_set_current_user( $this->editor );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		set_query_var( 'slug', '' );
		parent::tear_down();
	}

	/**
	 * Render the board kanban page into a string.
	 *
	 * @param string $slug Board slug to render.
	 * @return string The captured page output.
	 */
	private function render_board_page( string $slug ): string {
		set_query_var( 'decker_page', 'board' );
		set_query_var( 'slug', $slug );

		// The board lookup reads through the cached manager, which survives
		// across test classes; drop the cache so the fixtures show up.
		BoardManager::reset_instance();

		// wp_die() throws out of the include, so unwind the buffer either way.
		$level = ob_get_level();

		try {
			ob_start();
			include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-kanban.php';
			return ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Render the personal kanban page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_my_board_page(): string {
		set_query_var( 'decker_page', 'my-board' );

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-kanban-my.php';
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
	 * Tasks land in the column matching their stack and the counters follow.
	 */
	public function test_tasks_are_split_into_stack_columns_with_counters() {
		$todo_id = $this->create_task(
			array(
				'post_title' => 'Todo Card',
				'stack'      => 'to-do',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Progress Card',
				'stack'      => 'in-progress',
			)
		);
		$this->create_task(
			array(
				'post_title' => 'Another Progress Card',
				'stack'      => 'in-progress',
			)
		);

		$output = $this->render_board_page( 'sprint-board' );

		$this->assertStringContainsString( 'Sprint Board', $output );
		$this->assertStringContainsString( 'Todo Card', $output );
		$this->assertStringContainsString( 'Progress Card', $output );

		// Column counters reflect the split: 1 to-do, 2 in-progress, 0 done.
		$this->assertStringContainsString( 'TO-DO (1)', $output );
		$this->assertStringContainsString( 'In Progress (2)', $output );
		$this->assertStringContainsString( 'Done (0)', $output );

		// Cards expose their task id so the drag handler can persist a move.
		$this->assertStringContainsString( 'data-task-id="' . $todo_id . '"', $output );
	}

	/**
	 * An unknown board slug stops the page with an explicit error.
	 */
	public function test_unknown_board_slug_halts_with_an_error() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'does not exist' );

		$this->render_board_page( 'no-such-board' );
	}

	/**
	 * Only administrators are offered the "Fix Order" maintenance action.
	 */
	public function test_fix_order_button_is_limited_to_administrators() {
		$output = $this->render_board_page( 'sprint-board' );
		$this->assertStringNotContainsString( 'id="fix-order-btn"', $output );

		wp_set_current_user( 1 );
		$admin_output = $this->render_board_page( 'sprint-board' );
		$this->assertStringContainsString( 'id="fix-order-btn"', $admin_output );
		$this->assertStringContainsString( 'data-board-id="' . $this->board->term_id . '"', $admin_output );
	}

	/**
	 * A board description is exposed to the popover helper.
	 */
	public function test_board_description_is_exposed_to_the_popover() {
		wp_update_term(
			$this->board->term_id,
			'decker_board',
			array( 'description' => 'Everything for the current sprint' )
		);
		BoardManager::reset_instance();

		$output = $this->render_board_page( 'sprint-board' );

		$this->assertStringContainsString( 'data-bs-content="Everything for the current sprint"', $output );
	}

	/**
	 * The personal board only lists tasks the current user is involved in.
	 */
	public function test_my_board_lists_only_the_current_users_tasks() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		$mine = $this->create_task(
			array(
				'post_title'  => 'My Own Card',
				'stack'       => 'to-do',
				'responsable' => $this->editor,
			)
		);
		$theirs = $this->create_task(
			array(
				'post_title'  => 'Their Card',
				'stack'       => 'to-do',
				'responsable' => $other,
				'author'      => $other,
			)
		);

		$output = $this->render_my_board_page();

		$this->assertStringContainsString( 'My Board', $output );
		$this->assertStringContainsString( 'data-task-id="' . $mine . '"', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $theirs . '"', $output );
		$this->assertStringContainsString( 'TO-DO (1)', $output );
	}

	/**
	 * The personal board paints cards with the board colour.
	 */
	public function test_my_board_paints_cards_with_the_board_colour() {
		$this->create_task(
			array(
				'post_title'  => 'Coloured Card',
				'stack'       => 'done',
				'responsable' => $this->editor,
			)
		);

		$output = $this->render_my_board_page();

		// The card background is the pastelized board colour, not the raw one.
		$pastel = Decker_Task_Card_Renderer::pastelize_color( '#654321' );

		$this->assertStringContainsString( 'Coloured Card', $output );
		$this->assertStringContainsString( 'style="background-color: ' . $pastel . ';"', $output );
		$this->assertStringNotContainsString( 'background-color: #654321', $output );
		$this->assertStringContainsString( 'Done (1)', $output );
	}
}
