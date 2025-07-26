<?php
/**
 * Class Test_Decker_Events
 *
 * @package Decker
 */
class DeckerEventsTest extends Decker_Test_Base {

    /**
     * Editor user ID.
     *
     * @var int
     */
    private $editor;

    /**
     * Created event ID.
     *
     * @var int
     */
    private $event_id;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Configurar zona horaria consistente para pruebas
        update_option('timezone_string', 'Europe/Madrid');

        // Register custom post types.
        do_action( 'init' );

        // Create an editor user and set as current.
        $this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );
        wp_set_current_user( $this->editor );
    }

    /**
     * Tear down after each test.
     */
    public function tear_down() {
        if ( $this->event_id ) {
            wp_delete_post( $this->event_id, true );
        }
        wp_delete_user( $this->editor );
        parent::tear_down();
    }


    /**
     * Data-provider: valid pairs of local start / end strings for all-day events.
     */
    public function dp_all_day_valid() {
        return array(
            'same day'      => array( '2025-01-01', '2025-01-01' ),
            'different day' => array( '2025-01-01', '2025-01-03' ),
            'empty end'     => array( '2025-12-31', '' ), // should copy start
        );
    }

    /**
     * Data-provider: valid pairs for datetime events.
     */
    public function dp_datetime_valid() {
        return array(
            'same tz winter' => array( '2025-01-01 08:00:00', '2025-01-01 09:30:00', 'Europe/Berlin' ),
            'same tz summer' => array( '2025-07-01 08:00:00', '2025-07-01 09:30:00', 'Europe/Berlin' ),
            'NY winter'      => array( '2025-01-01 08:00:00', '2025-01-01 09:30:00', 'America/New_York' ),
            'UTC'            => array( '2025-01-01 08:00:00', '2025-01-01 09:30:00', 'UTC' ),
        );
    }

    /**
     * Data-provider: invalid end < start.
     */
    public function dp_end_before_start() {
        return array(
            'all-day'  => array( true, '2025-01-02', '2025-01-01' ),
            'datetime' => array( false, '2025-01-01 12:00:00', '2025-01-01 11:00:00' ),
        );
    }


    /**
     * An editor should be able to create an event.
     */
    public function test_editor_can_create_event() {
        wp_set_current_user( $this->editor );

        $start_local = '2025-01-01 10:00:00';
        $end_local   = '2025-01-01 11:00:00';

        // Create the event via factory->create()
        $event_result = self::factory()->event->create( [
            'post_title'   => 'Test Event',
            'post_content' => 'Event description',
            'post_author'  => $this->editor,
            'meta_input'   => [
                'event_allday'           => false,
                'event_start'  => $start_local,
                'event_end'    => $end_local,
                'event_location'         => 'Test Location',
                'event_url'              => 'https://example.com',
                'event_category'         => 'bg-primary',
                'event_assigned_users'   => [ $this->editor ],
            ],
        ] );

        // Ensure creation succeeded
        $this->assertNotWPError( $event_result, 'The event should be created successfully.' );
        $this->event_id = $event_result;

        // Calcular el valor UTC esperado dinámicamente
        $expected_start_utc = get_gmt_from_date( $start_local, 'Y-m-d H:i:s' );
        $expected_end_utc   = get_gmt_from_date( $end_local, 'Y-m-d H:i:s' );

        // Fetch the post and verify core fields
        $event = get_post( $this->event_id );
        $this->assertEquals( 'Test Event', $event->post_title,   'Title should match.' );
        $this->assertEquals( 'Event description', $event->post_content, 'Content should match.' );
        $this->assertEquals( $this->editor, $event->post_author,    'Author should match.' );

        // Verify all meta fields
        $expected_meta = [
            'event_allday'         => false,
            'event_start'          => $expected_start_utc,
            'event_end'            => $expected_end_utc,
            'event_location'       => 'Test Location',
            'event_url'            => 'https://example.com',
            'event_category'       => 'bg-primary',
            'event_assigned_users' => [ $this->editor ],
        ];

        foreach ( $expected_meta as $key => $value ) {
            $this->assertEquals(
                $value,
                get_post_meta( $this->event_id, $key, true ),
                "Meta field '{$key}' should be stored correctly."
            );
        }
    }

    /**
     * An editor should be able to update an event.
     */
    public function test_update_event() {
        wp_set_current_user( $this->editor );

        // First, create an event (only core + one meta field).
        $this->event_id = self::factory()->event->create( [
            'post_title'   => 'Original Event',
            'post_content' => 'Original content',
            'meta_input'   => [
                'event_location' => 'Original Location',
            ],
        ] );

        $this->assertNotWPError( $this->event_id, 'Initial creation should not error.' );

        // Now update title + two meta fields
        $updated_id = self::factory()->event->update_object(
            $this->event_id,
            [
                'post_title' => 'Updated Event',
                'meta_input' => [
                    'event_location'   => 'Updated Location',
                    'event_category'   => 'bg-success',
                ],
            ]
        );

        $this->assertNotWPError( $updated_id, 'Update should not return a WP_Error.' );
        $this->assertEquals( $this->event_id, $updated_id, 'Update should return same event ID.' );

        $event = get_post( $this->event_id );
        $this->assertEquals( 'Updated Event', $event->post_title, 'Title should be updated.' );

        $updated_meta = [
            'event_location' => 'Updated Location',
            'event_category' => 'bg-success',
        ];

        foreach ( $updated_meta as $key => $value ) {
            $this->assertEquals(
                $value,
                get_post_meta( $this->event_id, $key, true ),
                "Meta field '{$key}' should be updated."
            );
        }
    }


    /**
     * An editor should be able to update all fields of an event.
     */
    public function test_update_event_with_all_fields() {
        wp_set_current_user( $this->editor );

        $this->event_id = self::factory()->event->create( [
            'post_title'   => 'Original Event',
            'post_content' => 'Original content',
            'post_excerpt' => 'Original excerpt',
            'meta_input'   => [
                'event_allday'         => false,
                'event_start'          => '2025-01-01 10:00:00',
                'event_end'            => '2025-01-01 12:00:00',
                'event_location'       => 'Room A',
                'event_url'            => 'https://old.example.com',
                'event_category'       => 'bg-warning',
                'event_assigned_users' => [ $this->editor ],
            ],
        ] );

        $this->assertNotWPError( $this->event_id );

        $updated_id = self::factory()->event->update_object(
            $this->event_id,
            [
                'post_title'   => 'Updated Event',
                'post_content' => 'Updated content',
                'post_excerpt' => 'Updated excerpt',
                'meta_input'   => [
                    'event_allday'         => true,
                    'event_start'          => '2025-02-01',
                    'event_end'            => '2025-02-01',
                    'event_location'       => 'Room B',
                    'event_url'            => 'https://new.example.com',
                    'event_category'       => 'bg-info',
                    'event_assigned_users' => [],
                ],
            ]
        );

        $this->assertNotWPError( $updated_id );
        $event = get_post( $this->event_id );

        $this->assertEquals( 'Updated Event', $event->post_title );
        $this->assertEquals( 'Updated content', $event->post_content );
        $this->assertEquals( 'Updated excerpt', $event->post_excerpt );

        // Verificar metas
        $this->assertEquals( '1', get_post_meta( $this->event_id, 'event_allday', true ), "Meta 'event_allday' should be 1." );
        $this->assertEquals( '2025-02-01', get_post_meta( $this->event_id, 'event_start', true ), "Meta 'event_start' for all-day should be Y-m-d." );
        $this->assertEquals( '2025-02-01', get_post_meta( $this->event_id, 'event_end', true ), "Meta 'event_end' for all-day should be Y-m-d." );

        $meta = [
            'event_allday'         => true,
            'event_start'          => '2025-02-01',
            'event_end'            => '2025-02-01',
            'event_location'       => 'Room B',
            'event_url'            => 'https://new.example.com',
            'event_category'       => 'bg-info',
            'event_assigned_users' => [],
        ];

        foreach ( $meta as $key => $expected ) {
            $this->assertEquals(
                $expected,
                get_post_meta( $this->event_id, $key, true ),
                "Meta field '{$key}' should be updated."
            );
        }
    }

    /**
     * An editor should be able to delete an event.
     */
    public function test_delete_event() {
        wp_set_current_user( $this->editor );

        $event_id = self::factory()->event->create( [
            'post_title' => 'Deletable Event',
        ] );

        $this->assertNotWPError( $event_id );
        $this->assertNotNull( get_post( $event_id ) );

        wp_delete_post( $event_id, true );
        $this->assertNull( get_post( $event_id ), 'Event should be deleted permanently.' );
    }

    /**
     * An editor should be able to create multiple events.
     */
    public function test_create_multiple_events() {
        wp_set_current_user( $this->editor );

        $events = self::factory()->event->create_many( 3, [
            'post_author' => $this->editor,
            'post_status' => 'publish',
        ] );

        $this->assertCount( 3, $events, 'Three events should be created.' );

        foreach ( $events as $id ) {
            $this->assertNotWPError( $id );
            $this->assertEquals( 'decker_event', get_post_type( $id ) );
        }
    }

    public function test_registered_meta_fields() {
        global $wp_meta_keys;

        $expected_keys = [
            'event_allday',
            'event_start',
            'event_end',
            'event_location',
            'event_url',
            'event_category',
            'event_assigned_users',
        ];

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey(
                $key,
                $wp_meta_keys['post']['decker_event'],
                "Meta key {$key} should be registered for decker_event"
            );
        }
    }

