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
		$this->assertEquals( '<p>Article content</p>', $data['article']['content'] );
		$this->assertEquals( $parent_id, $data['article']['parent_id'] );
		$this->assertEquals( 3, $data['article']['menu_order'] );
		$this->assertEquals( $board['term_id'], $data['article']['board'] );
		$this->assertEquals( array( $label['term_id'] ), $data['article']['labels'] );
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
}
