<?php
/**
 * Tests for CPT public visibility flags to prevent data leaks.
 *
 * @package Decker
 */

class CustomPostTypesVisibilityTest extends WP_UnitTestCase {

    public function test_decker_task_not_publicly_queryable() {
        do_action( 'init' );
        $pto = get_post_type_object( 'decker_task' );
        $this->assertNotNull( $pto );
        $this->assertFalse( $pto->public );
        $this->assertFalse( $pto->publicly_queryable );
    }

    public function test_decker_event_not_publicly_queryable() {
        do_action( 'init' );
        $pto = get_post_type_object( 'decker_event' );
        $this->assertNotNull( $pto );
        $this->assertFalse( $pto->public );
        $this->assertFalse( $pto->publicly_queryable );
    }

    public function test_decker_kb_not_publicly_queryable() {
        do_action( 'init' );
        $pto = get_post_type_object( 'decker_kb' );
        $this->assertNotNull( $pto );
        $this->assertFalse( $pto->public );
        $this->assertFalse( $pto->publicly_queryable );
    }
}

