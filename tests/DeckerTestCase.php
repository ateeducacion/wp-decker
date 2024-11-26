<?php
/**
 * Base test case for Decker plugin tests
 *
 * @package Decker
 */

class DeckerTestCase extends WP_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        
        // Reset any state between tests
        $this->reset_post_types();
        $this->reset_taxonomies();
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    protected function reset_post_types() {
        foreach ( get_post_types( [], 'objects' ) as $post_type ) {
            if ( ! in_array( $post_type->name, ['post', 'page', 'attachment'] ) ) {
                unregister_post_type( $post_type->name );
            }
        }
    }

    protected function reset_taxonomies() {
        foreach ( get_taxonomies() as $taxonomy ) {
            if ( ! in_array( $taxonomy, ['category', 'post_tag'] ) ) {
                unregister_taxonomy( $taxonomy );
            }
        }
    }

}
