<?php
/**
 * Class DeckerAdminExportTest
 *
 * @package Decker
 */

class DeckerAdminExportTest extends WP_UnitTestCase {
	/**
	 * @var Decker_Admin_Export
	 */
	private $export_instance;

	/**
	 * @var int $admin_user_id Admin user ID
	 */
	private int $admin_user_id;

	/**
	 * Set up test environment
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Create and set admin user.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_user_id );

		$this->export_instance = new Decker_Admin_Export();
	}

	/**
	 * Test export post type functionality
	 *
	 * @return void
	 */
	public function test_export_post_types() {
		// Test Task Export
		$this->test_export_task();

		// Test KB Article Export
		$this->test_export_kb_article();

		// Test Event Export
		$this->test_export_event();
	}

	private function test_export_task() {
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );
		$board_id = $this->factory->board->create( array( 'name' => 'Test Board' ) );
		$post_id = $this->factory->task->create(
			array(
				'post_title' => 'Test Task',
				'post_content' => 'Test Content',
				'duedate'    => '2024-12-20',
				'max_priority'   => true,
				'board' => $board_id,
				'labels' => array( $this->factory->label->create( array( 'name' => 'Test Label' ) ) ),
			)
		);

		$exported_posts = $this->export_instance->export_post_type( 'decker_task' );
		$this->validate_task_export( $exported_posts, $post_id );
	}

	private function test_export_kb_article() {
		$parent_id = wp_insert_post(
			array(
				'post_type' => 'decker_kb',
				'post_title' => 'Parent Article',
				'post_content' => 'Parent Content',
				'post_status' => 'publish',
			)
		);

		$child_id = wp_insert_post(
			array(
				'post_type' => 'decker_kb',
				'post_title' => 'Child Article',
				'post_content' => 'Child Content',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'menu_order' => 1,
			)
		);

		$exported_posts = $this->export_instance->export_post_type( 'decker_kb' );
		$this->validate_kb_export( $exported_posts, $parent_id, $child_id );
	}

	private function test_export_event() {
		$event_id = wp_insert_post(
			array(
				'post_type' => 'decker_event',
				'post_title' => 'Test Event',
				'post_content' => 'Event Content',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $event_id, 'event_allday', true );
		update_post_meta( $event_id, 'event_start', '2024-12-20T00:00:00' );
		update_post_meta( $event_id, 'event_end', '2024-12-21T00:00:00' );
		update_post_meta( $event_id, 'event_category', 'bg-success' );

		$exported_posts = $this->export_instance->export_post_type( 'decker_event' );
		$this->validate_event_export( $exported_posts, $event_id );
	}

	private function validate_task_export( $exported_posts, $post_id ) {
		$this->assertIsArray( $exported_posts );
		$this->assertCount( 1, $exported_posts );

		$exported_post = $exported_posts[0];
		$this->assertEquals( $post_id, $exported_post['ID'] );
		$this->assertEquals( 'Test Task', $exported_post['post_title'] );
		$this->assertEquals( 'Test Content', $exported_post['post_content'] );

		$this->assertArrayHasKey( 'post_meta', $exported_post );
		$this->assertEquals( '1', $exported_post['post_meta']['max_priority'][0] );
		$this->assertEquals( '2024-12-20', $exported_post['post_meta']['duedate'][0] );

		$this->assertArrayHasKey( 'decker_board', $exported_post );
		$this->assertNotEmpty( $exported_post['decker_board'] );
		$this->assertEquals( 'Test Board', $exported_post['decker_board'][0]->name );
	}

	private function validate_kb_export( $exported_posts, $parent_id, $child_id ) {
		$this->assertIsArray( $exported_posts );
		$this->assertCount( 2, $exported_posts );

		$parent = array_filter(
			$exported_posts,
			function ( $post ) use ( $parent_id ) {
				return $post['ID'] === $parent_id;
			}
		);
		$parent = reset( $parent );

		$child = array_filter(
			$exported_posts,
			function ( $post ) use ( $child_id ) {
				return $post['ID'] === $child_id;
			}
		);
		$child = reset( $child );

		$this->assertEquals( 'Parent Article', $parent['post_title'] );
		$this->assertEquals( 'Child Article', $child['post_title'] );
		$this->assertEquals( $parent_id, $child['post_parent'] );
		$this->assertEquals( 1, $child['menu_order'] );
	}

	private function validate_event_export( $exported_posts, $event_id ) {
		$this->assertIsArray( $exported_posts );
		$this->assertCount( 1, $exported_posts );

		$exported_post = $exported_posts[0];
		$this->assertEquals( $event_id, $exported_post['ID'] );
		$this->assertEquals( 'Test Event', $exported_post['post_title'] );

		$this->assertArrayHasKey( 'post_meta', $exported_post );
		$this->assertEquals( '1', $exported_post['post_meta']['event_allday'][0] );
		$this->assertEquals( '2024-12-20T00:00:00', $exported_post['post_meta']['event_start'][0] );
		$this->assertEquals( '2024-12-21T00:00:00', $exported_post['post_meta']['event_end'][0] );
		$this->assertEquals( 'bg-success', $exported_post['post_meta']['event_category'][0] );
	}

	/**
	 * Test taxonomy terms export functionality
	 *
	 * @return void
	 */
	public function test_export_taxonomy_terms() {
		// Create a test board term.
		$board_term_id = $this->factory->board->create(
			array(
				'name'       => 'Test Board',
				'description' => 'Test Board Description',
				'slug'       => 'test-board',
			)
		);

		// Create another board.
		$this->factory->board->create();

		$board_term = get_term( $board_term_id, 'decker_board' );
		$this->assertNotWPError( $board_term, 'Failed to retrieve board term.' );

		// Add board meta.
		add_term_meta( $board_term->term_id, 'board_color', '#FF5733' );
		add_term_meta( $board_term->term_id, 'board_order', '1' );

		// Export the taxonomy terms directly (without reflection).
		$exported_terms = $this->export_instance->export_taxonomy_terms( 'decker_board' );

		// Assertions.
		$this->assertIsArray( $exported_terms, 'Exported terms should be an array.' );
		$this->assertCount( 2, $exported_terms, 'There should be exactly two exported terms.' );
	}
}
