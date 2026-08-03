<?php
/**
 * Tests for the wp-admin list-table and edit-screen presentation classes.
 *
 * Decker_Task_Admin_List owns the task list table and its filters,
 * Decker_Task_Admin_Chrome the edit-screen tweaks, and
 * Decker_Event_Admin_Screen the event list table. All three are pure
 * presentation, so these assert the columns, the emitted markup and the query
 * mutations, including the cases where nothing should happen.
 *
 * @package Decker
 */

class DeckerAdminScreenChromeTest extends Decker_Test_Base {

	/**
	 * The task list-table presenter.
	 *
	 * @var Decker_Task_Admin_List
	 */
	private $task_list;

	/**
	 * The task edit-screen presenter.
	 *
	 * @var Decker_Task_Admin_Chrome
	 */
	private $task_chrome;

	/**
	 * The event list-table presenter.
	 *
	 * @var Decker_Event_Admin_Screen
	 */
	private $event_screen;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->task_list    = new Decker_Task_Admin_List();
		$this->task_chrome  = new Decker_Task_Admin_Chrome();
		$this->event_screen = new Decker_Event_Admin_Screen();

		wp_set_current_user( 1 );
		$_GET = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_GET                  = array();
		$GLOBALS['pagenow']    = 'index.php';
		$GLOBALS['typenow']    = '';
		$GLOBALS['post_type']  = '';
		parent::tear_down();
	}

	/**
	 * Capture the output of a callback.
	 *
	 * @param callable $callback The callback to run.
	 * @return string The captured output.
	 */
	private function capture( callable $callback ): string {
		ob_start();
		$callback();
		return ob_get_clean();
	}

	/**
	 * The task list table replaces the date column with a stack column.
	 */
	public function test_task_columns_swap_date_for_stack() {
		$columns = $this->task_list->add_custom_columns(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertArrayNotHasKey( 'date', $columns );
		$this->assertArrayHasKey( 'stack', $columns );
		$this->assertSame( array( 'cb', 'title', 'stack' ), array_keys( $columns ) );
	}

	/**
	 * The stack column is registered as sortable.
	 */
	public function test_stack_column_is_sortable() {
		$columns = $this->task_list->make_columns_sortable( array( 'title' => 'title' ) );

		$this->assertSame( 'stack', $columns['stack'] );
		$this->assertSame( 'title', $columns['title'] );
	}

	/**
	 * The stack column renders the stored stack meta; other columns render nothing.
	 */
	public function test_stack_column_renders_the_stored_stack() {
		$board_id = self::factory()->board->create();
		$task_id  = self::factory()->task->create(
			array(
				'board' => $board_id,
				'stack' => 'in-progress',
			)
		);

		$rendered = $this->capture(
			function () use ( $task_id ) {
				$this->task_list->render_custom_columns( 'stack', $task_id );
			}
		);
		$this->assertSame( 'in-progress', $rendered );

		$other = $this->capture(
			function () use ( $task_id ) {
				$this->task_list->render_custom_columns( 'title', $task_id );
			}
		);
		$this->assertSame( '', $other );
	}

	/**
	 * Row actions are stripped for tasks and left alone for other post types.
	 */
	public function test_row_actions_are_removed_for_tasks_only() {
		$board_id = self::factory()->board->create();
		$task     = get_post( self::factory()->task->create( array( 'board' => $board_id ) ) );
		$page     = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );

		$actions = array( 'edit' => '<a>Edit</a>' );

		$this->assertSame( array(), $this->task_list->remove_row_actions( $actions, $task ) );
		$this->assertSame( $actions, $this->task_list->remove_row_actions( $actions, $page ) );
	}

	/**
	 * The task list defaults to published tasks, unless a status was requested.
	 */
	public function test_task_list_defaults_to_published_status() {
		$GLOBALS['pagenow']   = 'edit.php';
		$_GET['post_type']    = 'decker_task';

		$query = new WP_Query();
		$this->task_list->filter_tasks_by_status( $query );
		$this->assertSame( 'publish', $query->get( 'post_status' ) );

		// An explicit status request is left untouched.
		$_GET['post_status'] = 'archived';
		$explicit            = new WP_Query();
		$this->task_list->filter_tasks_by_status( $explicit );
		$this->assertSame( '', $explicit->get( 'post_status' ) );
	}

	/**
	 * Outside the task list table the status filter does nothing.
	 */
	public function test_status_filter_is_inert_on_other_screens() {
		$GLOBALS['pagenow'] = 'edit.php';
		$_GET['post_type']  = 'page';

		$query = new WP_Query();
		$this->task_list->filter_tasks_by_status( $query );

		$this->assertSame( '', $query->get( 'post_status' ) );
	}

	/**
	 * Sorting by stack switches the query to the stack meta value.
	 */
	public function test_order_by_stack_switches_to_the_meta_value() {
		set_current_screen( 'edit-decker_task' );

		$query = new WP_Query();
		$query->is_main_query = true;
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'post_type', 'decker_task' );
		$query->set( 'orderby', 'stack' );

		$this->task_list->custom_order_by_stack( $query );

		$this->assertSame( 'stack', $query->get( 'meta_key' ) );
		$this->assertSame( 'meta_value', $query->get( 'orderby' ) );
	}

	/**
	 * Any other orderby is left alone.
	 */
	public function test_order_by_other_columns_is_left_alone() {
		set_current_screen( 'edit-decker_task' );

		$query = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'post_type', 'decker_task' );
		$query->set( 'orderby', 'title' );

		$this->task_list->custom_order_by_stack( $query );

		$this->assertSame( '', $query->get( 'meta_key' ) );
		$this->assertSame( 'title', $query->get( 'orderby' ) );
	}

	/**
	 * The taxonomy filter dropdowns render only on the task list table.
	 */
	public function test_taxonomy_filters_render_only_for_tasks() {
		self::factory()->board->create(
			array(
				'name' => 'Filterable Board',
				'slug' => 'filterable-board',
			)
		);
		$board_id = get_term_by( 'slug', 'filterable-board', 'decker_board' )->term_id;

		// hide_empty is true, so the term needs at least one task.
		self::factory()->task->create( array( 'board' => $board_id ) );

		$GLOBALS['typenow'] = 'decker_task';
		$output             = $this->capture( fn() => $this->task_list->add_taxonomy_filters() );

		$this->assertStringContainsString( "name='decker_board'", $output );
		$this->assertStringContainsString( "name='decker_label'", $output );
		$this->assertStringContainsString( 'Filterable Board', $output );

		$GLOBALS['typenow'] = 'page';
		$this->assertSame( '', $this->capture( fn() => $this->task_list->add_taxonomy_filters() ) );
	}

	/**
	 * Gutenberg is disabled for tasks and left as-is for other post types.
	 */
	public function test_gutenberg_is_disabled_for_tasks_only() {
		$this->assertFalse( $this->task_chrome->disable_gutenberg( true, 'decker_task' ) );
		$this->assertTrue( $this->task_chrome->disable_gutenberg( true, 'page' ) );
		$this->assertFalse( $this->task_chrome->disable_gutenberg( false, 'page' ) );
	}

	/**
	 * The visibility and publish-box tweaks are scoped to the task post type.
	 */
	public function test_edit_screen_tweaks_are_scoped_to_the_task_post_type() {
		$GLOBALS['post_type'] = 'decker_task';

		$visibility = $this->capture( fn() => $this->task_chrome->hide_visibility_options() );
		$this->assertStringContainsString( '.misc-pub-section.misc-pub-visibility', $visibility );
		$this->assertStringContainsString( 'display: none', $visibility );

		$publish = $this->capture( fn() => $this->task_chrome->change_publish_meta_box_title() );
		$this->assertStringContainsString( '#submitdiv .hndle', $publish );

		$GLOBALS['post_type'] = 'page';
		$this->assertSame( '', $this->capture( fn() => $this->task_chrome->hide_visibility_options() ) );
		$this->assertSame( '', $this->capture( fn() => $this->task_chrome->change_publish_meta_box_title() ) );
	}

	/**
	 * The permalink and menu-order tweaks only run on the task edit screen.
	 */
	public function test_permalink_and_menu_order_tweaks_require_the_task_edit_screen() {
		set_current_screen( 'decker_task' );

		$slug_css = $this->capture( fn() => $this->task_chrome->hide_permalink_and_slug() );
		$this->assertStringContainsString( '#edit-slug-box', $slug_css );

		$menu_order = $this->capture( fn() => $this->task_chrome->disable_menu_order_field() );
		$this->assertStringContainsString( "getElementById('menu_order')", $menu_order );

		// On the list table (base "edit") neither tweak applies.
		set_current_screen( 'edit-decker_task' );
		$this->assertSame( '', $this->capture( fn() => $this->task_chrome->hide_permalink_and_slug() ) );
		$this->assertSame( '', $this->capture( fn() => $this->task_chrome->disable_menu_order_field() ) );
	}

	/**
	 * The "Add New" submenu entry is removed for tasks.
	 */
	public function test_add_new_submenu_entry_is_removed() {
		global $submenu;

		$submenu['edit.php?post_type=decker_task'] = array(
			5  => array( 'All Tasks', 'edit_posts', 'edit.php?post_type=decker_task' ),
			10 => array( 'Add New', 'edit_posts', 'post-new.php?post_type=decker_task' ),
		);

		$this->task_chrome->remove_add_new_link();

		$slugs = array_column( $submenu['edit.php?post_type=decker_task'], 2 );
		$this->assertContains( 'edit.php?post_type=decker_task', $slugs );
		$this->assertNotContains( 'post-new.php?post_type=decker_task', $slugs );
	}

	/**
	 * The event list table adds its meta columns and keeps date last.
	 */
	public function test_event_columns_add_meta_columns_and_keep_date_last() {
		$columns = $this->event_screen->add_custom_columns(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', 'event_allday', 'event_start', 'event_end', 'event_category', 'date' ),
			array_keys( $columns )
		);
	}

	/**
	 * Each event column renders the corresponding meta value.
	 */
	public function test_event_columns_render_their_meta() {
		$event_id = self::factory()->event->create(
			array(
				'post_title' => 'Column Event',
				'meta_input' => array(
					'event_allday'   => true,
					'event_start'    => '2031-02-03 08:00:00',
					'event_end'      => '2031-02-03 09:00:00',
					'event_category' => 'bg-warning',
				),
			)
		);

		$allday = $this->capture( fn() => $this->event_screen->render_custom_columns( 'event_allday', $event_id ) );
		$this->assertStringContainsString( 'checked', $allday );

		$start = $this->capture( fn() => $this->event_screen->render_custom_columns( 'event_start', $event_id ) );
		$this->assertStringContainsString( '2031-02-03', $start );

		$end = $this->capture( fn() => $this->event_screen->render_custom_columns( 'event_end', $event_id ) );
		$this->assertStringContainsString( '2031-02-03', $end );

		$category = $this->capture( fn() => $this->event_screen->render_custom_columns( 'event_category', $event_id ) );
		$this->assertSame( 'bg-warning', $category );

		$unknown = $this->capture( fn() => $this->event_screen->render_custom_columns( 'nope', $event_id ) );
		$this->assertSame( '', $unknown );
	}

	/**
	 * The event edit screen hides the visibility box; other screens are untouched.
	 */
	public function test_event_visibility_css_is_scoped_to_the_event_screen() {
		set_current_screen( 'decker_event' );
		$css = $this->capture( fn() => $this->event_screen->hide_visibility_options() );
		$this->assertStringContainsString( '.misc-pub-section.misc-pub-visibility', $css );

		set_current_screen( 'post' );
		$this->assertSame( '', $this->capture( fn() => $this->event_screen->hide_visibility_options() ) );
	}
}