public function test_end_date_is_adjusted_on_create_if_before_start() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

    $event_id = self::factory()->event->create( [
        'meta_input' => [
            'event_start' => '2025-01-01 12:00:00',
            'event_end'   => '2025-01-01 10:00:00', // Invalid: earlier than start
        ]
    ] );

    $this->assertNotWPError( $event_id );

    // Usar timestamps para comparación
    $start_utc_stored = get_post_meta( $event_id, 'event_start', true );
    $end_utc_stored   = get_post_meta( $event_id, 'event_end', true );

    $start_ts = strtotime( $start_utc_stored );
    $end_ts   = strtotime( $end_utc_stored );

    // Verificar que end es 1 hora después de start
    // $this->assertEquals($start_ts + 3600, $end_ts);

    // $start = get_post_meta( $event_id, 'event_start', true );
    // $end   = get_post_meta( $event_id, 'event_end', true );

    // End should be adjusted to match start
    // $this->assertEquals( $start, $end, 'End date should be adjusted to match start if earlier' );

    // Optional: assert the actual string value if you want to be strict
    // $this->assertEquals(
    //     '2025-01-01 12:00:00',
    //     $end,
    //     'End date should be equal to start date after adjustment.'
    // );

    $this->assertEquals( $start_ts + HOUR_IN_SECONDS, $end_ts, 'End date should be adjusted to 1 hour after start.' );


}

