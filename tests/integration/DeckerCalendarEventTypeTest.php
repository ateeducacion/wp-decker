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
                $this->assertArrayHasKey( '/decker/v1/calendar/absence', $routes );
                $this->assertArrayHasKey( '/decker/v1/calendar/warning', $routes );
                $this->assertArrayHasKey( '/decker/v1/calendar/alert', $routes );
        }

        public function event_type_provider() {
                return [
                        ['meeting'],
                        ['absence'],
                        ['warning'],
                        ['alert'],
                ];
        }

        /**
         * @dataProvider event_type_provider
         */
        public function test_json_feed_returns_only_events_of_type( $type ) {
                $calendar = new Decker_Calendar();

                // Crear evento del tipo actual
                $current_type_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-01T10:00:00',
                                'event_end'      => '2025-01-01T11:00:00',
                                'event_category' => $calendar->get_type_map()[$type],
                        ),
                ) );

                // Crear evento de un tipo diferente (usar meeting si no es el tipo actual)
                $other_type = ($type === 'meeting') ? 'warning' : 'meeting';
                $other_type_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-02T10:00:00',
                                'event_end'      => '2025-01-02T11:00:00',
                                'event_category' => $calendar->get_type_map()[$other_type],
                        ),
                ) );

                // Crear una tarea
                $task_id = $this->factory->task->create( array(
                        'post_title'   => 'Test Task',
                        'post_status'  => 'publish',
                        'meta_input'   => array(
                                'duedate'        => '2025-01-03',
                        ),
                ) );

                $request  = new WP_REST_Request( 'GET', '/decker/v1/calendar' );
                $request->set_param( 'type', $type );
                $nonce = wp_create_nonce( 'wp_rest' );
                $request->set_header( 'X-WP-Nonce', $nonce );
                $response = rest_get_server()->dispatch( $request );
                // Verificar si la respuesta es un error
                if ( is_wp_error( $response ) ) {
                    $this->fail( 'REST request failed: ' . $response->get_error_message() );
                    return; // Evitar continuar con un objeto WP_Error.
                }
                $data = $response->get_data();

                $ids = wp_list_pluck( $data, 'id' );
                $this->assertContains( 'event_' . $current_type_id, $ids );
                $this->assertNotContains( 'event_' . $other_type_id, $ids );
                // $this->assertNotContains( 'task_' . $task_id, $ids );
        }

        /**
         * @dataProvider event_type_provider
         */
        public function test_ics_feed_returns_only_events_of_type( $type ) {
                $calendar = new Decker_Calendar();

                // Crear evento del tipo actual
                $current_type_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-01T10:00:00',
                                'event_end'      => '2025-01-01T11:00:00',
                                'event_category' => $calendar->get_type_map()[$type],
                        ),
                ) );

                // Crear evento de un tipo diferente
                $other_type = ($type === 'meeting') ? 'warning' : 'meeting';
                $other_type_id = self::factory()->post->create( array(
                        'post_type'   => 'decker_event',
                        'post_status' => 'publish',
                        'meta_input'  => array(
                                'event_start'    => '2025-01-02T10:00:00',
                                'event_end'      => '2025-01-02T11:00:00',
                                'event_category' => $calendar->get_type_map()[$other_type],
                        ),
                ) );

                // Crear una tarea
                $task_id = $this->factory->task->create( array(
                        'post_title'   => 'Test Task',
                        'post_status'  => 'publish',
                        'meta_input'   => array(
                                'duedate'        => '2025-01-03',
                        ),
                ) );

                // Generar directamente el contenido ICS sin pasar por headers.
                $events = $calendar->get_events( $type );
                if ( is_wp_error( $events ) ) {
                    $this->fail( 'Failed to get events: ' . $events->get_error_message() );
                    return;
                }
                $ical = $calendar->generate_ical( $events, $type );

                // Verificar que solo el evento del tipo actual está presente
                $this->assertStringContainsString( 'event_' . $current_type_id, $ical );
                $this->assertStringNotContainsString( 'event_' . $other_type_id, $ical );
                // $this->assertStringNotContainsString( 'task_' . $task_id, $ical );
        }
}
