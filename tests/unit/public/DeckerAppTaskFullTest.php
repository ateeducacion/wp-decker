<?php
/**
 * Characterization tests for the full-page task detail template.
 *
 * app-task-full.php renders the page chrome and, for a resolvable task,
 * includes layouts/task-card.php — the same partial the task modal loads over
 * AJAX. The partial declares include_wp_load() at file scope, so it can only be
 * included once per PHP process; exactly one test here therefore renders a
 * valid task, and it uses a deliberately rich fixture so a single pass exercises
 * the comment, attachment, label, assignee and history branches together.
 *
 * @package Decker
 */

class DeckerAppTaskFullTest extends Decker_Test_Base {

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
				'name' => 'Detail Board',
				'slug' => 'detail-board',
			)
		);

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Dana Detail',
			)
		);
		wp_set_current_user( $this->editor );

		$_GET = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_GET = array();
		set_query_var( 'id', 0 );
		parent::tear_down();
	}

	/**
	 * Render the task detail page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_task_page(): string {
		set_query_var( 'decker_page', 'task' );

		// The board and label selects read through the cached managers, which
		// survive across test classes; drop the cache so the fixtures show up.
		BoardManager::reset_instance();
		LabelManager::reset_instance();

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-task-full.php';
		return ob_get_clean();
	}

	/**
	 * An existing task renders its card with the title, copy link and tab payloads.
	 */
	public function test_existing_task_renders_the_editable_task_card() {
		wp_set_current_user( 1 );
		$label = self::factory()->label->create_and_get(
			array(
				'name'  => 'Regression',
				'slug'  => 'regression',
				'color' => '#00ffcc',
			)
		);
		wp_set_current_user( $this->editor );

		$assignee = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Alan Assignee',
			)
		);

		$task_id = self::factory()->task->create(
			array(
				'post_title'     => 'Detailed Task',
				'post_content'   => 'The full description body',
				'board'          => $this->board->term_id,
				'stack'          => 'in-progress',
				'max_priority'   => true,
				'duedate'        => '2030-01-15',
				'responsable'    => $this->editor,
				'assigned_users' => array( $this->editor, $assignee ),
				'labels'         => array( $label->term_id ),
			)
		);

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $task_id,
				'comment_content'  => 'A recorded observation',
				'comment_approved' => 1,
				'user_id'          => $this->editor,
			)
		);

		set_query_var( 'id', $task_id );
		$output = $this->render_task_page();

		// Page chrome resolves the task title and offers the copy-URL affordance.
		$this->assertStringContainsString( 'Detailed Task', $output );
		$this->assertStringContainsString( 'class="copy-task-url"', $output );
		$this->assertStringNotContainsString( 'Task not found', $output );

		// The card form is bound to the task.
		$this->assertStringContainsString( 'id="task-form"', $output );
		$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $output );
		$this->assertStringContainsString( 'value="' . $task_id . '"', $output );

		// Fields are pre-filled from the task.
		$this->assertStringContainsString( 'value="Detailed Task"', $output );
		$this->assertStringContainsString( 'The full description body', $output );
		$this->assertMatchesRegularExpression( '/<option value="in-progress"\s+selected/', $output );
		$this->assertStringContainsString( 'value="2030-01-15"', $output );

		// Board, label and assignee selects carry the current selection.
		$this->assertStringContainsString( 'Detail Board', $output );
		$this->assertStringContainsString( 'Regression', $output );
		$this->assertStringContainsString( '"color": "#00ffcc"', $output );
		$this->assertStringContainsString( 'Alan Assignee', $output );

		// The comment tab counts the approved comment and renders it.
		$this->assertStringContainsString( 'id="comment-count">1<', $output );
		$this->assertStringContainsString( 'A recorded observation', $output );

		// The comment belongs to the current user, so the delete affordance renders.
		$this->assertStringContainsString( 'data-comment-id="' . $comment_id . '"', $output );

		// With no media attached the attachment tab counts zero.
		$this->assertStringContainsString( 'id="attachment-count">0<', $output );

		// An unlocked task is editable: no lock banner, and the today quick action shows.
		$this->assertStringNotContainsString( 'decker-lock-banner', $output );
		$this->assertStringContainsString( 'id="task-today-quick"', $output );
		$this->assertStringContainsString( 'Add to today', $output );
	}

	/**
	 * An id that does not resolve to a task renders the not-found title and no card.
	 */
	public function test_unknown_task_id_renders_not_found_without_the_card() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		set_query_var( 'id', $page_id );
		$output = $this->render_task_page();

		$this->assertStringContainsString( 'Task not found', $output );
		$this->assertStringNotContainsString( 'class="copy-task-url"', $output );
		$this->assertStringNotContainsString( 'id="task-form"', $output );
	}

	/**
	 * A task id pointing at a deleted post also degrades to the not-found state.
	 */
	public function test_missing_post_renders_not_found_without_the_card() {
		set_query_var( 'id', 999999 );
		$output = $this->render_task_page();

		$this->assertStringContainsString( 'Task not found', $output );
		$this->assertStringNotContainsString( 'id="task-form"', $output );
	}
}