public function test_end_date_is_adjusted_on_update_if_before_start() {
    wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

    $event_id = self::factory()->event->create( [
        'post_title' => 'To Update',
        'meta_input' => [
            'event_allday' => false,
            'event_start'  => '2025-01-01 10:00:00',
            'event_end'    => '2025-01-01 11:00:00',
        ],
    ] );
    $this->assertNotWPError( $event_id );

    // Now, update with an invalid end date.
    self::factory()->event->update_object(
        $event_id,
        [
            'meta_input' => [
                'event_allday' => false,
                'event_start'  => '2025-01-01 12:00:00',
                'event_end'    => '2025-01-01 10:00:00', // Invalid: earlier than start
            ],
        ]
    );

    $start_utc_stored = get_post_meta( $event_id, 'event_start', true );
    $end_utc_stored   = get_post_meta( $event_id, 'event_end', true );

    $start_ts = strtotime( $start_utc_stored );
    $end_ts   = strtotime( $end_utc_stored );

    $this->assertEquals( $start_ts + HOUR_IN_SECONDS, $end_ts, 'End date should be adjusted to 1 hour after start.' );
}


    public function test_get_events_returns_expected_structure() {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
        $id = self::factory()->event->create();

        $events = Decker_Events::get_events();

        $this->assertNotEmpty( $events );
        $this->assertIsArray( $events[0] );
        $this->assertArrayHasKey( 'post', $events[0] );
        $this->assertArrayHasKey( 'meta', $events[0] );
        $this->assertEquals( $id, $events[0]['post']->ID );
    }

    /**
     * It should retrieve multiple events with correct meta using get_events().
     */
    public function test_get_events_returns_multiple_events_with_meta() {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

        // Define test events
        $events_data = [
            [
                'post_title' => 'Event A',
                'meta_input' => [
                    'event_start'    => '2025-01-01T10:00:00Z',
                    'event_end'      => '2025-01-01T11:00:00Z',
                    'event_location' => 'Room A',
                ],
            ],
            [
                'post_title' => 'Event B',
                'meta_input' => [
                    'event_start'    => '2025-01-02T12:00:00Z',
                    'event_end'      => '2025-01-02T13:30:00Z',
                    'event_location' => 'Room B',
                ],
            ],
        ];

        // Create events
        $event_ids = [];
        foreach ( $events_data as $data ) {
            $event_id = self::factory()->event->create( $data );
            $this->assertNotWPError( $event_id );
            $event_ids[] = $event_id;
        }

        // Fetch using get_events()
        $events = Decker_Events::get_events();

        // Filter down to just the ones we created
        $retrieved = array_filter( $events, function ( $event ) use ( $event_ids ) {
            return in_array( $event['post']->ID, $event_ids, true );
        } );

        $this->assertCount( 2, $retrieved, 'Should return exactly 2 matching events' );

        foreach ( $retrieved as $event ) {
            $id = $event['post']->ID;
            $expected = $events_data[ array_search( $id, $event_ids, true ) ];

            $this->assertEquals( $expected['post_title'], $event['post']->post_title, 'Post title should match' );
            $this->assertEquals( $expected['meta_input']['event_location'], $event['meta']['event_location'][0], 'Location should match' );
        }
    }

