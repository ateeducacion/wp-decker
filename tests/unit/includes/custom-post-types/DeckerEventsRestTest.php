<?php
/**
 * Class Test_Decker_Events
 *
 * @package Decker
 */

class DeckerEventsRestTest extends WP_Test_REST_TestCase {

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
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Ensure that post types are registered
		do_action( 'init' );

		// Create users for testing
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
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
	 * Test that the event post type exists and is accessible via REST
	 */
	public function test_register_route() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/decker_event', $routes );
	}

	/**
	 * Test event creation via REST API with meta fields
	 */
	public function test_create_event() {
		wp_set_current_user( $this->editor );

		$event_data = array(
			'title'  => 'Test Event',
			'status' => 'publish',
			'meta'   => array(
				'event_start'          => '2024-02-01 10:00:00',
				'event_end'            => '2024-02-01 11:00:00',
				'event_location'       => 'Test Location',
				'event_url'            => 'https://example.com',
				'event_category'       => 'bg-primary',
				'event_assigned_users' => array( $this->editor ),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		foreach ( $event_data as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Check response status and basic data
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'Test Event', $data['title']['rendered'] );

		// Store event ID for cleanup
		$this->event_id = $data['id'];

		// Verify meta via direct access and REST response
		foreach ( $event_data['meta'] as $key => $value ) {
			// Check meta in REST response
			$this->assertArrayHasKey( $key, $data['meta'], "Meta field '$key' not found in REST response" );
			$this->assertEquals( $value, $data['meta'][ $key ], "Meta field '$key' has incorrect value in REST response" );

			// Check meta in database
			$stored_value = get_post_meta( $this->event_id, $key, true );
			$this->assertEquals( $value, $stored_value, "Meta field '$key' has incorrect value in database" );
		}
	}

	/**
	 * Test that subscribers cannot create events via REST
	 */
	public function test_subscriber_cannot_create_event() {
		wp_set_current_user( $this->subscriber );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'Subscriber Event' );
		$request->set_param( 'status', 'publish' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test event update via REST API including meta fields
	 */
	public function test_update_event() {
		// Create event as editor with initial meta
		wp_set_current_user( $this->editor );

		$initial_meta = array(
			'event_start'          => '2024-02-01T10:00:00',
			'event_end'            => '2024-02-01T11:00:00',
			'event_location'       => 'Initial Location',
			'event_url'            => 'https://example.com',
			'event_category'       => 'bg-primary',
			'event_assigned_users' => array( $this->editor ),
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'Original Event' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( 'meta', $initial_meta );

		$response       = $this->server->dispatch( $request );
		$this->event_id = $response->get_data()['id'];

		// Update event with new title and meta
		$updated_meta = array(
			'event_start'          => '2024-02-01 14:00:00',
			'event_end'            => '2024-02-01 15:00:00',
			'event_location'       => 'Updated Location',
			'event_url'            => 'https://updated-example.com',
			'event_category'       => 'bg-success',
			'event_assigned_users' => array( $this->administrator, $this->editor ),
		);

		$request = new WP_REST_Request( 'PUT', '/wp/v2/decker_event/' . $this->event_id );
		$request->set_param( 'title', 'Updated Event' );
		$request->set_param( 'meta', $updated_meta );

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Verify response status and title
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Updated Event', $data['title']['rendered'] );

		// Verify updated meta fields
		foreach ( $updated_meta as $key => $value ) {
			// Check meta in REST response
			$this->assertArrayHasKey( $key, $data['meta'], "Meta field '$key' not found in REST response" );
			$this->assertEquals( $value, $data['meta'][ $key ], "Meta field '$key' has incorrect value in REST response" );

			// Check meta in database
			$stored_value = get_post_meta( $this->event_id, $key, true );
			$this->assertEquals( $value, $stored_value, "Meta field '$key' has incorrect value in database" );
		}

		// Test that subscriber cannot update
		wp_set_current_user( $this->subscriber );

		$request = new WP_REST_Request( 'PUT', '/wp/v2/decker_event/' . $this->event_id );
		$request->set_param( 'title', 'Subscriber Update' );
		$request->set_param( 'meta', $initial_meta ); // Try to revert meta

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );

		// Verify meta wasn't changed by failed subscriber update
		foreach ( $updated_meta as $key => $value ) {
			$stored_value = get_post_meta( $this->event_id, $key, true );
			$this->assertEquals( $value, $stored_value, "Meta field '$key' should not have been changed by subscriber" );
		}
	}

	/**
	 * Test event deletion via REST API
	 */
	public function test_delete_event() {
		// Create event as editor
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'Event to Delete' );
		$request->set_param( 'status', 'publish' );

		$response       = $this->server->dispatch( $request );
		$this->event_id = $response->get_data()['id'];

		// Try to delete as subscriber (should fail)
		wp_set_current_user( $this->subscriber );

		$request  = new WP_REST_Request( 'DELETE', '/wp/v2/decker_event/' . $this->event_id );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		// Delete as editor (should succeed)
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/decker_event/' . $this->event_id );
		$request->set_param( 'force', true );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Verify post is correctly deleted
		$this->assertNull( get_post( $this->event_id ) );
	}

	/**
	 * Test REST API schema for Backbone compatibility
	 */
	public function test_rest_api_schema() {
		wp_set_current_user( $this->editor );

		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/decker_event' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Check schema exists
		$this->assertArrayHasKey( 'schema', $data );
		$schema = $data['schema'];

		// Check required fields for Backbone
		$this->assertArrayHasKey( 'title', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'status', $schema['properties'] );
		$this->assertArrayHasKey( 'meta', $schema['properties'] );
		$this->assertArrayHasKey( 'author', $schema['properties'] );
		$this->assertArrayHasKey( 'date', $schema['properties'] );
		$this->assertArrayHasKey( 'modified', $schema['properties'] );
		$this->assertArrayHasKey( 'slug', $schema['properties'] );
		$this->assertArrayHasKey( 'guid', $schema['properties'] );
		$this->assertArrayHasKey( 'link', $schema['properties'] );

		// Check meta schema
		$meta_schema = $schema['properties']['meta']['properties'];
		// foreach(['event_start', 'event_end', 'event_location', 'event_url', 'event_category'] as $field) {
		// $this->assertArrayHasKey($field, $meta_schema);
		// $this->assertEquals('string', $meta_schema[$field]['type']);
		// $this->assertEquals('', $meta_schema[$field]['default']);
		// }

		// Check array type meta field
		// $this->assertArrayHasKey('event_assigned_users', $meta_schema);
		// $this->assertEquals('array', $meta_schema['event_assigned_users']['type']);
		// $this->assertEquals('integer', $meta_schema['event_assigned_users']['items']['type']);
		// $this->assertEquals([], $meta_schema['event_assigned_users']['default']);
	}

	/**
	 * Test REST API collection parameters for Backbone compatibility
	 */
	public function test_rest_api_collection_params() {
		wp_set_current_user( $this->editor );

		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/decker_event' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Check collection parameters
		$this->assertArrayHasKey( 'endpoints', $data );
		$endpoints = $data['endpoints'];

		// Find GET endpoint
		$get_endpoint = null;
		foreach ( $endpoints as $endpoint ) {
			if ( $endpoint['methods'][0] === 'GET' && count( $endpoint['methods'] ) === 1 ) {
				$get_endpoint = $endpoint;
				break;
			}
		}

		$this->assertNotNull( $get_endpoint );
		$this->assertArrayHasKey( 'args', $get_endpoint );

		// Check Backbone-required parameters
		$args = $get_endpoint['args'];

		// Pagination
		$this->assertArrayHasKey( 'page', $args );
		$this->assertEquals( 1, $args['page']['default'] );
		$this->assertArrayHasKey( 'per_page', $args );
		$this->assertEquals( 10, $args['per_page']['default'] );

		// Search
		$this->assertArrayHasKey( 'search', $args );
		$this->assertArrayHasKey( 'search_columns', $args );
		$this->assertEquals( array( 'post_title', 'post_content', 'post_excerpt' ), $args['search_columns']['items']['enum'] );

		// Ordering
		$this->assertArrayHasKey( 'order', $args );
		$this->assertEquals( array( 'asc', 'desc' ), $args['order']['enum'] );
		$this->assertEquals( 'desc', $args['order']['default'] );

		$this->assertArrayHasKey( 'orderby', $args );
		$expected_orderby = array( 'author', 'date', 'id', 'include', 'modified', 'parent', 'relevance', 'slug', 'include_slugs', 'title' );
		$this->assertEquals( $expected_orderby, $args['orderby']['enum'] );
		$this->assertEquals( 'date', $args['orderby']['default'] );

		// Filtering
		$this->assertArrayHasKey( 'status', $args );
		$this->assertEquals( 'publish', $args['status']['default'] );
		$this->assertArrayHasKey( 'author', $args );
		$this->assertArrayHasKey( 'author_exclude', $args );
	}

	/**
	 * Test REST API response format for Backbone compatibility
	 */
	public function test_rest_api_response_format() {
		wp_set_current_user( $this->editor );

		// Create test events
		$events = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
			$request->set_param( 'title', "Test Event $i" );
			$request->set_param( 'status', 'publish' );
			$request->set_param(
				'meta',
				array(
					'event_start'    => "2024-02-0{$i}T10:00:00",
					'event_end'      => "2024-02-0{$i}T11:00:00",
					'event_location' => "Location $i",
					'event_category' => 'bg-primary',
				)
			);

			$response = $this->server->dispatch( $request );
			$events[] = $response->get_data()['id'];
		}

		// Test collection response
		$request  = new WP_REST_Request( 'GET', '/wp/v2/decker_event' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		// Check response headers
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );

		// Check response format
		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( 3, count( $data ) );

		// Check first item format
		$item = $data[0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'title', $item );
		$this->assertArrayHasKey( 'content', $item );
		$this->assertArrayHasKey( 'meta', $item );
		$this->assertArrayHasKey( '_links', $item );

		// Clean up
		foreach ( $events as $event_id ) {
			wp_delete_post( $event_id, true );
		}
	}


	/**
	 * Test that unauthenticated users cannot create events via REST
	 */
	public function test_unauthenticated_rest_cannot_create_event() {
		// Do NOT set current user, simulating unauthenticated access

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'Unauthenticated Event' );
		$request->set_param( 'status', 'publish' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403 depending on your auth implementation
	}

	/**
	 * Test that unauthenticated users cannot update events via REST
	 */
	public function test_unauthenticated_rest_cannot_update_event() {
		// Create an event first that we can try to update
		wp_set_current_user( $this->editor );
		$create_request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$create_request->set_param( 'title', 'Event to Update' );
		$create_request->set_param( 'status', 'publish' );
		$create_response = $this->server->dispatch( $create_request );
		$this->event_id  = $create_response->get_data()['id'];
		wp_set_current_user( null ); // Clear current user for unauthenticated test

		$request = new WP_REST_Request( 'PUT', '/wp/v2/decker_event/' . $this->event_id );
		$request->set_param( 'title', 'Unauthenticated Update' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403
	}

	/**
	 * Test that unauthenticated users cannot delete events via REST
	 */
	public function test_unauthenticated_rest_cannot_delete_event() {
		// Create an event first that we can try to delete
		wp_set_current_user( $this->editor );
		$create_request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$create_request->set_param( 'title', 'Event to Delete' );
		$create_request->set_param( 'status', 'publish' );
		$create_response = $this->server->dispatch( $create_request );
		$this->event_id  = $create_response->get_data()['id'];
		wp_set_current_user( null ); // Clear current user for unauthenticated test

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/decker_event/' . $this->event_id );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403
	}

	/**
	 * Test that unauthenticated users cannot read a collection of events via REST
	 */
	public function test_unauthenticated_rest_cannot_read_events() {
		// Do NOT set current user, simulating unauthenticated access

		$request = new WP_REST_Request( 'GET', '/wp/v2/decker_event' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403, or maybe even 200 with empty list depending on requirements
	}

	/**
	 * Test that unauthenticated users cannot read a single event via REST
	 */
	public function test_unauthenticated_rest_cannot_read_event() {
		// Create an event first that we can try to read
		wp_set_current_user( $this->editor );
		$create_request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$create_request->set_param( 'title', 'Event to Read' );
		$create_request->set_param( 'status', 'publish' );
		$create_response = $this->server->dispatch( $create_request );
		$this->event_id  = $create_response->get_data()['id'];
		wp_set_current_user( null ); // Clear current user for unauthenticated test

		$request = new WP_REST_Request( 'GET', '/wp/v2/decker_event/' . $this->event_id );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403
	}

	/**
	 * Test that unauthenticated users cannot access the schema via REST
	 */
	public function test_unauthenticated_rest_cannot_access_schema() {
		// Do NOT set current user, simulating unauthenticated access

		$request = new WP_REST_Request( 'OPTIONS', '/wp/v2/decker_event' );

		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() ); // Or 403, or maybe 200 depending on requirements
	}



	/**
	 * Test REST API event creation with all-day event
	 */
	public function test_rest_create_allday_event() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_body_params(
			array(
				'title' => 'All Day REST Event',
				'meta'  => array(
					'event_allday' => true,
					'event_start'  => '2025-01-01',
					'event_end'    => '2025-01-01',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( '2025-01-01', $data['meta']['event_start'] );
		$this->assertEquals( '2025-01-01', $data['meta']['event_end'] );
	}

	/**
	 * Test REST API event update with time change
	 */
	public function test_rest_update_event_time() {
		// Create initial event
		wp_set_current_user( $this->editor );
		$event_id = self::factory()->event->create(
			array(
				'post_title' => 'REST Update Test',
				'meta_input' => array(
					'event_start' => '2025-01-01 10:00:00',
					'event_end'   => '2025-01-01 11:00:00',
				),
			)
		);

		// Update via REST
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$event_id}" );
		$request->set_body_params(
			array(
				'meta' => array(
					'event_start' => '2025-01-01 14:00:00',
					'event_end'   => '2025-01-01 15:30:00',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( '2025-01-01 14:00:00', $data['meta']['event_start'] );
		$this->assertEquals( '2025-01-01 15:30:00', $data['meta']['event_end'] );
	}

	/**
	 * Test event with no end date specified
	 */
	public function test_missing_end_date() {
		update_option( 'timezone_string', 'UTC' );

		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => '2025-01-01 10:00:00',
				// No end date
				),
			)
		);

		$end          = get_post_meta( $event_id, 'event_end', true );
		$expected_end = '2025-01-01 11:00:00';

		$this->assertEquals( $expected_end, $end );
	}

	/**
	 * Test invalid date handling
	 */
	public function test_invalid_date_handling() {
		update_option( 'timezone_string', 'UTC' );

		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => 'invalid-date',
					'event_end'   => 'another-invalid',
				),
			)
		);

		$start = get_post_meta( $event_id, 'event_start', true );
		$end   = get_post_meta( $event_id, 'event_end', true );

		$this->assertEquals( '1970-01-01 00:00:00', $start );
		$this->assertEquals( '1970-01-01 01:00:00', $end );
	}
}
