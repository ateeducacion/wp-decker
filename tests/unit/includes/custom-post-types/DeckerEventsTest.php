<?php
/**
 * Class Test_Decker_Events
 *
 * @package Decker
 */

class DeckerEventsTest extends Decker_Test_Base {

    private $administrator;
    private $editor;
    private $subscriber;
    private $event_id;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Ensure that post types are registered
        do_action( 'init' );

        // Create users for testing
        $this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
        $this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

        // Set current user as administrator for setup
        wp_set_current_user( $this->administrator );
    }

    /**
     * Clean up after each test.
     */
    public function tear_down() {
        if ( $this->event_id ) {
            wp_delete_post( $this->event_id, true );
        }
        
        wp_delete_user( $this->administrator );
        wp_delete_user( $this->editor );
        wp_delete_user( $this->subscriber );
        
        parent::tear_down();
    }

    /**
     * Test that the event post type exists and has correct settings
     */
    public function test_post_type_exists() {
        $post_type = get_post_type_object( 'decker_event' );
        
        $this->assertNotNull( $post_type );
        $this->assertTrue( $post_type->public );
        $this->assertTrue( $post_type->show_in_rest );
        $this->assertEquals( 'events', $post_type->rewrite['slug'] );
    }

    /**
     * Test event creation with meta fields
     */
    public function test_create_event() {
        wp_set_current_user( $this->editor );

        $event_data = array(
            'post_title'  => 'Test Event',
            'post_type'   => 'decker_event',
            'post_status' => 'publish',
            'meta_input'  => array(
                '_event_start'    => '2024-02-01T10:00:00',
                '_event_end'      => '2024-02-01T11:00:00',
                '_event_location' => 'Test Location',
                '_event_url'      => 'https://example.com',
                '_event_category' => 'bg-primary',
                '_event_assigned_users' => array( $this->editor )
            )
        );

        $this->event_id = wp_insert_post( $event_data );

        $this->assertNotEquals( 0, $this->event_id );
        $this->assertEquals( 'Test Event', get_the_title( $this->event_id ) );
        
        // Verify meta fields
        $this->assertEquals( '2024-02-01T10:00:00', get_post_meta( $this->event_id, '_event_start', true ) );
        $this->assertEquals( '2024-02-01T11:00:00', get_post_meta( $this->event_id, '_event_end', true ) );
        $this->assertEquals( 'Test Location', get_post_meta( $this->event_id, '_event_location', true ) );
        $this->assertEquals( 'https://example.com', get_post_meta( $this->event_id, '_event_url', true ) );
        $this->assertEquals( 'bg-primary', get_post_meta( $this->event_id, '_event_category', true ) );
        
        $assigned_users = get_post_meta( $this->event_id, '_event_assigned_users', true );
        $this->assertContains( $this->editor, $assigned_users );
    }

    /**
     * Test that subscribers cannot create events
     */
    public function test_subscriber_cannot_create_event() {
        wp_set_current_user( $this->subscriber );

        $event_data = array(
            'post_title'  => 'Subscriber Event',
            'post_type'   => 'decker_event',
            'post_status' => 'publish'
        );

        $result = wp_insert_post( $event_data );
        
        // Should fail due to lack of permissions
        $this->assertEquals( 0, $result );
    }

    /**
     * Test REST API access restrictions
     */
    public function test_rest_api_restrictions() {
        $request = new WP_REST_Request( 'GET', '/wp/v2/decker_event' );
        
        // Test subscriber access (should be denied)
        wp_set_current_user( $this->subscriber );
        $response = rest_do_request( $request );
        $this->assertEquals( 403, $response->get_status() );

        // Test editor access (should be allowed)
        wp_set_current_user( $this->editor );
        $response = rest_do_request( $request );
        $this->assertEquals( 200, $response->get_status() );
    }

    /**
     * Test event meta registration
     */
    public function test_meta_registration() {
        $registered_meta = get_registered_meta_keys( 'post', 'decker_event' );
        
        $expected_meta_keys = array(
            '_event_start',
            '_event_end',
            '_event_location',
            '_event_url',
            '_event_category',
            '_event_assigned_users'
        );

        foreach ( $expected_meta_keys as $key ) {
            $this->assertArrayHasKey( $key, $registered_meta );
            $this->assertTrue( $registered_meta[$key]['show_in_rest'] );
        }
    }

    /**
     * Test event update capabilities
     */
    public function test_update_event() {
        // Create event as editor
        wp_set_current_user( $this->editor );
        
        $this->event_id = wp_insert_post( array(
            'post_title'  => 'Original Event',
            'post_type'   => 'decker_event',
            'post_status' => 'publish'
        ) );

        // Update event
        $updated = wp_update_post( array(
            'ID'         => $this->event_id,
            'post_title' => 'Updated Event'
        ) );

        $this->assertNotWPError( $updated );
        $this->assertEquals( 'Updated Event', get_the_title( $this->event_id ) );

        // Test that subscriber cannot update
        wp_set_current_user( $this->subscriber );
        
        $result = wp_update_post( array(
            'ID'         => $this->event_id,
            'post_title' => 'Subscriber Update'
        ) );

        $this->assertEquals( 0, $result );
        $this->assertEquals( 'Updated Event', get_the_title( $this->event_id ) );
    }

    /**
     * Test event deletion capabilities
     */
    public function test_delete_event() {
        // Create event as editor
        wp_set_current_user( $this->editor );
        
        $this->event_id = wp_insert_post( array(
            'post_title'  => 'Event to Delete',
            'post_type'   => 'decker_event',
            'post_status' => 'publish'
        ) );

        // Try to delete as subscriber (should fail)
        wp_set_current_user( $this->subscriber );
        $result = wp_delete_post( $this->event_id );
        $this->assertFalse( $result );
        $this->assertNotNull( get_post( $this->event_id ) );

        // Delete as editor (should succeed)
        wp_set_current_user( $this->editor );
        $result = wp_delete_post( $this->event_id );
        $this->assertNotFalse( $result );
        $this->assertNull( get_post( $this->event_id ) );
    }
}
