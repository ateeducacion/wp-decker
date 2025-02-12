<?php
 /**
  * Class DeckerKnowledgeBaseIntegrationTest
  *
  * @package Decker
  */

class DeckerKnowledgeBaseIntegrationTest extends Decker_Test_Base {

	public function test_cpt_registration() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertTrue( post_type_exists( 'decker_kb' ) );
		$this->assertEquals( 'Knowledge Base', $post_type->label );
	}

	public function test_taxonomy_connection() {
		$taxonomy = get_taxonomy( 'decker_label' );
		$this->assertContains( 'decker_kb', $taxonomy->object_type );
	}

	public function test_editor_support() {
		$post_type = get_post_type_object( 'decker_kb' );
		$this->assertTrue( post_type_supports( 'decker_kb', 'editor' ) );
		$this->assertTrue( $post_type->show_in_rest );
	}
}
