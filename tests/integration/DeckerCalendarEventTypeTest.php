<?php
/**
 * Calendar event type filtering tests.
 *
 * @package Decker
 */

class DeckerCalendarEventTypeTest extends Decker_Test_Base {
        private $user_id;

        public function set_up() {
                parent::set_up();

                do_action( 'init' );
                $this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
                wp_set_current_user( $this->user_id );
        }

        public function test_rest_route_registered() {
                $routes = rest_get_server()->get_routes();
                $this->assertArrayHasKey( '/decker/v1/calendar/meeting', $routes );
        }

        public function test_meeting_filter_returns_only_meeting_events() {
                $calendar = new Decker_Calendar();

                $meeting_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-01T10:00:00',
                                'event_end'      => '2025-01-01T11:00:00',
                                'event_category' => 'bg-success',
                        ),
                ) );

                $holiday_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-02T10:00:00',
                                'event_end'      => '2025-01-02T11:00:00',
                                'event_category' => 'bg-info',
                        ),
                ) );

                $request  = new WP_REST_Request( 'GET', '/decker/v1/calendar' );
                $request->set_param( 'type', 'meeting' );
                $response = rest_get_server()->dispatch( $request );
                $data     = $response->get_data();

                $ids = wp_list_pluck( $data, 'id' );
                $this->assertContains( 'event_' . $meeting_id, $ids );
                $this->assertNotContains( 'event_' . $holiday_id, $ids );
        }
}
