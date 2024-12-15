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
	private $admin_user_id;

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

		// Delete all terms from decker_board before running tests to ensure a clean environment.
		$existing_terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
			foreach ( $existing_terms as $term ) {
				wp_delete_term( $term->term_id, 'decker_board' );
			}
		}
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
		$result = wp_insert_term( 'Board TEST 1', 'decker_board' );

		if ( is_wp_error( $result ) ) {
			error_log( 'Error inserting term: ' . $result->get_error_message() );
			$this->fail( 'wp_insert_term failed: ' . $result->get_error_message() );
		}

		$board_id = $result['term_id'];

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'decker_task',
				'post_title'   => 'Test Task',
				'post_content' => 'Test Content',
				'post_status'  => 'publish',
				'tax_input'    => array(
					'decker_board' => array( $board_id ),
				),
				'meta_input'   => array(
					'stack' => 'to-do',
				),
			)
		);

		// Add test meta data.
		add_post_meta( $post_id, 'priority', 'high' );
		add_post_meta( $post_id, 'due_date', '2024-12-20' );

		// Create and assign a board.
		$board_term = wp_insert_term( 'Test Board', 'decker_board' );
		if ( is_wp_error( $board_term ) ) {
			$this->fail( 'Failed to create board term: ' . $board_term->get_error_message() );
		}
		wp_set_object_terms( $post_id, array( $board_term['term_id'] ), 'decker_board' );

		// Create and assign a label.
		$label_term = wp_insert_term( 'Test Label', 'decker_label' );
		if ( is_wp_error( $label_term ) ) {
			$this->fail( 'Failed to create label term: ' . $label_term->get_error_message() );
		}
		wp_set_object_terms( $post_id, array( $label_term['term_id'] ), 'decker_label' );

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
		$this->assertEquals( 'high', $exported_post['post_meta']['priority'][0], 'Post meta priority does not match.' );
		$this->assertEquals( '2024-12-20', $exported_post['post_meta']['due_date'][0], 'Post meta due_date does not match.' );

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
		$board_term_id = $this->factory->term->create(
			array(
				'taxonomy'   => 'decker_board',
				'name'       => 'Test Board',
				'description' => 'Test Board Description',
				'slug'       => 'test-board',
			)
		);

		$board_term = get_term( $board_term_id, 'decker_board' );
		$this->assertNotWPError( $board_term, 'Failed to retrieve board term.' );

		// Add board meta.
		add_term_meta( $board_term->term_id, 'board_color', '#FF5733' );
		add_term_meta( $board_term->term_id, 'board_order', '1' );

		// Create a child board.
		$child_board_id = $this->factory->term->create(
			array(
				'taxonomy'   => 'decker_board',
				'name'       => 'Child Board',
				'description' => 'Child Board Description',
				'slug'       => 'child-board',
				'parent'     => $board_term->term_id,
			)
		);

		$child_board = get_term( $child_board_id, 'decker_board' );
		$this->assertNotWPError( $child_board, 'Failed to retrieve child board term.' );

		// Export the taxonomy terms directly (without reflection).
		$exported_terms = $this->export_instance->export_taxonomy_terms( 'decker_board' );

		// Assertions.
		$this->assertIsArray( $exported_terms, 'Exported terms should be an array.' );
		$this->assertCount( 2, $exported_terms, 'There should be exactly two exported terms.' );

		// Locate parent board term.
		$parent_term = null;
		foreach ( $exported_terms as $term ) {
			// $this->assertInstanceOf(WP_Term::class, $term, 'Each exported term should be a WP_Term instance.');
			if ( $term['term_id'] === $board_term->term_id ) {
				$parent_term = $term;
				break;
			}
		}
		$this->assertNotNull( $parent_term, 'Parent term not found in exported terms.' );

		// Check basic term data.
		$this->assertEquals( $board_term->term_id, $parent_term['term_id'], 'Parent term ID does not match.' );
		$this->assertEquals( 'Test Board', $parent_term['name'], 'Parent term name does not match.' );
		$this->assertEquals( 'test-board', $parent_term['slug'], 'Parent term slug does not match.' );
		$this->assertEquals( 'Test Board Description', $parent_term['description'], 'Parent term description does not match.' );

		// Check term meta.
		// $this->assertTrue(property_exists($parent_term, 'term_meta'), 'Parent term should have term_meta property.');
		$this->assertEquals( '#FF5733', $parent_term['term_meta']['board_color'][0], 'Parent term meta board_color does not match.' );
		$this->assertEquals( '1', $parent_term['term_meta']['board_order'][0], 'Parent term meta board_order does not match.' );

		// Locate child board term.
		$child_term = null;
		foreach ( $exported_terms as $term ) {
			if ( $term['term_id'] === $child_board->term_id ) {
				$child_term = $term;
				break;
			}
		}
		$this->assertNotNull( $child_term, 'Child term not found in exported terms.' );

		// Check child term data.
		$this->assertEquals( $child_board->term_id, $child_term['term_id'], 'Child term ID does not match.' );
		$this->assertEquals( 'Child Board', $child_term['name'], 'Child term name does not match.' );
		$this->assertEquals( $board_term->term_id, $child_term['parent'], 'Child term parent does not match.' );
	}
}
