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

		$comment_id = wp_insert_comment(
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
		$request->set_param( 'include_comments', true );

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( $this->administrator, $data['article']['author']['id'] );
		$this->assertEquals( $this->editor, $data['article']['last_editor']['id'] );
		$this->assertEquals( 1, intval( $data['article']['comment_count'] ) );
		$this->assertCount( 1, $data['article']['comments'] );
		$this->assertEquals( $comment_id, $data['article']['comments'][0]['id'] );
		$this->assertEquals( 'First KB comment', wp_strip_all_tags( $data['article']['comments'][0]['content_rendered'] ) );
		$this->assertTrue( $data['article']['comments'][0]['can_delete'] );
		$this->assertGreaterThan( 0, intval( $data['article']['revision_count'] ) );
		$this->assertArrayNotHasKey( 'comments', $data['article']['links'] );
		$this->assertStringContainsString(
			'revision.php?revision=',
			$data['article']['links']['history']
		);
	}

	public function test_get_article_returns_article_data() {
		wp_set_current_user( $this->administrator );

		$board = wp_insert_term(
			'Knowledge Board',
			'decker_board',
			array(
				'slug' => 'knowledge-board',
			)
		);

		$label = wp_insert_term(
			'Knowledge Label',
			'decker_label',
			array(
				'slug' => 'knowledge-label',
			)
		);

		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Parent Article',
				'post_status' => 'publish',
			)
		);

		$article_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_title'   => 'Child Article',
				'post_content' => '<p>Article content</p>',
				'post_parent'  => $parent_id,
				'post_status'  => 'publish',
				'menu_order'   => 3,
			)
		);

		wp_set_object_terms(
			$article_id,
			array( $board['term_id'] ),
			'decker_board'
		);
		wp_set_object_terms(
			$article_id,
			array( $label['term_id'] ),
			'decker_label'
		);

		$request = new WP_REST_Request( 'GET', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( $article_id, $data['article']['id'] );
		$this->assertEquals( 'Child Article', $data['article']['title'] );
		$this->assertEquals(
			'<p>Article content</p>',
			$data['article']['content']
		);
		$this->assertEquals( $parent_id, $data['article']['parent_id'] );
		$this->assertEquals( 3, $data['article']['menu_order'] );
		$this->assertEquals( $board['term_id'], $data['article']['board'] );
		$this->assertEquals(
			array( $label['term_id'] ),
			$data['article']['labels']
		);
	}

	public function test_update_article_without_board_keeps_existing_board() {
		wp_set_current_user( $this->administrator );

		$board = wp_insert_term(
			'Existing Board',
			'decker_board',
			array(
				'slug' => 'existing-board',
			)
		);

		$article_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_title'   => 'Original Article',
				'post_content' => 'Original content',
				'post_status'  => 'publish',
			)
		);

		wp_set_object_terms(
			$article_id,
			array( $board['term_id'] ),
			'decker_board'
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'title', 'Updated Article' );
		$request->set_param( 'content', '<p>Updated content</p>' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$terms    = wp_get_object_terms( $article_id, 'decker_board' );
		$article  = get_post( $article_id );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'Updated Article', $article->post_title );
		$this->assertEquals( '<p>Updated content</p>', $article->post_content );
		$this->assertCount( 1, $terms );
		$this->assertEquals( $board['term_id'], $terms[0]->term_id );
	}

	public function test_reorder_articles_updates_parent_and_order() {
		wp_set_current_user( $this->administrator );

		$old_parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Old Parent',
				'post_status' => 'publish',
			)
		);

		$new_parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'New Parent',
				'post_status' => 'publish',
			)
		);

		$old_sibling_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Old Sibling',
				'post_parent' => $old_parent_id,
				'post_status' => 'publish',
				'menu_order'  => 0,
			)
		);

		$moved_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Moved Article',
				'post_parent' => $old_parent_id,
				'post_status' => 'publish',
				'menu_order'  => 1,
			)
		);

		$new_first_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'New First',
				'post_parent' => $new_parent_id,
				'post_status' => 'publish',
				'menu_order'  => 0,
			)
		);

		$new_last_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'New Last',
				'post_parent' => $new_parent_id,
				'post_status' => 'publish',
				'menu_order'  => 1,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb/reorder' );
		$request->set_param( 'moved_id', $moved_id );
		$request->set_param( 'new_parent_id', $new_parent_id );
		$request->set_param(
			'new_order',
			array( $new_first_id, $moved_id, $new_last_id )
		);
		$request->set_param( 'old_parent_id', $old_parent_id );
		$request->set_param( 'old_order', array( $old_sibling_id ) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$moved    = get_post( $moved_id );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals( $new_parent_id, $moved->post_parent );
		$this->assertEquals( 0, get_post( $new_first_id )->menu_order );
		$this->assertEquals( 1, get_post( $moved_id )->menu_order );
		$this->assertEquals( 2, get_post( $new_last_id )->menu_order );
		$this->assertEquals( 0, get_post( $old_sibling_id )->menu_order );
	}

	public function test_get_article_comments_data_sets_can_delete_for_author_and_moderator() {
		wp_set_current_user( $this->administrator );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'KB Comment Permissions Test',
				'post_status' => 'publish',
			)
		);

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'  => $post_id,
				'comment_content'  => 'Permission check comment',
				'user_id'          => $this->editor,
				'comment_approved' => 1,
			)
		);

		wp_set_current_user( $this->editor );
		$author_comments = Decker_Kb::get_article_comments_data( $post_id );
		$this->assertTrue( $author_comments[0]['can_delete'] );

		wp_set_current_user( $this->administrator );
		$moderator_comments = Decker_Kb::get_article_comments_data( $post_id );
		$this->assertTrue( $moderator_comments[0]['can_delete'] );

		$subscriber_id = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber_id );
		$subscriber_comments = Decker_Kb::get_article_comments_data( $post_id );
		$this->assertFalse( $subscriber_comments[0]['can_delete'] );

		wp_delete_comment( $comment_id, true );
		wp_delete_user( $subscriber_id );
	}

	public function test_save_article_rejects_update_of_non_kb_post() {
		wp_set_current_user( $this->administrator );

		// A regular post that must never be convertible to a KB article.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Untouchable Post',
				'post_status' => 'draft',
				'post_author' => $this->administrator,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'title', 'Hijacked Title' );
		$request->set_param( 'content', '<p>Hijacked content</p>' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 404, $response->get_status() );
		$this->assertFalse( $data['success'] );

		// The original post must remain completely unchanged.
		$post = get_post( $post_id );
		$this->assertEquals( 'post', $post->post_type );
		$this->assertEquals( 'Untouchable Post', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );
	}

	public function test_save_article_rejects_update_without_edit_permission() {
		// Create a KB article owned by the administrator.
		wp_set_current_user( $this->administrator );

		$article_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_title'   => 'Protected Article',
				'post_content' => 'Protected content',
				'post_status'  => 'publish',
				'post_author'  => $this->administrator,
			)
		);

		// A contributor cannot edit another user's published article.
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'title', 'Tampered Title' );
		$request->set_param( 'content', '<p>Tampered content</p>' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 403, $response->get_status() );
		$this->assertFalse( $data['success'] );

		// The article must remain unchanged.
		$article = get_post( $article_id );
		$this->assertEquals( 'Protected Article', $article->post_title );
		$this->assertEquals( 'Protected content', $article->post_content );

		wp_delete_user( $contributor );
	}

	public function test_save_article_updates_existing_kb_article() {
		wp_set_current_user( $this->administrator );

		$article_id = self::factory()->post->create(
			array(
				'post_type'    => 'decker_kb',
				'post_title'   => 'Legit Article',
				'post_content' => 'Legit content',
				'post_status'  => 'publish',
				'post_author'  => $this->administrator,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'title', 'Legit Article Updated' );
		$request->set_param( 'content', '<p>Legit content updated</p>' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );

		$article = get_post( $article_id );
		$this->assertEquals( 'decker_kb', $article->post_type );
		$this->assertEquals( 'Legit Article Updated', $article->post_title );
		$this->assertEquals( '<p>Legit content updated</p>', $article->post_content );
	}

	/**
	 * Helper to create a KB article quickly.
	 *
	 * @param array $args Overrides for the post array.
	 * @return int Post ID.
	 */
	private function create_kb_article( $args = array() ) {
		return self::factory()->post->create(
			wp_parse_args(
				$args,
				array(
					'post_type'   => 'decker_kb',
					'post_title'  => 'KB Article',
					'post_status' => 'publish',
				)
			)
		);
	}

	public function test_save_article_update_without_parent_or_order_preserves_hierarchy() {
		wp_set_current_user( $this->administrator );

		$parent_id = $this->create_kb_article( array( 'post_title' => 'Parent P' ) );
		$article_a = $this->create_kb_article(
			array(
				'post_title'  => 'Article A',
				'post_parent' => $parent_id,
				'menu_order'  => 2,
			)
		);
		$sibling_s = $this->create_kb_article(
			array(
				'post_title'  => 'Sibling S',
				'post_parent' => $parent_id,
				'menu_order'  => 5,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_a );
		$request->set_param( 'title', 'Article A Updated' );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $parent_id, get_post( $article_a )->post_parent );
		$this->assertEquals( 2, get_post( $article_a )->menu_order );
		$this->assertEquals( 5, get_post( $sibling_s )->menu_order );
	}

	public function test_save_article_with_menu_order_repositions_and_reindexes_siblings() {
		wp_set_current_user( $this->administrator );

		$parent_id = $this->create_kb_article( array( 'post_title' => 'Parent P' ) );
		$child_a   = $this->create_kb_article(
			array(
				'post_title'  => 'Child A',
				'post_parent' => $parent_id,
				'menu_order'  => 0,
			)
		);
		$child_b = $this->create_kb_article(
			array(
				'post_title'  => 'Child B',
				'post_parent' => $parent_id,
				'menu_order'  => 1,
			)
		);
		$child_c = $this->create_kb_article(
			array(
				'post_title'  => 'Child C',
				'post_parent' => $parent_id,
				'menu_order'  => 2,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $child_c );
		$request->set_param( 'menu_order', 0 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, get_post( $child_c )->menu_order );
		$this->assertEquals( 1, get_post( $child_a )->menu_order );
		$this->assertEquals( 2, get_post( $child_b )->menu_order );
	}

	public function test_save_article_menu_order_clamped_to_sibling_count() {
		wp_set_current_user( $this->administrator );

		$parent_id = $this->create_kb_article( array( 'post_title' => 'Parent P' ) );
		$child_a   = $this->create_kb_article(
			array(
				'post_title'  => 'Child A',
				'post_parent' => $parent_id,
				'menu_order'  => 0,
			)
		);
		$child_b = $this->create_kb_article(
			array(
				'post_title'  => 'Child B',
				'post_parent' => $parent_id,
				'menu_order'  => 1,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $child_a );
		$request->set_param( 'menu_order', 99 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 1, get_post( $child_a )->menu_order );
		$this->assertEquals( 0, get_post( $child_b )->menu_order );
	}

	public function test_save_article_parent_change_recalculates_old_and_new_siblings() {
		wp_set_current_user( $this->administrator );

		$parent1 = $this->create_kb_article( array( 'post_title' => 'Parent 1' ) );
		$parent2 = $this->create_kb_article( array( 'post_title' => 'Parent 2' ) );

		$child_a = $this->create_kb_article(
			array(
				'post_title'  => 'Child A',
				'post_parent' => $parent1,
				'menu_order'  => 0,
			)
		);
		$moved = $this->create_kb_article(
			array(
				'post_title'  => 'Moved M',
				'post_parent' => $parent1,
				'menu_order'  => 1,
			)
		);
		$child_b = $this->create_kb_article(
			array(
				'post_title'  => 'Child B',
				'post_parent' => $parent1,
				'menu_order'  => 2,
			)
		);
		$child_x = $this->create_kb_article(
			array(
				'post_title'  => 'Child X',
				'post_parent' => $parent2,
				'menu_order'  => 0,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $moved );
		$request->set_param( 'parent_id', $parent2 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $parent2, get_post( $moved )->post_parent );
		$this->assertEquals( 0, get_post( $child_x )->menu_order );
		$this->assertEquals( 1, get_post( $moved )->menu_order );
		$this->assertEquals( 0, get_post( $child_a )->menu_order );
		$this->assertEquals( 1, get_post( $child_b )->menu_order );
	}

	public function test_save_article_create_with_unresolvable_board_deletes_post_and_returns_400() {
		wp_set_current_user( $this->administrator );

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'title', 'Ghost Article' );
		$request->set_param( 'board', -1 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Invalid board ID', $data['message'] );

		$found = get_posts(
			array(
				'post_type'   => 'decker_kb',
				'post_status' => 'any',
				'title'       => 'Ghost Article',
				'numberposts' => -1,
			)
		);
		$this->assertCount( 0, $found );
	}

	public function test_save_article_create_with_zero_board_returns_board_required() {
		wp_set_current_user( $this->administrator );

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'title', 'Zero Board' );
		$request->set_param( 'board', 0 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Board is required', $data['message'] );
	}

	public function test_save_article_update_with_invalid_board_keeps_board_and_succeeds() {
		wp_set_current_user( $this->administrator );

		$board = wp_insert_term(
			'Kept Board',
			'decker_board',
			array(
				'slug' => 'kept-board',
			)
		);

		$article_id = $this->create_kb_article( array( 'post_title' => 'Article With Board' ) );
		wp_set_object_terms( $article_id, array( $board['term_id'] ), 'decker_board' );

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'board', -1 );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertNotNull( get_post( $article_id ) );

		$terms = wp_get_object_terms( $article_id, 'decker_board', array( 'fields' => 'ids' ) );
		$this->assertEquals( array( $board['term_id'] ), $terms );
	}

	public function test_save_article_labels_replaced_kept_or_cleared() {
		wp_set_current_user( $this->administrator );

		$label1 = wp_insert_term( 'Label One', 'decker_label', array( 'slug' => 'label-one' ) );
		$label2 = wp_insert_term( 'Label Two', 'decker_label', array( 'slug' => 'label-two' ) );

		$article_id = $this->create_kb_article( array( 'post_title' => 'Labelled Article' ) );
		wp_set_object_terms( $article_id, array( $label1['term_id'] ), 'decker_label' );

		// (1) Replace labels with L2.
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'labels', array( $label2['term_id'] ) );
		rest_do_request( $request );
		$this->assertEquals(
			array( $label2['term_id'] ),
			wp_get_object_terms( $article_id, 'decker_label', array( 'fields' => 'ids' ) )
		);

		// (2) Update without labels param keeps L2.
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'title', 'Labelled Article Touched' );
		rest_do_request( $request );
		$this->assertEquals(
			array( $label2['term_id'] ),
			wp_get_object_terms( $article_id, 'decker_label', array( 'fields' => 'ids' ) )
		);

		// (3) Empty labels array clears all labels.
		$request = new WP_REST_Request( 'POST', '/decker/v1/kb' );
		$request->set_param( 'id', $article_id );
		$request->set_param( 'labels', array() );
		rest_do_request( $request );
		$this->assertEmpty(
			wp_get_object_terms( $article_id, 'decker_label', array( 'fields' => 'ids' ) )
		);
	}

	public function test_reorder_articles_without_moved_id_returns_400() {
		wp_set_current_user( $this->administrator );

		$parent_id  = $this->create_kb_article( array( 'post_title' => 'Parent' ) );
		$article_id = $this->create_kb_article(
			array(
				'post_title'  => 'Untouched Article',
				'post_parent' => $parent_id,
				'menu_order'  => 4,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb/reorder' );
		$request->set_param( 'new_parent_id', $parent_id );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertEquals( 400, $response->get_status() );
		$this->assertFalse( $data['success'] );
		$this->assertEquals( 'Invalid moved ID.', $data['message'] );

		$this->assertEquals( $parent_id, get_post( $article_id )->post_parent );
		$this->assertEquals( 4, get_post( $article_id )->menu_order );
	}

	public function test_reorder_articles_without_old_parent_skips_old_sibling_reindex() {
		wp_set_current_user( $this->administrator );

		$parent1 = $this->create_kb_article( array( 'post_title' => 'Parent 1' ) );
		$parent2 = $this->create_kb_article( array( 'post_title' => 'Parent 2' ) );

		$moved = $this->create_kb_article(
			array(
				'post_title'  => 'Moved M',
				'post_parent' => $parent1,
				'menu_order'  => 0,
			)
		);
		$sibling = $this->create_kb_article(
			array(
				'post_title'  => 'Sibling S',
				'post_parent' => $parent1,
				'menu_order'  => 7,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb/reorder' );
		$request->set_param( 'moved_id', $moved );
		$request->set_param( 'new_parent_id', $parent2 );
		$request->set_param( 'new_order', array( $moved ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( $parent2, get_post( $moved )->post_parent );
		$this->assertEquals( 7, get_post( $sibling )->menu_order );
	}

	public function test_reorder_articles_with_old_parent_and_empty_old_order_reindexes_sequentially() {
		wp_set_current_user( $this->administrator );

		$parent1 = $this->create_kb_article( array( 'post_title' => 'Parent 1' ) );
		$parent2 = $this->create_kb_article( array( 'post_title' => 'Parent 2' ) );

		$moved = $this->create_kb_article(
			array(
				'post_title'  => 'Moved M',
				'post_parent' => $parent1,
				'menu_order'  => 0,
			)
		);
		$sibling = $this->create_kb_article(
			array(
				'post_title'  => 'Sibling S',
				'post_parent' => $parent1,
				'menu_order'  => 7,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb/reorder' );
		$request->set_param( 'moved_id', $moved );
		$request->set_param( 'new_parent_id', $parent2 );
		$request->set_param( 'new_order', array( $moved ) );
		$request->set_param( 'old_parent_id', $parent1 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, get_post( $sibling )->menu_order );
		$this->assertEquals(
			$this->administrator,
			intval( get_post_meta( $sibling, '_last_editor', true ) )
		);
	}

	public function test_reorder_articles_to_root_reparents_all_ids_in_new_order() {
		wp_set_current_user( $this->administrator );

		$parent1 = $this->create_kb_article( array( 'post_title' => 'Parent 1' ) );

		$root1 = $this->create_kb_article(
			array(
				'post_title'  => 'Root 1',
				'post_parent' => 0,
				'menu_order'  => 5,
			)
		);
		$root2 = $this->create_kb_article(
			array(
				'post_title'  => 'Root 2',
				'post_parent' => 0,
				'menu_order'  => 9,
			)
		);
		$moved = $this->create_kb_article(
			array(
				'post_title'  => 'Moved M',
				'post_parent' => $parent1,
				'menu_order'  => 0,
			)
		);

		$request = new WP_REST_Request( 'POST', '/decker/v1/kb/reorder' );
		$request->set_param( 'moved_id', $moved );
		$request->set_param( 'new_parent_id', 0 );
		$request->set_param( 'new_order', array( $root1, $moved, $root2 ) );
		$request->set_param( 'old_parent_id', $parent1 );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 0, get_post( $root1 )->post_parent );
		$this->assertEquals( 0, get_post( $moved )->post_parent );
		$this->assertEquals( 0, get_post( $root2 )->post_parent );
		$this->assertEquals( 0, get_post( $root1 )->menu_order );
		$this->assertEquals( 1, get_post( $moved )->menu_order );
		$this->assertEquals( 2, get_post( $root2 )->menu_order );
	}
}
