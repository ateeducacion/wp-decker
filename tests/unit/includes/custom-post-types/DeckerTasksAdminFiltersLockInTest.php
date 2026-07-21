<?php
/**
 * Characterization test for Decker_Tasks::filter_tasks_by_taxonomies().
 *
 * Pins the by-reference in-place mutation of $query->query_vars before the
 * method is refactored to share a helper.
 *
 * @package Decker
 */

class DeckerTasksAdminFiltersLockInTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $board_id;
	private $label_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$this->board_id = self::factory()->board->create();
		$this->label_id = self::factory()->label->create();
	}

	/**
	 * Lock the in-place conversion of numeric IDs to slugs.
	 */
	public function test_filter_tasks_by_taxonomies_converts_numeric_id_to_slug_in_place() {
		global $pagenow;
		$previous_pagenow = $pagenow;
		$pagenow          = 'edit.php';

		$query             = new WP_Query();
		$query->query_vars = array(
			'post_type'    => 'decker_task',
			'decker_board' => (string) $this->board_id,
			'decker_label' => (string) $this->label_id,
		);

		( new Decker_Tasks() )->filter_tasks_by_taxonomies( $query );

		$this->assertEquals(
			get_term( $this->board_id )->slug,
			$query->query_vars['decker_board'],
			'Board ID must be replaced with the term slug in place.'
		);
		$this->assertEquals(
			get_term( $this->label_id )->slug,
			$query->query_vars['decker_label'],
			'Label ID must be replaced with the term slug in place.'
		);

		// Non-numeric / zero values stay untouched.
		$query2             = new WP_Query();
		$query2->query_vars = array(
			'post_type'    => 'decker_task',
			'decker_board' => '0',
			'decker_label' => 'already-a-slug',
		);
		( new Decker_Tasks() )->filter_tasks_by_taxonomies( $query2 );
		$this->assertEquals( '0', $query2->query_vars['decker_board'] );
		$this->assertEquals( 'already-a-slug', $query2->query_vars['decker_label'] );

		$pagenow = $previous_pagenow;
	}
}
