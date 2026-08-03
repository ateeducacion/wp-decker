<?php
/**
 * Tests for the decker_action taxonomy.
 *
 * The taxonomy is limited to two levels and is not readable anonymously over
 * the REST API. These cover the depth clamp on insert, the error returned for
 * a too-deep term, the admin column tweak and the REST guard.
 *
 * @package Decker
 */

class DeckerActionsTaxonomyTest extends Decker_Test_Base {

	/**
	 * The taxonomy handler under test.
	 *
	 * @var Decker_Actions
	 */
	private $actions;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->actions = new Decker_Actions();
		wp_set_current_user( 1 );
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
	 * The taxonomy is registered against tasks and exposed as "actions" in REST.
	 */
	public function test_taxonomy_is_registered_for_tasks() {
		$taxonomy = get_taxonomy( 'decker_action' );

		$this->assertInstanceOf( WP_Taxonomy::class, $taxonomy );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertContains( 'decker_task', $taxonomy->object_type );
		$this->assertSame( 'actions', $taxonomy->rest_base );
		$this->assertSame( 'read', $taxonomy->cap->assign_terms );
	}

	/**
	 * A third-level term is reparented to the top level on insert.
	 */
	public function test_third_level_term_is_reparented_to_the_root() {
		$root  = wp_insert_term( 'Root Action', 'decker_action' );
		$child = wp_insert_term( 'Child Action', 'decker_action', array( 'parent' => $root['term_id'] ) );

		$clamped = $this->actions->restrict_depth(
			array( 'parent' => $child['term_id'] ),
			'decker_action'
		);

		$this->assertSame( 0, $clamped['parent'] );
	}

	/**
	 * A second-level parent is left alone.
	 */
	public function test_second_level_term_keeps_its_parent() {
		$root = wp_insert_term( 'Keeper Root', 'decker_action' );

		$args = $this->actions->restrict_depth(
			array( 'parent' => $root['term_id'] ),
			'decker_action'
		);

		$this->assertSame( $root['term_id'], $args['parent'] );
	}

	/**
	 * Other taxonomies are never touched by the depth clamp.
	 */
	public function test_other_taxonomies_are_not_clamped() {
		$args = array( 'parent' => 12345 );

		$this->assertSame( $args, $this->actions->restrict_depth( $args, 'category' ) );
	}

	/**
	 * A term that ended up too deep is reported as an error.
	 */
	public function test_too_deep_term_is_reported_as_an_error() {
		$root  = wp_insert_term( 'Depth Root', 'decker_action' );
		$child = wp_insert_term( 'Depth Child', 'decker_action', array( 'parent' => $root['term_id'] ) );

		$grandchild        = new stdClass();
		$grandchild->parent = $child['term_id'];

		$result = $this->actions->term_depth_error( $grandchild, 'decker_action' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'term_depth_error', $result->get_error_code() );
	}

	/**
	 * A second-level term passes the depth check unchanged.
	 */
	public function test_second_level_term_passes_the_depth_check() {
		$root = wp_insert_term( 'Passing Root', 'decker_action' );

		$child         = new stdClass();
		$child->parent = $root['term_id'];

		$this->assertSame( $child, $this->actions->term_depth_error( $child, 'decker_action' ) );

		// A pre-existing error is passed straight through.
		$error = new WP_Error( 'existing', 'Already broken' );
		$this->assertSame( $error, $this->actions->term_depth_error( $error, 'decker_action' ) );
	}

	/**
	 * The description column is removed from the term list table.
	 */
	public function test_description_column_is_removed() {
		$columns = $this->actions->customize_columns(
			array(
				'name'        => 'Name',
				'description' => 'Description',
				'slug'        => 'Slug',
			)
		);

		$this->assertArrayNotHasKey( 'description', $columns );
		$this->assertArrayHasKey( 'name', $columns );
	}

	/**
	 * The description field is hidden only on the decker_action term screen.
	 */
	public function test_description_css_is_scoped_to_the_action_screen() {
		$_GET['taxonomy'] = 'decker_action';

		ob_start();
		$this->actions->hide_description();
		$this->assertStringContainsString( '.term-description-wrap', ob_get_clean() );

		$_GET['taxonomy'] = 'category';

		ob_start();
		$this->actions->hide_description();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Anonymous callers are refused on the actions REST route.
	 */
	public function test_rest_route_is_closed_to_anonymous_callers() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/actions' );
		$result  = $this->actions->restrict_rest_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Logged-in callers and other routes are left alone.
	 */
	public function test_rest_guard_allows_logged_in_callers_and_other_routes() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/actions' );
		$this->assertNull( $this->actions->restrict_rest_access( null, rest_get_server(), $request ) );

		wp_set_current_user( 0 );
		$other = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$this->assertNull( $this->actions->restrict_rest_access( null, rest_get_server(), $other ) );
	}
}
