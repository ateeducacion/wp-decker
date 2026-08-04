<?php
/**
 * Characterization tests for the Tasks list page template.
 *
 * The page feeds a DataTable, so every cell carries data-order/data-search
 * payloads the client sorts and filters on. These tests pin those payloads,
 * the ?type= switch between the active/my/archived task sets, and the
 * due-date classes the stylesheet keys off.
 *
 * @package Decker
 */

class DeckerAppTasksTest extends Decker_Test_Base {

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
				'name'  => 'Delivery Board',
				'slug'  => 'delivery-board',
				'color' => '#112233',
			)
		);

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Tanya Tasks',
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
		parent::tear_down();
	}

	/**
	 * Render the Tasks page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_tasks_page(): string {
		set_query_var( 'decker_page', 'tasks' );

		// The board filter reads through the cached manager, which survives
		// across test classes; drop the cache so the fixtures show up.
		BoardManager::reset_instance();

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-tasks.php';
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
	 * The default view lists published tasks with their board badge and modal link.
	 */
	public function test_default_view_lists_published_tasks() {
		$task_id = $this->create_task(
			array(
				'post_title'   => 'Ship The Thing',
				'stack'        => 'in-progress',
				'max_priority' => true,
			)
		);

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'id="tablaTareas"', $output );
		$this->assertStringContainsString( 'Ship The Thing', $output );
		$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $output );
		$this->assertStringContainsString( 'background-color: ' . $this->board->color, $output );
		$this->assertStringContainsString( 'Delivery Board', $output );

		// The max-priority flame renders in the first column.
		$this->assertStringContainsString( '<td>🔥</td>', $output );
	}

	/**
	 * The board filter dropdown is populated from every existing board.
	 */
	public function test_board_filter_lists_every_board() {
		self::factory()->board->create(
			array(
				'name' => 'Second Board',
				'slug' => 'second-board',
			)
		);
		BoardManager::reset_instance();

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'id="boardFilter"', $output );
		$this->assertStringContainsString( '<option value="">', $output );
		$this->assertStringContainsString( '<option value="Delivery Board">Delivery Board</option>', $output );
		$this->assertStringContainsString( '<option value="Second Board">Second Board</option>', $output );
	}

	/**
	 * ?type=active renders the "Active Tasks" title with the add button enabled.
	 */
	public function test_active_type_renders_active_title_with_enabled_add_button() {
		$_GET['type'] = 'active';

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'Active Tasks', $output );
		$this->assertStringNotContainsString( 'btn btn-success btn-sm ms-3  disabled', $output );
	}

	/**
	 * ?type=archived switches the query to archived tasks and disables the add button.
	 */
	public function test_archived_type_lists_archived_tasks_only_and_disables_add_button() {
		$published_id = $this->create_task( array( 'post_title' => 'Still Running' ) );
		$archived_id  = $this->create_task( array( 'post_title' => 'Long Finished' ) );

		wp_update_post(
			array(
				'ID'          => $archived_id,
				'post_status' => 'archived',
			)
		);

		$_GET['type'] = 'archived';
		$output       = $this->render_tasks_page();

		$this->assertStringContainsString( 'Archived Tasks', $output );
		$this->assertStringContainsString( 'Long Finished', $output );
		$this->assertStringNotContainsString( 'Still Running', $output );
		$this->assertStringContainsString( 'data-task-id="' . $archived_id . '"', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $published_id . '"', $output );

		// The add button is rendered disabled on the archived view.
		$this->assertMatchesRegularExpression( '/btn btn-success btn-sm ms-3\s+disabled/', $output );
	}

	/**
	 * ?type=my restricts the list to tasks the current user is involved in.
	 */
	public function test_my_type_lists_only_the_current_users_tasks() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		$mine = $this->create_task(
			array(
				'post_title'  => 'Assigned To Me',
				'responsable' => $this->editor,
			)
		);
		$theirs = $this->create_task(
			array(
				'post_title'  => 'Assigned To Them',
				'responsable' => $other,
				'author'      => $other,
			)
		);

		$_GET['type'] = 'my';
		$output       = $this->render_tasks_page();

		$this->assertStringContainsString( 'My Tasks', $output );
		$this->assertStringContainsString( 'data-task-id="' . $mine . '"', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $theirs . '"', $output );
	}

	/**
	 * A task with no board renders the "Undefined board" warning badge.
	 */
	public function test_task_without_board_renders_undefined_board_badge() {
		$task_id = $this->create_task( array( 'post_title' => 'Orphan Task' ) );
		wp_delete_object_term_relationships( $task_id, 'decker_board' );
		clean_post_cache( $task_id );

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'badge bg-danger', $output );
		$this->assertStringContainsString( 'Undefined board', $output );
	}

	/**
	 * Labels render as coloured badges in the tags column.
	 */
	public function test_labels_render_as_coloured_badges() {
		wp_set_current_user( 1 );
		$label = self::factory()->label->create_and_get(
			array(
				'name'  => 'Blocked',
				'slug'  => 'blocked',
				'color' => '#ff00ff',
			)
		);
		wp_set_current_user( $this->editor );

		$this->create_task(
			array(
				'post_title' => 'Labelled Task',
				'labels'     => array( $label->term_id ),
			)
		);

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( '<span class="badge" style="background-color: #ff00ff;">Blocked</span>', $output );
	}

	/**
	 * A due date today gets the due-today class, a past one due-past.
	 */
	public function test_due_date_classes_distinguish_today_and_overdue() {
		$today_id = $this->create_task(
			array(
				'post_title' => 'Due Today Task',
				'duedate'    => ( new DateTime( 'today' ) )->format( 'Y-m-d' ),
			)
		);
		$past_id  = $this->create_task(
			array(
				'post_title' => 'Overdue Task',
				'duedate'    => ( new DateTime( '-3 days' ) )->format( 'Y-m-d' ),
			)
		);

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'class="due-today"', $output );
		$this->assertStringContainsString( 'class="due-past"', $output );
		$this->assertStringContainsString( 'data-order="' . ( new DateTime( 'today' ) )->format( 'Y-m-d' ) . '"', $output );
		$this->assertNotSame( $today_id, $past_id );
	}

	/**
	 * A task with no duedate meta at all renders the due-none span.
	 *
	 * Tasks saved through Decker_Task_Writer always carry a duedate meta row
	 * (empty when unset), so this branch is reached only when the row is
	 * missing entirely, e.g. for rows written before the meta existed.
	 */
	public function test_task_without_due_date_meta_renders_due_none() {
		$task_id = $this->create_task( array( 'post_title' => 'No Deadline' ) );
		delete_post_meta( $task_id, 'duedate' );
		clean_post_cache( $task_id );

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'class="due-none"', $output );
	}

	/**
	 * The people cell exposes the searchable display-name list DataTables sorts on.
	 */
	public function test_people_cell_exposes_searchable_display_names() {
		$this->create_task(
			array(
				'post_title'     => 'Team Task',
				'responsable'    => $this->editor,
				'assigned_users' => array( $this->editor ),
			)
		);

		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'data-search="Tanya Tasks"', $output );
		$this->assertStringContainsString( 'data-order="Tanya Tasks"', $output );
	}

	/**
	 * The stack cell carries the human-readable label for sorting and searching.
	 */
	public function test_stack_cell_carries_the_stack_label() {
		$this->create_task(
			array(
				'post_title' => 'In Progress Task',
				'stack'      => 'in-progress',
			)
		);

		$label  = Decker_Tasks::get_stack_label( 'in-progress' );
		$output = $this->render_tasks_page();

		$this->assertStringContainsString( 'data-order="' . esc_attr( $label ) . '" data-search="' . esc_attr( $label ) . '"', $output );
	}
}
