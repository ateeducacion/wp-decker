<?php
/**
 * Class DeckerEventsIntegrationTest
 *
 * @package Decker
 */

/**
 * Events integration tests
 */
class DeckerEventsIntegrationTest extends Decker_Test_Base {

	/**
	 * Test event creation and meta saving
	 */
	public function test_create_event_with_meta() {
		// Create a test user
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		// Create an event
		$event_data = array(
			'post_title'   => 'Test Event',
			'post_content' => 'Test Event Description',
			'post_status'  => 'publish',
			'post_type'    => 'decker_event',
			'post_author'  => $user_id,
		);

		$event_id = wp_insert_post( $event_data );
		$this->assertNotEquals( 0, $event_id );

		// Add event meta
		$start_date = '2024-01-24T10:00';
		$end_date = '2024-01-24T11:00';
		$location = 'Test Location';
		$url = 'https://example.com';
		$category = 'bg-primary';
		$assigned_users = array( $user_id );

		update_post_meta( $event_id, '_event_start', $start_date );
		update_post_meta( $event_id, '_event_end', $end_date );
		update_post_meta( $event_id, '_event_location', $location );
		update_post_meta( $event_id, '_event_url', $url );
		update_post_meta( $event_id, '_event_category', $category );
		update_post_meta( $event_id, '_event_assigned_users', $assigned_users );

		// Verify meta was saved correctly
		$this->assertEquals( $start_date, get_post_meta( $event_id, '_event_start', true ) );
		$this->assertEquals( $end_date, get_post_meta( $event_id, '_event_end', true ) );
		$this->assertEquals( $location, get_post_meta( $event_id, '_event_location', true ) );
		$this->assertEquals( $url, get_post_meta( $event_id, '_event_url', true ) );
		$this->assertEquals( $category, get_post_meta( $event_id, '_event_category', true ) );
		$this->assertEquals( $assigned_users, get_post_meta( $event_id, '_event_assigned_users', true ) );
	}

	/**
	 * Test event post type registration
	 */
	public function test_event_post_type_registration() {
		$post_type = get_post_type_object( 'decker_event' );
		$this->assertNotNull( $post_type );
		$this->assertEquals( 'decker_event', $post_type->name );
		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_in_rest );
	}

	/**
	 * Test event meta box registration
	 */
	public function test_event_meta_box_registration() {
		global $wp_meta_boxes;

		do_action( 'add_meta_boxes', 'decker_event' );

		$this->assertArrayHasKey( 'decker_event', $wp_meta_boxes );
		$this->assertArrayHasKey( 'normal', $wp_meta_boxes['decker_event'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['decker_event']['normal'] );
		$this->assertArrayHasKey( 'decker_event_details', $wp_meta_boxes['decker_event']['normal']['high'] );
	}
}
