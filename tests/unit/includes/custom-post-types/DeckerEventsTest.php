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
	 * An editor should be able to create an event.
	 */
	public function test_editor_can_create_event() {
		wp_set_current_user( $this->editor );

		$expected_start_utc = '2025-01-01 10:00:00'; // already UTC
		$expected_end_utc   = '2025-01-01 11:00:00'; // already UTC

		// Create the event via factory->create()
		$event_result = self::factory()->event->create(
			array(
				'post_title'   => 'Test Event',
				'post_content' => 'Event description',
				'post_author'  => $this->editor,
				'meta_input'   => array(
					'event_allday'         => false,
					'event_start'          => $expected_start_utc,
					'event_end'            => $expected_end_utc,
					'event_location'       => 'Test Location',
					'event_url'            => 'https://example.com',
					'event_category'       => 'bg-primary',
					'event_assigned_users' => array( $this->editor ),
				),
			)
		);

		// Ensure creation succeeded
		$this->assertNotWPError( $event_result, 'The event should be created successfully.' );
		$this->event_id = $event_result;

		// Fetch the post and verify core fields
		$event = get_post( $this->event_id );
		$this->assertEquals( 'Test Event', $event->post_title, 'Title should match.' );
		$this->assertEquals( 'Event description', $event->post_content, 'Content should match.' );
		$this->assertEquals( $this->editor, $event->post_author, 'Author should match.' );

		// Verify all meta fields
		$expected_meta = array(
			'event_allday'         => false,
			'event_start'          => $expected_start_utc,
			'event_end'            => $expected_end_utc,
			'event_location'       => 'Test Location',
			'event_url'            => 'https://example.com',
			'event_category'       => 'bg-primary',
			'event_assigned_users' => array( $this->editor ),
		);

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
		$this->event_id = self::factory()->event->create(
			array(
				'post_title'   => 'Original Event',
				'post_content' => 'Original content',
				'meta_input'   => array(
					'event_location' => 'Original Location',
				),
			)
		);

		$this->assertNotWPError( $this->event_id, 'Initial creation should not error.' );

		// Now update title + two meta fields
		$updated_id = self::factory()->event->update_object(
			$this->event_id,
			array(
				'post_title' => 'Updated Event',
				'meta_input' => array(
					'event_location' => 'Updated Location',
					'event_category' => 'bg-success',
				),
			)
		);

		$this->assertNotWPError( $updated_id, 'Update should not return a WP_Error.' );
		$this->assertEquals( $this->event_id, $updated_id, 'Update should return same event ID.' );

		$event = get_post( $this->event_id );
		$this->assertEquals( 'Updated Event', $event->post_title, 'Title should be updated.' );

		$updated_meta = array(
			'event_location' => 'Updated Location',
			'event_category' => 'bg-success',
		);

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

		$this->event_id = self::factory()->event->create(
			array(
				'post_title'   => 'Original Event',
				'post_content' => 'Original content',
				'post_excerpt' => 'Original excerpt',
				'meta_input'   => array(
					'event_allday'         => false,
					'event_start'          => '2025-01-01 10:00:00',
					'event_end'            => '2025-01-01 12:00:00',
					'event_location'       => 'Room A',
					'event_url'            => 'https://old.example.com',
					'event_category'       => 'bg-warning',
					'event_assigned_users' => array( $this->editor ),
				),
			)
		);

		$this->assertNotWPError( $this->event_id );

		$updated_id = self::factory()->event->update_object(
			$this->event_id,
			array(
				'post_title'   => 'Updated Event',
				'post_content' => 'Updated content',
				'post_excerpt' => 'Updated excerpt',
				'meta_input'   => array(
					'event_allday'         => true,
					'event_start'          => '2025-02-01',
					'event_end'            => '2025-02-01',
					'event_location'       => 'Room B',
					'event_url'            => 'https://new.example.com',
					'event_category'       => 'bg-info',
					'event_assigned_users' => array(),
				),
			)
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

		$meta = array(
			'event_allday'         => true,
			'event_start'          => '2025-02-01',
			'event_end'            => '2025-02-01',
			'event_location'       => 'Room B',
			'event_url'            => 'https://new.example.com',
			'event_category'       => 'bg-info',
			'event_assigned_users' => array(),
		);

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

		$event_id = self::factory()->event->create(
			array(
				'post_title' => 'Deletable Event',
			)
		);

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

		$events = self::factory()->event->create_many(
			3,
			array(
				'post_author' => $this->editor,
				'post_status' => 'publish',
			)
		);

		$this->assertCount( 3, $events, 'Three events should be created.' );

		foreach ( $events as $id ) {
			$this->assertNotWPError( $id );
			$this->assertEquals( 'decker_event', get_post_type( $id ) );
		}
	}

	public function test_registered_meta_fields() {
		global $wp_meta_keys;

		$expected_keys = array(
			'event_allday',
			'event_start',
			'event_end',
			'event_location',
			'event_url',
			'event_category',
			'event_assigned_users',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$wp_meta_keys['post']['decker_event'],
				"Meta key {$key} should be registered for decker_event"
			);
		}
	}

	public function test_end_date_is_adjusted_on_create_if_before_start() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start' => '2025-01-01 12:00:00',
					'event_end'   => '2025-01-01 10:00:00', // Invalid: earlier than start
				),
			)
		);

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
		// '2025-01-01 12:00:00',
		// $end,
		// 'End date should be equal to start date after adjustment.'
		// );

		$this->assertEquals( $start_ts + HOUR_IN_SECONDS, $end_ts, 'End date should be adjusted to 1 hour after start.' );
	}

	public function test_end_date_is_adjusted_on_update_if_before_start() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$event_id = self::factory()->event->create(
			array(
				'post_title' => 'To Update',
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-01-01 10:00:00',
					'event_end'    => '2025-01-01 11:00:00',
				),
			)
		);
		$this->assertNotWPError( $event_id );

		// Now, update with an invalid end date.
		self::factory()->event->update_object(
			$event_id,
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-01-01 12:00:00',
					'event_end'    => '2025-01-01 10:00:00', // Invalid: earlier than start
				),
			)
		);

		$start_utc_stored = get_post_meta( $event_id, 'event_start', true );
		$end_utc_stored   = get_post_meta( $event_id, 'event_end', true );

		$start_ts = strtotime( $start_utc_stored );
		$end_ts   = strtotime( $end_utc_stored );

		$this->assertEquals( $start_ts + HOUR_IN_SECONDS, $end_ts, 'End date should be adjusted to 1 hour after start.' );
	}


	public function test_get_events_returns_expected_structure() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Define test events
		$events_data = array(
			array(
				'post_title' => 'Event A',
				'meta_input' => array(
					'event_start'    => '2025-01-01T10:00:00Z',
					'event_end'      => '2025-01-01T11:00:00Z',
					'event_location' => 'Room A',
				),
			),
			array(
				'post_title' => 'Event B',
				'meta_input' => array(
					'event_start'    => '2025-01-02T12:00:00Z',
					'event_end'      => '2025-01-02T13:30:00Z',
					'event_location' => 'Room B',
				),
			),
		);

		// Create events
		$event_ids = array();
		foreach ( $events_data as $data ) {
			$event_id = self::factory()->event->create( $data );
			$this->assertNotWPError( $event_id );
			$event_ids[] = $event_id;
		}

		// Fetch using get_events()
		$events = Decker_Events::get_events();

		// Filter down to just the ones we created
		$retrieved = array_filter(
			$events,
			function ( $event ) use ( $event_ids ) {
				return in_array( $event['post']->ID, $event_ids, true );
			}
		);

		$this->assertCount( 2, $retrieved, 'Should return exactly 2 matching events' );

		foreach ( $retrieved as $event ) {
			$id       = $event['post']->ID;
			$expected = $events_data[ array_search( $id, $event_ids, true ) ];

			$this->assertEquals( $expected['post_title'], $event['post']->post_title, 'Post title should match' );
			$this->assertEquals( $expected['meta_input']['event_location'], $event['meta']['event_location'][0], 'Location should match' );
		}
	}

	/**
	 * Test all-day event creation and storage
	 */
	public function test_all_day_event_creation() {
		wp_set_current_user( $this->editor );

		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-01-01',
					'event_end'    => '2025-01-01',
				),
			)
		);

		$start = get_post_meta( $event_id, 'event_start', true );
		$end   = get_post_meta( $event_id, 'event_end', true );

		$this->assertEquals( '2025-01-01', $start );
		$this->assertEquals( '2025-01-01', $end );
	}


	/**
	 * Test event update from all-day to timed
	 */
	public function test_update_allday_to_timed() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-01-01',
					'event_end'    => '2025-01-01',
				),
			)
		);

		self::factory()->event->update_object(
			$event_id,
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-01-01 10:00:00',
					'event_end'    => '2025-01-01 11:00:00',
				),
			)
		);

		$start = get_post_meta( $event_id, 'event_start', true );
		$end   = get_post_meta( $event_id, 'event_end', true );

		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $start );
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $end );
	}

	public function test_can_update_assigned_users() {
		wp_set_current_user( $this->editor );

		$other_user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Crear evento con un usuario asignado
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_assigned_users' => array( $this->editor ),
				),
			)
		);

		// Confirmar asignación inicial
		$this->assertEquals(
			array( $this->editor ),
			get_post_meta( $this->event_id, 'event_assigned_users', true ),
			'Initial assigned users should match.'
		);

		// Actualizar usuarios asignados
		$updated_id = self::factory()->event->update_object(
			$this->event_id,
			array(
				'meta_input' => array(
					'event_assigned_users' => array( $this->editor, $other_user ),
				),
			)
		);

		$this->assertEquals(
			array( $this->editor, $other_user ),
			get_post_meta( $this->event_id, 'event_assigned_users', true ),
			'Updated assigned users should match.'
		);
	}

	public function test_can_clear_assigned_users() {
		wp_set_current_user( $this->editor );

		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_assigned_users' => array( $this->editor ),
				),
			)
		);

		$this->assertNotEmpty(
			get_post_meta( $this->event_id, 'event_assigned_users', true ),
			'There should be at least one assigned user initially.'
		);

		// Eliminar todos los usuarios asignados
		$updated_id = self::factory()->event->update_object(
			$this->event_id,
			array(
				'meta_input' => array(
					'event_assigned_users' => array(),
				),
			)
		);

		$this->assertEquals(
			array(),
			get_post_meta( $this->event_id, 'event_assigned_users', true ),
			'Assigned users should be empty after clearing.'
		);
	}

	public function test_assigned_users_unchanged_if_omitted_on_update() {
		wp_set_current_user( $this->editor );

		$other_user = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_assigned_users' => array( $this->editor, $other_user ),
				),
			)
		);

		$before = get_post_meta( $this->event_id, 'event_assigned_users', true );

		// Actualizar el evento sin tocar assigned_users
		$updated_id = self::factory()->event->update_object(
			$this->event_id,
			array(
				'post_title' => 'Updated title only',
			)
		);

		$after = get_post_meta( $this->event_id, 'event_assigned_users', true );

		$this->assertEquals(
			$before,
			$after,
			'Assigned users should not change if field is omitted.'
		);
	}
}
