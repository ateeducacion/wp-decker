<?php
/**
 * Extra test-cases for Decker_Events date / all-day handling.
 *
 * @package Decker
 */
class DeckerEventsExtraDateTest extends Decker_Test_Base {

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
	 * Creating an all-day event stores only the date part.
	 *
	 * @dataProvider dp_all_day_valid
	 */
	public function test_create_all_day_event_stores_date_only( $start_local, $end_local ) {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => $start_local,
					'event_end'    => $end_local,
				),
			)
		);

		$this->assertNotWPError( $event_id );

		$stored_start = get_post_meta( $event_id, 'event_start', true );
		$stored_end   = get_post_meta( $event_id, 'event_end', true );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $stored_start );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $stored_end );

		// If empty end was supplied, expect it to be set to start.
		if ( '' === $end_local ) {
			$this->assertSame( $stored_start, $stored_end );
		}
	}

	/**
	 * When end < start the plugin auto-fixes it.
	 *
	 * @dataProvider dp_end_before_start
	 */
	public function test_create_adjusts_end_when_before_start( $all_day, $start, $end ) {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => $all_day,
					'event_start'  => $start,
					'event_end'    => $end,
				),
			)
		);

		$this->assertNotWPError( $event_id );

		$stored_start = get_post_meta( $event_id, 'event_start', true );
		$stored_end   = get_post_meta( $event_id, 'event_end', true );

		if ( $all_day ) {
			$this->assertSame( $stored_start, $stored_end );
		} else {
			$this->assertEquals( strtotime( $stored_start ) + HOUR_IN_SECONDS, strtotime( $stored_end ) );
		}
	}

	/**
	 * Updating an event from all-day to datetime and vice-versa.
	 */
	public function test_update_switch_all_day_flag() {
		// Create as all-day.
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-03-15',
					'event_end'    => '2025-03-15',
				),
			)
		);

		// Switch to datetime.
		self::factory()->event->update_object(
			$event_id,
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-03-15 09:00:00',
					'event_end'    => '2025-03-15 10:30:00',
				),
			)
		);

        $this->assertFalse(get_post_meta( $this->event_id, 'event_allday', true ), 'After switch, event_allday should be "0".' );

		$this->assertEmpty( get_post_meta( $event_id, 'event_allday', true ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', get_post_meta( $event_id, 'event_start', true ) );

		// Switch back to all-day.
		self::factory()->event->update_object(
			$event_id,
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-03-20',
					'event_end'    => '2025-03-22',
				),
			)
		);

		$this->assertSame( '1', get_post_meta( $event_id, 'event_allday', true ) );
		$this->assertSame( '2025-03-20', get_post_meta( $event_id, 'event_start', true ) );
	}

	/**
	 * REST: create all-day event via API.
	 */
	public function test_rest_create_all_day() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'REST All-Day' );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				'event_allday' => true,
				'event_start'  => '2025-05-01',
				'event_end'    => '2025-05-03',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 201, $response->get_status() );

		$this->assertSame( '2025-05-01', $data['meta']['event_start'] );
		$this->assertSame( '2025-05-03', $data['meta']['event_end'] );

		wp_delete_post( $data['id'], true );
	}

	/**
     * Tests REST update of a datetime event via the default /wp/v2/decker_event endpoint.
     */
    public function test_rest_update_datetime() {
        // Create a timed event
        $this->event_id = self::factory()->event->create( [
            'meta_input' => [
                'event_start' => '2025-02-10T08:00:00',
                'event_end'   => '2025-02-10T09:00:00',
            ],
        ] );

        // Update via REST
        $request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$this->event_id}" );
        $request->set_param( 'meta', [
            'event_start' => '2025-02-10T14:30:00',
            'event_end'   => '2025-02-10T16:00:00',
        ] );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        // Confirm stored in UTC (Madrid is UTC+1 in February)
        $stored = get_post_meta( $this->event_id, 'event_start', true );
        $expected = get_gmt_from_date( '2025-02-10 14:30:00', 'Y-m-d H:i:s' );
        $this->assertEquals( $expected, $stored, 'REST update should convert local time to UTC.' );
    }

	/**
	 * REST: delete an event.
	 */
	public function test_rest_delete_event() {
		wp_set_current_user( $this->editor );

		$event_id = self::factory()->event->create(
			array(
				'post_title' => 'To Delete',
			)
		);

		$request  = new WP_REST_Request( 'DELETE', "/wp/v2/decker_event/{$event_id}" );
		$request->set_param( 'force', true );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( get_post( $event_id ) );
	}

	/**
	 * Malformed date strings should not fatal.
	 */
	public function test_malformed_date_does_not_fatal() {
		// Expect WP_Error from factory, not a PHP fatal.
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => 'not-a-date',
					'event_end'   => 'also-bad',
				),
			)
		);

		$this->assertNotWPError( $event_id );
	}

}