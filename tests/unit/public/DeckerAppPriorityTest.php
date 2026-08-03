<?php
/**
 * Characterization tests for the Priority page template.
 *
 * The template renders the "MAX PRIORITY" table (desktop and mobile variants),
 * a per-user card for everybody who may see the board, and the import modal
 * that appears when the current user has nothing marked for today. These pin
 * the markup contract priority.js relies on plus the nonce check that guards
 * the import form.
 *
 * @package Decker
 */

class DeckerAppPriorityTest extends Decker_Test_Base {

	/**
	 * Editor used as the current user for most assertions.
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
				'name'  => 'Priority Board',
				'slug'  => 'priority-board',
				'color' => '#abcdef',
			)
		);

		$this->editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Edna Editor',
			)
		);
		wp_set_current_user( $this->editor );

		$_POST = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Render the Priority page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_priority_page(): string {
		set_query_var( 'decker_page', 'priority' );

		// The template opens an output buffer of its own and never closes it, so
		// unwind every buffer it left open down to the level we started at.
		$level = ob_get_level();

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-priority.php';

		$chunks = array();
		while ( ob_get_level() > $level ) {
			array_unshift( $chunks, ob_get_clean() );
		}

		return implode( '', $chunks );
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
	 * A max-priority task appears in both the desktop table and the mobile list.
	 */
	public function test_max_priority_task_renders_in_desktop_table_and_mobile_list() {
		$task_id = $this->create_task(
			array(
				'post_title'   => 'Urgent Rollout',
				'max_priority' => true,
				'stack'        => 'in-progress',
			)
		);

		$output = $this->render_priority_page();

		// Desktop table row.
		$this->assertStringContainsString( '<tbody id="priority-id-table">', $output );
		$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $output );
		$this->assertStringContainsString( 'Urgent Rollout', $output );

		// Board badge carries the board colour.
		$this->assertStringContainsString( 'background-color: ' . $this->board->color, $output );
		$this->assertStringContainsString( 'Priority Board', $output );

