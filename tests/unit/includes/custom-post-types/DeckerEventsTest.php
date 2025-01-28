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
     * Test event creation via REST API
     */
    public function test_create_event() {
        wp_set_current_user($this->editor);

        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        $request->set_param('title', 'Test Event');
        $request->set_param('status', 'publish');
        $request->set_param('meta', array(
            'event_start' => '2024-02-01T10:00:00',
            'event_end' => '2024-02-01T11:00:00',
            'event_location' => 'Test Location',
            'event_url' => 'https://example.com',
            'event_category' => 'bg-primary',
            'event_assigned_users' => array($this->editor)
        ));

        $response = $this->server->dispatch($request);
        $data = $response->get_data();
        
        $this->assertEquals(201, $response->get_status());
        $this->assertEquals('Test Event', $data['title']['rendered']);
        
        // Store event ID for cleanup
        $this->event_id = $data['id'];
        
        // var_dump($data);
        // die();

        // Verify meta via REST
        $this->assertEquals('2024-02-01T10:00:00', $data['meta']['event_start']);
        $this->assertEquals('2024-02-01T11:00:00', $data['meta']['event_end']);
        $this->assertEquals('Test Location', $data['meta']['event_location']);
        $this->assertEquals('https://example.com', $data['meta']['event_url']);
        $this->assertEquals('bg-primary', $data['meta']['event_category']);
        $this->assertContains($this->editor, $data['meta']['event_assigned_users']);
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
     * Test event update via REST API
     */
    public function test_update_event() {
        // Create event as editor
        wp_set_current_user($this->editor);
        
        $request = new WP_REST_Request('POST', '/wp/v2/decker_event');
        $request->set_param('title', 'Original Event');
        $request->set_param('status', 'publish');
        
        $response = $this->server->dispatch($request);
        $this->event_id = $response->get_data()['id'];

        // Update event
        $request = new WP_REST_Request('PUT', '/wp/v2/decker_event/' . $this->event_id);
        $request->set_param('title', 'Updated Event');
        
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('Updated Event', $response->get_data()['title']['rendered']);

        // Test that subscriber cannot update
        wp_set_current_user($this->subscriber);
        
        $request = new WP_REST_Request('PUT', '/wp/v2/decker_event/' . $this->event_id);
        $request->set_param('title', 'Subscriber Update');
        
        $response = $this->server->dispatch($request);
        $this->assertEquals(403, $response->get_status());
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
        
        $request = new WP_REST_Request('DELETE', '/wp/v2/decker_event/' . $this->event_id);
        $response = $this->server->dispatch($request);
        
        $this->assertEquals(200, $response->get_status());
        $this->assertEquals('trash', get_post_status($this->event_id));
    }
}
