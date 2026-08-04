<?php
/**
 * Tests for the Decker front-end page dispatcher.
 *
 * decker_pre_get_posts() promotes a canonical task URL into the internal
 * decker_page/id pair, and include_decker_page() maps a page key onto the
 * template file. The template include is intercepted with the public
 * decker_include_file filter so the resolved path can be asserted without
 * rendering a page.
 *
 * @package Decker
 */

class DeckerPublicPageDispatchTest extends Decker_Test_Base {

	/**
	 * The dispatcher under test.
	 *
	 * @var Decker_Public
	 */
	private $public;

	/**
	 * Paths the include filter was asked to load.
	 *
	 * @var string[]
	 */
	private $resolved = array();

	/**
	 * A harmless file the filter substitutes for the real template.
	 *
	 * @var string
	 */
	private $stub;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->public   = new Decker_Public( 'decker', DECKER_VERSION );
		$this->resolved = array();
		$this->stub     = plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/layouts/head-css.php';

		add_filter(
			'decker_include_file',
			function ( $file_path, $page ) {
				$this->resolved[ $page ] = $file_path;
				return $this->stub;
			},
			10,
			2
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'decker_include_file' );
		parent::tear_down();
	}

	/**
	 * Invoke the protected page include.
	 *
	 * @param string $page The decker page key.
	 * @return void
	 */
	private function include_page( string $page ): void {
		$method = new ReflectionMethod( $this->public, 'include_decker_page' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->public, $page );
		ob_end_clean();
	}

	/**
	 * Every page key resolves to its template file.
	 *
	 * @dataProvider provide_page_keys
	 *
	 * @param string $page     The decker_page value.
	 * @param string $expected The expected template, relative to the plugin root.
	 */
	public function test_page_key_resolves_to_its_template( string $page, string $expected ) {
		$this->include_page( $page );

		$this->assertArrayHasKey( $page, $this->resolved );
		$this->assertStringEndsWith( $expected, $this->resolved[ $page ] );
	}

	/**
	 * The page-key to template mapping.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function provide_page_keys(): array {
		return array(
			'analytics'      => array( 'analytics', 'public/app-analytics.php' ),
			'board'          => array( 'board', 'public/app-kanban.php' ),
			'calendar'       => array( 'calendar', 'public/app-calendar.php' ),
			'my board'       => array( 'my-board', 'public/app-kanban-my.php' ),
			'priority'       => array( 'priority', 'public/app-priority.php' ),
			'task'           => array( 'task', 'public/app-task-full.php' ),
			'tasks'          => array( 'tasks', 'public/app-tasks.php' ),
			'term manager'   => array( 'term-manager', 'public/app-term-manager.php' ),
			'upcoming'       => array( 'upcoming', 'public/app-upcoming.php' ),
			'event manager'  => array( 'event-manager', 'public/app-event-manager.php' ),
			'knowledge base' => array( 'knowledge-base', 'public/app-knowledge-base.php' ),
		);
	}

	/**
	 * An unknown page key includes nothing at all.
	 */
	public function test_unknown_page_key_includes_nothing() {
		$this->include_page( 'not-a-decker-page' );

		$this->assertSame( array(), $this->resolved );
	}

	/**
	 * A plain permalink promotes decker_task into the page and id query vars.
	 */
	public function test_plain_task_permalink_sets_the_page_and_id() {
		$board_id = self::factory()->board->create();
		$task_id  = self::factory()->task->create( array( 'board' => $board_id ) );

		$query = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'decker_task', $task_id );

		$this->public->decker_pre_get_posts( $query );

		$this->assertSame( 'task', $query->get( 'decker_page' ) );
		$this->assertSame( $task_id, $query->get( 'id' ) );
	}

	/**
	 * A query that is not for a task is left untouched.
	 */
	public function test_unrelated_query_is_left_untouched() {
		$query = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->set( 'post_type', 'page' );

		$this->public->decker_pre_get_posts( $query );

		$this->assertSame( '', $query->get( 'decker_page' ) );
		$this->assertSame( '', $query->get( 'id' ) );
	}

	/**
	 * Secondary queries are never modified.
	 */
	public function test_secondary_queries_are_never_modified() {
		$main = new WP_Query();
		$GLOBALS['wp_the_query'] = $main;

		$secondary = new WP_Query();
		$secondary->set( 'decker_task', 4242 );

		$this->public->decker_pre_get_posts( $secondary );

		$this->assertSame( '', $secondary->get( 'decker_page' ) );
	}
}
