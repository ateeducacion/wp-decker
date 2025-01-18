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
	public function test_export_post_type() {

		// Ensure 'save_decker_task' matches your plugin action.
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create terms for boards and labels.
		$board_id = $this->factory->board->create( array( 'name' => 'Test Board' ) );

		// Create a test post.
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

		// Export the post type directly (without reflection).
		$exported_posts = $this->export_instance->export_post_type( 'decker_task' );

		// Assertions.
		$this->assertIsArray( $exported_posts, 'Exported posts should be an array.' );
		$this->assertCount( 1, $exported_posts, 'There should be exactly one exported post.' );

		$exported_post = $exported_posts[0];

		// Check basic post data.
		$this->assertEquals( $post_id, $exported_post['ID'], 'Post ID does not match.' );
		$this->assertEquals( 'Test Task', $exported_post['post_title'], 'Post title does not match.' );
		$this->assertEquals( 'Test Content', $exported_post['post_content'], 'Post content does not match.' );

		// Check meta data.
		$this->assertArrayHasKey( 'post_meta', $exported_post, 'Exported post should have post_meta.' );
		$this->assertEquals( '1', $exported_post['post_meta']['max_priority'][0], 'Post meta priority does not match.' );
		$this->assertEquals( '2024-12-20', $exported_post['post_meta']['duedate'][0], 'Post meta due_date does not match.' );

		// Check taxonomies.
		$this->assertArrayHasKey( 'decker_board', $exported_post, 'Exported post should have decker_board taxonomy.' );
		$this->assertNotEmpty( $exported_post['decker_board'], 'Exported post should have at least one decker_board term.' );
		$this->assertInstanceOf( WP_Term::class, $exported_post['decker_board'][0], 'decker_board term should be a WP_Term instance.' );
		$this->assertEquals( 'Test Board', $exported_post['decker_board'][0]->name, 'Board term name does not match.' );

		$this->assertArrayHasKey( 'decker_label', $exported_post, 'Exported post should have decker_label taxonomy.' );
		$this->assertNotEmpty( $exported_post['decker_label'], 'Exported post should have at least one decker_label term.' );
		$this->assertInstanceOf( WP_Term::class, $exported_post['decker_label'][0], 'decker_label term should be a WP_Term instance.' );
		$this->assertEquals( 'Test Label', $exported_post['decker_label'][0]->name, 'Label term name does not match.' );
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
