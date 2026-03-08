<?php
/**
 * Class Test_Decker_Knowledge_Base
 *
 * @package Decker
 */

class DeckerKnowledgeBaseTest extends WP_Test_REST_TestCase {

	private $administrator;
	private $editor;

	public function set_up() {
		parent::set_up();

		// Initialize REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create test users
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function test_post_type_registration() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertNotNull( $post_type );
		$this->assertEquals( 'decker_kb', $post_type->name );
		$this->assertTrue( $post_type->hierarchical );
	}

	public function test_rest_api_integration() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/decker_kb', $routes );
	}

	public function test_hierarchical_structure() {
		wp_set_current_user( $this->administrator );

		// Create parent article
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Parent Article',
				'post_status' => 'publish',
			)
		);

		// Create child article
		$child_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Child Article',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$child_post = get_post( $child_id );
		$this->assertEquals( $parent_id, $child_post->post_parent );
	}

	public function test_board_taxonomy_connection() {
		$taxonomy = get_taxonomy( 'decker_board' );
		$this->assertContains( 'decker_kb', $taxonomy->object_type );
	}

	public function test_board_required_for_kb_article() {
		wp_set_current_user( $this->administrator );

		// Create a board
		$board_id = wp_insert_term(
			'Required Board',
			'decker_board',
			array(
				'slug' => 'required-board',
			)
		);

		// Test the REST API endpoint with missing board
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'title', 'Test Article' );
		$request->set_param( 'content', 'Test content' );

		$response = rest_do_request( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Board is required', $data['message'] );

		// Test with valid board
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'title', 'Test Article' );
		$request->set_param( 'content', 'Test content' );
		$request->set_param( 'board', $board_id['term_id'] );

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		// Verify the article was created with the board
		$article_id = $data['id'];
		$terms      = wp_get_object_terms( $article_id, 'decker_board' );
		$this->assertCount( 1, $terms );
		$this->assertEquals( $board_id['term_id'], $terms[0]->term_id );
	}

	public function test_get_articles_with_board_filter() {
		wp_set_current_user( $this->administrator );

		// Create a board
		$board_id = wp_insert_term(
			'Test Board',
			'decker_board',
			array(
				'slug' => 'test-board',
			)
		);

		// Create another board for testing
		$board2_id = wp_insert_term(
			'Test Board 2',
			'decker_board',
			array(
				'slug' => 'test-board-2',
			)
		);

		// Create an article with the first board
		$article_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Test Article with Board',
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $article_id, array( $board_id['term_id'] ), 'decker_board' );

		// Create another article with the second board
		$article2_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Test Article with Board 2',
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $article2_id, array( $board2_id['term_id'] ), 'decker_board' );

		// Test filtering by board
		$args = array(
			'tax_query' => array(
				array(
					'taxonomy' => 'decker_board',
					'field'    => 'slug',
					'terms'    => 'test-board',
				),
			),
		);

		$filtered_articles = Decker_Kb::get_articles( $args );

		// Should only return the article with the board
		$this->assertEquals( 1, count( $filtered_articles ) );
		$this->assertEquals( $article_id, $filtered_articles[0]->ID );
	}

	public function test_post_type_supports_comments() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertNotNull( $post_type );
		$this->assertTrue(
			post_type_supports( 'decker_kb', 'comments' ),
			'decker_kb should support comments'
		);
	}

	public function test_post_type_supports_revisions() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertNotNull( $post_type );
		$this->assertTrue(
			post_type_supports( 'decker_kb', 'revisions' ),
			'decker_kb should support revisions'
		);
	}

	public function test_post_type_supports_author() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertNotNull( $post_type );
		$this->assertTrue(
			post_type_supports( 'decker_kb', 'author' ),
			'decker_kb should support author'
		);
	}

	public function test_last_editor_tracked_on_save() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Track Editor Test',
				'post_status' => 'publish',
			)
		);

		$last_editor = get_post_meta( $post_id, '_last_editor', true );
		$this->assertEquals( $this->administrator, intval( $last_editor ) );
	}

	public function test_last_editor_updates_on_edit() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Editor Update Test',
				'post_status' => 'publish',
			)
		);

		// Switch to a different user and update the post.
		wp_set_current_user( $this->editor );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Editor Update Test - Updated',
			)
		);

		$last_editor = get_post_meta( $post_id, '_last_editor', true );
		$this->assertEquals( $this->editor, intval( $last_editor ) );
	}

	public function test_get_last_editor_returns_meta_value() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Get Last Editor Test',
				'post_status' => 'publish',
				'post_author' => $this->administrator,
			)
		);

		// Update as editor.
		wp_set_current_user( $this->editor );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Get Last Editor Test - Updated',
			)
		);

		$last_editor_id = Decker_Kb::get_last_editor( $post_id );
		$this->assertEquals( $this->editor, $last_editor_id );
	}

	public function test_get_last_editor_fallback_to_post_author() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Fallback Author Test',
				'post_status' => 'publish',
				'post_author' => $this->administrator,
			)
		);

		// Remove the meta to simulate an article created before this feature.
		delete_post_meta( $post_id, '_last_editor' );

		$last_editor_id = Decker_Kb::get_last_editor( $post_id );
		$this->assertEquals( $this->administrator, $last_editor_id );
	}

	public function test_get_comments_admin_url_points_to_edit_screen_comments() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_status' => 'publish',
			)
		);

		$comments_url = Decker_Kb::get_comments_admin_url( $post_id );

		$this->assertStringContainsString(
			'post.php?post=' . $post_id . '&action=edit#commentsdiv',
			$comments_url
		);
	}

	public function test_get_revision_admin_url_points_to_revision_screen() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Revision Link Test',
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Revision Link Test Updated',
				'post_content' => 'Updated content',
			)
		);

		$revision_id  = Decker_Kb::get_latest_revision_id( $post_id );
		$revision_url = Decker_Kb::get_revision_admin_url( $post_id );

		$this->assertGreaterThan( 0, $revision_id );
		$this->assertStringContainsString(
			'revision.php?revision=' . $revision_id,
			$revision_url
		);
	}

	public function test_get_article_returns_author_comments_and_history_metadata() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_title'   => 'KB Metadata Test',
				'post_content' => 'Initial content',
				'post_status'  => 'publish',
				'post_author'  => $this->administrator,
			)
		);

		wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'First KB comment',
				'user_id'         => $this->editor,
				'comment_approved' => 1,
			)
		);

		wp_set_current_user( $this->editor );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'KB Metadata Test Updated',
				'post_content' => 'Updated content',
			)
		);

		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', $post_id );

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $this->administrator, $data['article']['author']['id'] );
		$this->assertEquals( $this->editor, $data['article']['last_editor']['id'] );
		$this->assertEquals( 1, intval( $data['article']['comment_count'] ) );
		$this->assertGreaterThan( 0, intval( $data['article']['revision_count'] ) );
		$this->assertStringContainsString(
			'post.php?post=' . $post_id . '&action=edit#commentsdiv',
			$data['article']['links']['comments']
		);
		$this->assertStringContainsString(
			'revision.php?revision=',
			$data['article']['links']['history']
		);
	}
}
