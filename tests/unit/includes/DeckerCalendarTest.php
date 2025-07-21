<?php
/**
 * Tests for Decker_Calendar endpoints.
 *
 * @package Decker
 */

class DeckerCalendarTest extends Decker_Test_Base {

    /**
     * REST server instance.
     *
     * @var WP_REST_Server
     */
    private $server;

    /**
     * Administrator user ID.
     *
     * @var int
     */
    private $user_id;

    public function set_up() {
        parent::set_up();
        $this->server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        do_action( 'init' );
        $this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->user_id );
        update_user_meta( $this->user_id, 'decker_calendar_token', 'token123' );
    }

    public function tear_down() {
        wp_delete_user( $this->user_id );
        parent::tear_down();
    }

    private function create_event( $category, $title ) {
        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_type'   => 'decker_event',
        ) );
        update_post_meta( $post_id, 'event_start', '2024-01-01T10:00:00' );
        update_post_meta( $post_id, 'event_end', '2024-01-01T11:00:00' );
        update_post_meta( $post_id, 'event_category', $category );
        return $post_id;
    }

    public function test_filter_meeting_events() {
        $this->create_event( 'bg-success', 'Meeting' );
        $this->create_event( 'bg-info', 'Holiday' );

        $request = new WP_REST_Request( 'GET', '/decker/v1/calendar' );
        $request->set_param( 'token', 'token123' );
        $request->set_param( 'type', 'meeting' );

        $response = $this->server->dispatch( $request );
        $data     = $response->get_data();

        $this->assertCount( 1, $data );
        $this->assertEquals( 'Meeting', $data[0]['title'] );
    }
}