		// Mobile list renders the same task, and not the empty-state copy.
		$this->assertStringContainsString( 'class="priority-mobile-view d-md-none"', $output );
		$this->assertStringContainsString( 'class="list-group-item task-item"', $output );
		$this->assertStringNotContainsString( 'No high priority tasks found.', $output );
	}

	/**
	 * Tasks without max_priority never reach the priority table.
	 */
	public function test_tasks_without_max_priority_are_excluded() {
		$this->create_task(
			array(
				'post_title'   => 'Ordinary Backlog Item',
				'max_priority' => false,
			)
		);

		$output = $this->render_priority_page();

		$this->assertStringNotContainsString( 'Ordinary Backlog Item', $output );
		$this->assertStringContainsString( 'No high priority tasks found.', $output );
	}

	/**
	 * Hidden tasks are excluded even when flagged max priority.
	 */
	public function test_hidden_max_priority_task_is_excluded() {
		$visible_id = $this->create_task(
			array(
				'post_title'   => 'Visible Critical',
				'max_priority' => true,
			)
		);
		$hidden_id  = $this->create_task(
			array(
				'post_title'   => 'Hidden Critical',
				'max_priority' => true,
				'hidden'       => true,
			)
		);

		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'Visible Critical', $output );
		$this->assertStringNotContainsString( 'Hidden Critical', $output );
		$this->assertStringContainsString( 'data-task-id="' . $visible_id . '"', $output );
		$this->assertStringNotContainsString( 'data-task-id="' . $hidden_id . '"', $output );
	}

	/**
	 * A task with no board falls back to the "No board assigned" label.
	 */
	public function test_task_without_board_renders_uncategorized_labels() {
		$task_id = $this->create_task(
			array(
				'post_title'   => 'Boardless Critical',
				'max_priority' => true,
			)
		);
		wp_delete_object_term_relationships( $task_id, 'decker_board' );
		clean_post_cache( $task_id );

		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'No board assigned', $output );
		$this->assertStringContainsString( 'Uncategorized', $output );
	}

	/**
	 * The responsable renders a starred avatar and is not repeated as an assignee.
	 */
	public function test_responsable_renders_starred_avatar_and_is_not_duplicated() {
		$assignee = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Second Person',
			)
		);

		$this->create_task(
			array(
				'post_title'     => 'Shared Critical',
				'max_priority'   => true,
				'responsable'    => $this->editor,
				'assigned_users' => array( $this->editor, $assignee ),
			)
		);

		$output = $this->render_priority_page();

		// The responsable gets the star badge.
		$this->assertStringContainsString( 'badge badge_avatar', $output );
		$this->assertStringContainsString( 'title="Edna Editor"', $output );
		$this->assertStringContainsString( 'title="Second Person"', $output );

		// The responsable is skipped in the assigned-users column, so the star
		// markup is not emitted once per assignment.
		$this->assertSame( 2, substr_count( $output, 'ri-star-s-fill' ), 'Responsable avatar renders once per view (mobile + desktop).' );
	}

	/**
	 * With nothing marked for today the import alert and modal offer previous tasks.
	 */
	public function test_import_alert_lists_tasks_from_a_previous_day() {
		$task_id = $this->create_task(
			array(
				'post_title'     => 'Yesterday Task',
				'assigned_users' => array( $this->editor ),
			)
		);

		$yesterday = ( new DateTime( 'yesterday' ) )->format( 'Y-m-d' );
		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => $yesterday,
				),
			)
		);

		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'id="alert-import-today-1"', $output );
		$this->assertStringContainsString( 'You have no tasks defined for today.', $output );
		$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $output );
		$this->assertStringContainsString( 'name="task_ids[]"', $output );

		// The date selector offers the day the task was marked on.
		$this->assertStringContainsString( '<option value="' . $yesterday . '">', $output );
	}

	/**
	 * Once the user has tasks for today the import alert disappears.
	 */
	public function test_import_alert_is_hidden_when_user_has_tasks_for_today() {
		$task_id = $this->create_task(
			array(
				'post_title'     => 'Today Task',
				'max_priority'   => true,
				'assigned_users' => array( $this->editor ),
			)
		);

		( new Decker_Tasks() )->get_today_manager()->mark_for_today( $task_id, $this->editor );

		$output = $this->render_priority_page();

		$this->assertStringNotContainsString( 'id="alert-import-today-1"', $output );
		$this->assertStringNotContainsString( 'You have no tasks defined for today.', $output );

		// The editor's own card lists the task instead of the empty placeholder.
		$card = $this->card_for_user( $output, 'Edna Editor' );
		$this->assertStringContainsString( 'Today Task', $card );
		$this->assertStringNotContainsString( 'No tasks for today.', $card );
	}

	/**
	 * Extract the per-user card markup for a display name.
	 *
	 * The cards are siblings inside #cards-container, so the card ends where the
	 * next card header begins (or at the end of the container for the last one).
	 *
	 * @param string $output       Full page output.
	 * @param string $display_name The user display name in the card header.
	 * @return string The card markup, or an empty string when not found.
	 */
	private function card_for_user( string $output, string $display_name ): string {
		$container = strpos( $output, 'id="cards-container"' );
		if ( false === $container ) {
			return '';
		}

		$start = strpos( $output, '<h4 class="header-title">' . $display_name . '</h4>', $container );
		if ( false === $start ) {
			return '';
		}

		$rest = substr( $output, $start );
		$next = strpos( $rest, '<h4 class="header-title">', 1 );

		return false === $next ? $rest : substr( $rest, 0, $next );
	}

	/**
	 * Users with nothing for today get the empty placeholder in their card.
	 */
	public function test_user_card_renders_placeholder_when_user_has_no_tasks_today() {
		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'Edna Editor', $output );
		$this->assertStringContainsString( 'No tasks for today.', $output );

		// The current user's own card is highlighted.
		$this->assertStringContainsString( 'card border-primary border', $output );
	}

	/**
	 * Users listed in the ignored_users setting get no card at all.
	 */
	public function test_ignored_users_are_excluded_from_the_user_cards() {
		$ignored = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ignored Person',
			)
		);

		update_option(
			'decker_settings',
			array(
				'minimum_user_profile' => 'editor',
				'ignored_users'        => (string) $ignored,
			)
		);

		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'Edna Editor', $output );
		$this->assertStringNotContainsString( 'Ignored Person', $output );
	}

	/**
	 * The configured alert message renders through the shared top-alert partial.
	 */
	public function test_configured_alert_message_is_rendered() {
		update_option(
			'decker_settings',
			array(
				'alert_message' => 'Scheduled maintenance tonight',
				'alert_color'   => 'danger',
			)
		);

		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'alert alert-danger alert-dismissible', $output );
		$this->assertStringContainsString( 'Scheduled maintenance tonight', $output );
	}

	/**
	 * A submitted import form with an invalid nonce must not mark anything for today.
	 */
	public function test_import_submission_with_invalid_nonce_marks_nothing() {
		$task_id = $this->create_task( array( 'post_title' => 'Not Imported' ) );

		$_POST['import_tasks_nonce'] = 'clearly-not-a-valid-nonce';
		$_POST['task_ids']           = array( $task_id );

		$this->render_priority_page();

		$this->assertSame(
			'',
			get_post_meta( $task_id, '_user_date_relations', true ),
			'An invalid nonce must leave the today relations untouched.'
		);
	}

	/**
	 * The import modal carries a fresh nonce field for the import action.
	 */
	public function test_import_modal_emits_the_import_tasks_nonce_field() {
		$output = $this->render_priority_page();

		$this->assertStringContainsString( 'name="import_tasks_nonce"', $output );
		$this->assertStringContainsString( 'id="taskModal"', $output );
		$this->assertStringContainsString( 'class="btn btn-primary import-selected-tasks"', $output );
	}
}
