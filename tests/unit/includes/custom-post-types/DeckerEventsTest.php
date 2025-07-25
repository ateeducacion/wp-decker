<?php
/**
 * Class Test_Decker_Events
 *
 * @package Decker
 */

class DeckerEventsTest extends Decker_Test_Base {

    /**
     * Test users and objects.
     */
    private $editor;
    private $event_id;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Ensure that post types are registered
        do_action( 'init' );

        // Create an editor user using WordPress factory
        $this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $this->editor );
    }

    /**
     * Clean up after each test.
     */
    public function tear_down() {
        if ( $this->event_id ) {
            wp_delete_post( $this->event_id, true );
        }
        wp_delete_user( $this->editor );
        parent::tear_down();
    }

    /**
     * Test that an editor can create an event using our custom factory.
     */
    public function test_editor_can_create_event() {
        wp_set_current_user( $this->editor );

        // Create an event using our custom factory with all available fields
        $event_result = self::factory()->event->create(
            array(
                'post_title' => 'Test Event',
                'post_content' => 'Event description',
                'post_author' => $this->editor,
                'event_allday' => false,
                'event_start' => '2025-01-01 10:00:00',
                'event_end' => '2025-01-01 11:00:00',
                'event_location' => 'Test Location',
                'event_url' => 'https://example.com',
                'event_category' => 'bg-primary',
                'event_assigned_users' => array( $this->editor ),
            )
        );

        // Verify event creation was successful
        $this->assertNotWPError( $event_result, 'The event should be created successfully.' );
        $this->event_id = $event_result;

        // Verify basic event properties
        $event = get_post( $this->event_id );
        $this->assertEquals( 'Test Event', $event->post_title, 'The event title should match.' );
        $this->assertEquals( 'Event description', $event->post_content, 'The event description should match.' );
        $this->assertEquals( $this->editor, $event->post_author, 'The event author should match.' );

        // Verify meta fields
        $meta_fields = array(
            'event_allday' => false,
            'event_start' => '2025-01-01 10:00:00',
            'event_end' => '2025-01-01 11:00:00',
            'event_location' => 'Test Location',
            'event_url' => 'https://example.com',
            'event_category' => 'bg-primary',
            'event_assigned_users' => array( $this->editor ),
        );

        foreach ( $meta_fields as $key => $expected_value ) {
            $stored_value = get_post_meta( $this->event_id, $key, true );
            $this->assertEquals( $expected_value, $stored_value, "Meta field '$key' has incorrect value" );
        }
    }

    /**
     * Test event update using factory
     */
    public function test_update_event() {
        wp_set_current_user( $this->editor );

        // Create initial event
        $this->event_id = self::factory()->event->create(
            array(
                'post_title' => 'Original Event',
                'event_location' => 'Original Location',
            )
        );

        // Update event
        $updated_id = self::factory()->event->update_object(
            $this->event_id,
            array(
                'post_title' => 'Updated Event',
                'event_location' => 'Updated Location',
                'event_category' => 'bg-success',
            )
        );

        // Asegurar que no se devolvió un WP_Error y mostrar su mensaje si ocurre.
        $this->assertNotWPError(
            $updated_id,
            is_wp_error( $updated_id ) ? $updated_id->get_error_message() : ''
        );
        $this->assertEquals( $this->event_id, $updated_id, 'Update should return same event ID' );

        // Verify updates
        $event = get_post( $this->event_id );
        $this->assertEquals( 'Updated Event', $event->post_title, 'Event title should be updated' );

        $meta_fields = array(
            'event_location' => 'Updated Location',
            'event_category' => 'bg-success',
        );

        foreach ( $meta_fields as $key => $expected_value ) {
            $stored_value = get_post_meta( $this->event_id, $key, true );
            $this->assertEquals( $expected_value, $stored_value, "Meta field '$key' should be updated" );
        }
    }
}
