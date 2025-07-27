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
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
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

		$this->assertFalse( get_post_meta( $this->event_id, 'event_allday', true ), 'After switch, event_allday should be "0".' );

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
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => '2025-02-10T08:00:00Z',
					'event_end'   => '2025-02-10T09:00:00Z',
				),
			)
		);

		// Update via REST
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$this->event_id}" );
		$request->set_param(
			'meta',
			array(
				'event_start' => '2025-02-10T14:30:00Z',
				'event_end'   => '2025-02-10T16:00:00Z',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		// Confirm stored in UTC (Madrid is UTC+1 in February)
		$stored   = get_post_meta( $this->event_id, 'event_start', true );
		$expected = '2025-02-10 14:30:00';
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

		$request = new WP_REST_Request( 'DELETE', "/wp/v2/decker_event/{$event_id}" );
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

	/* ---------------------------------------------------------------------
	 *  NUEVOS TESTS → manejo de “all‑day” vs horas y segundos
	 * ------------------------------------------------------------------ */

	/**
	 * All‑day = true pero se envía una fecha con hora → debe guardarse solo la fecha.
	 */
	public function test_create_all_day_strips_time() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-12-24 13:45:59',
					'event_end'    => '2025-12-25 07:00:12',
				),
			)
		);
		$this->assertNotWPError( $event_id );

		$this->assertSame( '2025-12-24', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-12-25', get_post_meta( $event_id, 'event_end', true ) );
	}

	/**
	 * All‑day = false pero se envía solo fecha → debe añadirse 00:00:00
	 * (y end será start + 1 h según la lógica de process_and_save_meta()).
	 */
	public function test_create_datetime_with_date_only_sets_midnight() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-05-10',
					'event_end'    => '',
				),
			)
		);
		$this->assertNotWPError( $event_id );

		$this->assertSame( '2025-05-10 00:00:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-05-10 01:00:00', get_post_meta( $event_id, 'event_end', true ) );
	}

	/**
	 * REST create: all‑day con hora → el API debe devolver y almacenar solo la fecha.
	 */
	public function test_rest_create_all_day_strips_time() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'REST All‑Day Strip' );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				'event_allday' => true,
				'event_start'  => '2026-01-02T09:30:00Z',
				'event_end'    => '2026-01-02T11:00:00Z',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2026-01-02', get_post_meta( $data['id'], 'event_start', true ) );
		$this->assertSame( '2026-01-02', $data['meta']['event_start'] );
		$this->assertSame( '2026-01-02', get_post_meta( $data['id'], 'event_end', true ) );
		$this->assertSame( '2026-01-02', $data['meta']['event_end'] );
	}

	/**
	 * REST update: datetime → se pasa solo fecha → debe corregir a 00:00:00.
	 */
	public function test_rest_update_datetime_with_date_only_sets_midnight() {
		/* 1 · Creamos evento con hora correcta */
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-11-05 15:00:00',
					'event_end'    => '2025-11-05 16:00:00',
				),
			)
		);

		/* 2 · Actualizamos vía REST mandando solo la fecha */
		wp_set_current_user( $this->editor );
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$event_id}" );
		$request->set_param(
			'meta',
			array(
				'event_allday' => false,
				'event_start'  => '2025-11-06', // solo fecha
				'event_end'    => '2025-11-06', // solo fecha
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( '2025-11-06 00:00:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-11-06 01:00:00', get_post_meta( $event_id, 'event_end', true ) );
	}

	/**
	 * Crear, actualizar y verificar almacenamiento y salida REST de eventos.
	 */
	public function test_rest_create_and_update_datetime_event_check_db_and_rest() {
		wp_set_current_user( $this->editor );

		// Crear evento vía REST.
		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'REST Timed Event' );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				'event_allday' => false,
				'event_start'  => '2025-10-05T14:00:00Z',
				'event_end'    => '2025-10-05T15:30:00Z',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$event_id = $data['id'];

		// Verificar en base de datos (debe estar en UTC sin la Z).
		$this->assertSame( '2025-10-05 14:00:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-10-05 15:30:00', get_post_meta( $event_id, 'event_end', true ) );

		// Verificar en la respuesta REST (debería tener formato ISO8601 con Z).
		$this->assertSame( '2025-10-05 14:00:00', $data['meta']['event_start'] );
		$this->assertSame( '2025-10-05 15:30:00', $data['meta']['event_end'] );

		// Actualizar vía REST solo la hora de inicio.
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$event_id}" );
		$request->set_param(
			'meta',
			array(
				'event_start' => '2025-10-05T18:00:00Z',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// Verificación tras la actualización.
		$this->assertSame( '2025-10-05 18:00:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-10-05 18:00:00', $data['meta']['event_start'] );
	}

	/**
	 * Crea un evento directamente y luego lo actualiza (sin REST).
	 */
	public function test_direct_update_event_datetime() {
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-08-01 09:00:00',
					'event_end'    => '2025-08-01 10:00:00',
				),
			)
		);

		// Verificar formato almacenado.
		$this->assertSame( '2025-08-01 09:00:00', get_post_meta( $this->event_id, 'event_start', true ) );

		// Actualizar valores directamente.
		update_post_meta( $this->event_id, 'event_start', '2025-08-01 12:00:00' );
		update_post_meta( $this->event_id, 'event_end', '2025-08-01 13:00:00' );

		$this->assertSame( '2025-08-01 12:00:00', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '2025-08-01 13:00:00', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * Crea y actualiza un evento all-day vía REST, y verifica que almacene solo la fecha.
	 */
	public function test_rest_update_all_day_event_strips_time() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'REST Update All-Day' );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				'event_allday' => true,
				'event_start'  => '2026-03-15',
				'event_end'    => '2026-03-16',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$event_id = $data['id'];

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2026-03-15', get_post_meta( $event_id, 'event_start', true ) );

		// Ahora actualizar mandando una fecha con hora (debe ignorar la hora)
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$event_id}" );
		$request->set_param(
			'meta',
			array(
				'event_start' => '2026-03-17T10:30:00Z',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( '2026-03-17', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2026-03-17', $data['meta']['event_start'] );
	}

	public function test_create_multiple_events_back_to_back() {
		$id1 = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-10-01 10:00:00',
					'event_end'    => '2025-10-01 11:00:00',
				),
			)
		);

		$id2 = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-10-02',
					'event_end'    => '2025-10-02',
				),
			)
		);

		$this->assertNotWPError( $id1 );
		$this->assertNotWPError( $id2 );
		$this->assertNotSame( $id1, $id2 );
	}

	public function test_rest_create_missing_event_start() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event' );
		$request->set_param( 'title', 'Missing Start' );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				'event_end' => '2026-01-01T12:00:00Z',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertEmpty( get_post_meta( $data['id'], 'event_start', true ) );
	}

	public function test_create_missing_event_end() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-09-01 14:00:00',
				),
			)
		);

		$this->assertNotWPError( $event_id );

		// End should be start + 1 h
		$expected_end = '2025-09-01 15:00:00';
		$this->assertSame( $expected_end, get_post_meta( $event_id, 'event_end', true ) );
	}

	public function test_create_event_with_invalid_start_date() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => 'not-a-date',
					'event_end'    => '2025-01-01 10:00:00',
				),
			)
		);

		$this->assertNotWPError( $event_id );

		// It should be forced to 1970-01-01 00:00:00 according to the plugin's logic
		$this->assertSame( '1970-01-01 00:00:00', get_post_meta( $event_id, 'event_start', true ) );
	}


	public function test_rest_update_invalid_event_end() {
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => '2025-06-01 08:00:00',
					'event_end'   => '2025-06-01 09:00:00',
				),
			)
		);

		wp_set_current_user( $this->editor );
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/{$this->event_id}" );
		$request->set_param(
			'meta',
			array(
				'event_end' => 'invalid-end',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		// It should be automatically corrected
		$end = get_post_meta( $this->event_id, 'event_end', true );
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $end );
	}

	/**
	 * Test updating an all-day event via REST API.
	 */
	public function test_rest_update_all_day_event() {
		wp_set_current_user( $this->editor );

		// Create initial event
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-04-10',
					'event_end'    => '2025-04-11',
				),
			)
		);

		// Send PUT request
		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/$event_id" );
		$request->set_param(
			'meta',
			array(
				'event_start'  => '2025-04-12',
				'event_end'    => '2025-04-13',
				'event_allday' => true,
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-04-12', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-04-13', get_post_meta( $event_id, 'event_end', true ) );
		$this->assertSame( '2025-04-12', $data['meta']['event_start'] );
		$this->assertSame( '2025-04-13', $data['meta']['event_end'] );
	}

	/**
	 * Test updating a datetime event via REST API.
	 */
	public function test_rest_update_datetime_event() {
		wp_set_current_user( $this->editor );

		// Create timed event
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-06-01 10:00:00',
					'event_end'    => '2025-06-01 11:00:00',
				),
			)
		);

		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/$event_id" );
		$request->set_param(
			'meta',
			array(
				'event_start' => '2025-06-02T15:30:00Z',
				'event_end'   => '2025-06-02T17:00:00Z',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-06-02 15:30:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-06-02 17:00:00', get_post_meta( $event_id, 'event_end', true ) );
		$this->assertSame( '2025-06-02 15:30:00', $data['meta']['event_start'] );
		$this->assertSame( '2025-06-02 17:00:00', $data['meta']['event_end'] );
	}

	/**
	 * Test that malformed date input doesn't crash the endpoint.
	 */
	public function test_rest_update_with_invalid_date() {
		wp_set_current_user( $this->editor );

		$event_id = self::factory()->event->create();

		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/$event_id" );
		$request->set_param(
			'meta',
			array(
				'event_start' => 'no-es-una-fecha',
				'event_end'   => 'tampoco-es-valida',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotInstanceOf( WP_Error::class, get_post_meta( $event_id, 'event_start', true ) );
		$this->assertEmpty( get_post_meta( $event_id, 'event_start', true ) );
	}

	/**
	 * Test updating only one field (partial update).
	 */
	public function test_rest_partial_update_only_start() {
		wp_set_current_user( $this->editor );

		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => '2025-07-01 10:00:00',
					'event_end'   => '2025-07-01 12:00:00',
				),
			)
		);

		$request = new WP_REST_Request( 'PUT', "/wp/v2/decker_event/$event_id" );
		$request->set_param(
			'meta',
			array(
				'event_start' => '2025-07-01T14:00:00Z',
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2025-07-01 14:00:00', get_post_meta( $event_id, 'event_start', true ) );
		$this->assertSame( '2025-07-01 14:00:00', $data['meta']['event_start'] );
		$this->assertSame( '2025-07-01 15:00:00', get_post_meta( $event_id, 'event_end', true ) );
	}
}