public function test_timezone_conversion_allday() {
    // Configurar zona horaria diferente
    update_option('timezone_string', 'America/New_York');
    
    $event_id = self::factory()->event->create([
        'meta_input' => [
            'event_allday' => true,
            'event_start'  => '2025-01-01 10:00:00', // Hora local (NY)
            'event_end'    => '2025-01-01 11:00:00',
        ]
    ]);
    
    $start_utc = get_post_meta($event_id, 'event_start', true);
    $this->assertStringNotContainsString('T', $start_utc); // No debe tener componente de tiempo
    $this->assertStringNotContainsString('Z', $start_utc); // No debe terminar con Z (UTC)
}


// En tu clase DeckerEventsTest

public function test_timezone_conversion() {
    // Set a different timezone for the test
    update_option('timezone_string', 'America/New_York'); // UTC-5 (or -4 in summer)
    
    $local_start_time = '2025-01-01 10:00:00'; // 10 AM in New York
    
    $event_id = self::factory()->event->create([
        'meta_input' => [
            'event_allday' => false,
            'event_start'  => $local_start_time,
            'event_end'    => '2025-01-01 11:00:00',
        ]
    ]);
    
    // Get the expected UTC time using the same function the code uses.
    $expected_utc_start_time = get_gmt_from_date( $local_start_time, 'Y-m-d H:i:s' );
    // This will be '2025-01-01 15:00:00' in winter

    $stored_start_utc = get_post_meta($event_id, 'event_start', true);
    
    // Assert that the stored value matches the expected UTC conversion.
    $this->assertEquals($expected_utc_start_time, $stored_start_utc);
    
    // Also assert it's a valid MySQL datetime format.
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $stored_start_utc);
}

