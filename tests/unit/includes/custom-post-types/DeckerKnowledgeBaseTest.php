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
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
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
	
	public function test_get_articles_with_board_filter() {
		wp_set_current_user( $this->administrator );
		
		// Create a board
		$board_id = wp_insert_term( 'Test Board', 'decker_board', array(
			'slug' => 'test-board'
		) );
		
		// Create an article with the board
		$article_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Test Article with Board',
				'post_status' => 'publish',
			)
		);
		
		wp_set_object_terms( $article_id, array( $board_id['term_id'] ), 'decker_board' );
		
		// Create another article without the board
		$article2_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_kb',
				'post_title'  => 'Test Article without Board',
				'post_status' => 'publish',
			)
		);
		
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
		
		$filtered_articles = Decker_Kb::get_articles($args);
		
		// Should only return the article with the board
		$this->assertEquals(1, count($filtered_articles));
		$this->assertEquals($article_id, $filtered_articles[0]->ID);
	}
}
