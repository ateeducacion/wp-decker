<?php
/**
 * Class Test_Decker_Events
 *
 * @package Decker
 */

class DeckerEventsTest extends WP_Test_REST_TestCase {
    
    private $administrator;
    private $editor;
    private $subscriber;
    private $event_id;
    private $server;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Initialize REST server
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');

        // Ensure that post types are registered
        do_action('init');

        // Create users for testing
        $this->administrator = self::factory()->user->create(array('role' => 'administrator'));
        $this->editor = self::factory()->user->create(array('role' => 'editor'));
        $this->subscriber = self::factory()->user->create(array('role' => 'subscriber'));
    }

    /**
     * Clean up after each test.
     */
    public function tear_down() {
        if ($this->event_id) {
            wp_delete_post($this->event_id, true);
        }
        
        wp_delete_user($this->administrator);
        wp_delete_user($this->editor);
        wp_delete_user($this->subscriber);
        
        parent::tear_down();
    }

    /**
     * Test that the event post type exists and is accessible via REST
     */
    public function test_register_route() {
        $routes = $this->server->get_routes();
        $this->assertArrayHasKey('/wp/v2/decker_event', $routes);
    }

    /**
     * Test event creation via REST API with meta fields
     */
    public function test_create_event() {
        wp_set_current_user($this->editor);

        $event_data = array(
            'title' => 'Test Event',
            'status' => 'publish',
            'meta' => array(
                'event_start' => '2024-02-01T10:00:00',
                'event_end' => '2024-02-01T11:00:00',
                'event_location' => 'Test Location',
                'event_url' => 'https://example.com',
                'event_category' => 'bg-primary',
                'event_assigned_users' => array($this->editor)
            )
        );

        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        foreach($event_data as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = $this->server->dispatch($request);
        $data = $response->get_data();
        
        // Check response status and basic data
        $this->assertEquals(201, $response->get_status());
        $this->assertEquals('Test Event', $data['title']['rendered']);
        
        // Store event ID for cleanup
        $this->event_id = $data['id'];

        // Verify meta via direct access and REST response
        foreach($event_data['meta'] as $key => $value) {
            // Check meta in REST response
            $this->assertArrayHasKey($key, $data['meta'], "Meta field '$key' not found in REST response");
            $this->assertEquals($value, $data['meta'][$key], "Meta field '$key' has incorrect value in REST response");
            
            // Check meta in database
            $stored_value = get_post_meta($this->event_id, $key, true);
            $this->assertEquals($value, $stored_value, "Meta field '$key' has incorrect value in database");
        }
    }

    /**
     * Test that subscribers cannot create events via REST
     */
    public function test_subscriber_cannot_create_event() {
        wp_set_current_user($this->subscriber);

        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        $request->set_param('title', 'Subscriber Event');
        $request->set_param('status', 'publish');

        $response = $this->server->dispatch($request);
        
        $this->assertEquals(403, $response->get_status());
    }

    /**
     * Test event update via REST API including meta fields
     */
    public function test_update_event() {
        // Create event as editor with initial meta
        wp_set_current_user($this->editor);
        
        $initial_meta = array(
            'event_start' => '2024-02-01T10:00:00',
            'event_end' => '2024-02-01T11:00:00',
            'event_location' => 'Initial Location',
            'event_url' => 'https://example.com',
            'event_category' => 'bg-primary',
            'event_assigned_users' => array($this->editor)
        );
        
        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        $request->set_param('title', 'Original Event');
        $request->set_param('status', 'publish');
        $request->set_param('meta', $initial_meta);
        
        $response = $this->server->dispatch($request);
        $this->event_id = $response->get_data()['id'];

        // Update event with new title and meta
        $updated_meta = array(
            'event_start' => '2024-02-01T14:00:00',
            'event_end' => '2024-02-01T15:00:00',
            'event_location' => 'Updated Location',
            'event_url' => 'https://updated-example.com',
            'event_category' => 'bg-success',
            'event_assigned_users' => array($this->administrator, $this->editor)
        );
        
        $request = new WP_REST_Request('PUT', '/wp/v2/decker_event/' . $this->event_id);
        $request->set_param('title', 'Updated Event');
        $request->set_param('meta', $updated_meta);
        
        $response = $this->server->dispatch($request);
        $data = $response->get_data();
        
        // Verify response status and title
        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('Updated Event', $data['title']['rendered']);
        
        // Verify updated meta fields
        foreach($updated_meta as $key => $value) {
            // Check meta in REST response
            $this->assertArrayHasKey($key, $data['meta'], "Meta field '$key' not found in REST response");
            $this->assertEquals($value, $data['meta'][$key], "Meta field '$key' has incorrect value in REST response");
            
            // Check meta in database
            $stored_value = get_post_meta($this->event_id, $key, true);
            $this->assertEquals($value, $stored_value, "Meta field '$key' has incorrect value in database");
        }

        // Test that subscriber cannot update
        wp_set_current_user($this->subscriber);
        
        $request = new WP_REST_Request('PUT', '/wp/v2/decker_event/' . $this->event_id);
        $request->set_param('title', 'Subscriber Update');
        $request->set_param('meta', $initial_meta); // Try to revert meta
        
        $response = $this->server->dispatch($request);
        $this->assertEquals(403, $response->get_status());
        
        // Verify meta wasn't changed by failed subscriber update
        foreach($updated_meta as $key => $value) {
            $stored_value = get_post_meta($this->event_id, $key, true);
            $this->assertEquals($value, $stored_value, "Meta field '$key' should not have been changed by subscriber");
        }
    }

    /**
     * Test event deletion via REST API
     */
    public function test_delete_event() {
        // Create event as editor
        wp_set_current_user($this->editor);
        
        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        $request->set_param('title', 'Event to Delete');
        $request->set_param('status', 'publish');
        
        $response = $this->server->dispatch($request);
        $this->event_id = $response->get_data()['id'];

        // Try to delete as subscriber (should fail)
        wp_set_current_user($this->subscriber);
        
        $request = new WP_REST_Request('DELETE', '/wp/v2/decker_event/' . $this->event_id);
        $response = $this->server->dispatch($request);
        
        $this->assertEquals(403, $response->get_status());

        // Delete as editor (should succeed)
        wp_set_current_user($this->editor);
        
        // First verify all meta exists before deletion
        $meta_fields = array(
            'event_start',
            'event_end',
            'event_location',
            'event_url',
            'event_category',
            'event_assigned_users'
        );
        
        foreach($meta_fields as $field) {
            $this->assertNotEmpty(
                get_post_meta($this->event_id, $field, true),
                "Meta field '$field' should exist before deletion"
            );
        }
        
        $request = new WP_REST_Request('DELETE', '/wp/v2/decker_event/' . $this->event_id);
        $request->set_param('force', true);
        $response = $this->server->dispatch($request);
        
        $this->assertEquals(200, $response->get_status());
        
        // Verify post and all meta are completely deleted
        $this->assertNull(get_post($this->event_id));
        foreach($meta_fields as $field) {
            $this->assertEmpty(
                get_post_meta($this->event_id, $field, true),
                "Meta field '$field' should be deleted"
            );
        }
    }
}