public function test_daylight_saving_time() {
    // Un evento que cruza el cambio de horario
    $event_id = self::factory()->event->create([
        'meta_input' => [
            'event_start'  => '2025-03-09 01:30:00', // Antes del cambio
            'event_end'    => '2025-03-09 03:30:00', // Después del cambio
        ]
    ]);
    
    $start_utc = get_post_meta($event_id, 'event_start', true);
    $end_utc = get_post_meta($event_id, 'event_end', true);
    
    // Verificar que la diferencia es correcta (2 horas en lugar de 3)
    $start_ts = strtotime($start_utc);
    $end_ts = strtotime($end_utc);
    $this->assertEquals(2 * 3600, $end_ts - $start_ts);
}

public function test_allday_event_timezone() {
    update_option('timezone_string', 'Pacific/Auckland');
    
    $event_id = self::factory()->event->create([
        'meta_input' => [
            'event_allday' => true,
            'event_start'  => '2025-01-01',
        ]
    ]);
    
    $start = get_post_meta($event_id, 'event_start', true);
    $end = get_post_meta($event_id, 'event_end', true);
    
    $this->assertEquals('2025-01-01', $start);
    $this->assertEquals('2025-01-01', $end);
}



    /**
     * Test all-day event creation and storage
     */
    public function test_all_day_event_creation() {
        wp_set_current_user($this->editor);
        
        $event_id = self::factory()->event->create([
            'meta_input' => [
                'event_allday' => true,
                'event_start'  => '2025-01-01',
                'event_end'    => '2025-01-01',
            ]
        ]);
        
        $start = get_post_meta($event_id, 'event_start', true);
        $end   = get_post_meta($event_id, 'event_end', true);
        
        $this->assertEquals('2025-01-01', $start);
        $this->assertEquals('2025-01-01', $end);
    }

    /**
     * Test conversion between timezones for timed events
     */
    public function test_timezone_conversion_timed_event() {
        update_option('timezone_string', 'America/New_York');
        
        $event_id = self::factory()->event->create([
            'meta_input' => [
                'event_allday' => false,
                'event_start'  => '2025-01-01 10:00:00',
                'event_end'    => '2025-01-01 11:00:00',
            ]
        ]);
        
        $start_utc = get_post_meta($event_id, 'event_start', true);
        $end_utc   = get_post_meta($event_id, 'event_end', true);
        
        // EST is UTC-5, so 10:00 EST = 15:00 UTC
        $this->assertEquals('2025-01-01 15:00:00', $start_utc);
        $this->assertEquals('2025-01-01 16:00:00', $end_utc);
    }

    /**
     * Test daylight saving time handling
     */
    public function test_daylight_saving_time_transition() {
        update_option('timezone_string', 'America/New_York');
        
        // Event spanning DST change (March 9, 2025 - clocks spring forward)
        $event_id = self::factory()->event->create([
            'meta_input' => [
                'event_start' => '2025-03-09 01:30:00',
                'event_end'   => '2025-03-09 03:30:00',
            ]
        ]);
        
        $start_utc = get_post_meta($event_id, 'event_start', true);
        $end_utc   = get_post_meta($event_id, 'event_end', true);
        
        // Should be 6:30 UTC to 7:30 UTC (1 hour duration after DST adjustment)
        $this->assertEquals('2025-03-09 06:30:00', $start_utc);
        $this->assertEquals('2025-03-09 07:30:00', $end_utc);
    }

    /**
     * Test event update from all-day to timed
     */
    public function test_update_allday_to_timed() {
        $event_id = self::factory()->event->create([
            'meta_input' => [
                'event_allday' => true,
                'event_start'  => '2025-01-01',
                'event_end'    => '2025-01-01',
            ]
        ]);
        
        self::factory()->event->update_object($event_id, [
            'meta_input' => [
                'event_allday' => false,
                'event_start'  => '2025-01-01 10:00:00',
                'event_end'    => '2025-01-01 11:00:00',
            ]
        ]);
        
        $start = get_post_meta($event_id, 'event_start', true);
        $end   = get_post_meta($event_id, 'event_end', true);
        
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $start);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $end);
    }


}



