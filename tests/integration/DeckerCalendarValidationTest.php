<?php
/** Calendar validation, recurrence preservation and presentation boundaries. */
class DeckerCalendarValidationTest extends WP_UnitTestCase {
	private $request_data;
	public function set_up() {
		parent::set_up();
		$this->request_data = array( $_GET, $_POST );
	}
	public function tear_down() {
		list( $_GET, $_POST ) = $this->request_data;
		parent::tear_down();
	}
	private function calendar( $properties = '', $dates = "DTSTART:20261104T100000Z\r\nDTEND:20261104T110000Z", $components = '' ) {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Decker tests//EN\r\nBEGIN:VEVENT\r\nUID:validation@example.test\r\nDTSTAMP:20261101T100000Z\r\n$dates\r\n$properties" . "END:VEVENT\r\n$components" . "END:VCALENDAR\r\n";
	}

	/** @dataProvider invalid_resources */
	public function test_invalid_resource_is_rejected_without_exposing_content( $data ) {
		try {
			Decker_Event_Store::parse( $data );
			$this->fail( 'Invalid input must be rejected.' );
		} catch ( Sabre\DAV\Exception\BadRequest $error ) {
			$this->assertSame( 'Invalid or unsupported iCalendar resource.', $error->getMessage() );
		}
	}
	public function invalid_resources() {
		$valid = $this->calendar();
		return array(
			'oversized' => array( str_repeat( 'x', 1048577 ) ),
			'not a calendar' => array( "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Private name\r\nEND:VCARD\r\n" ),
			'scheduling method' => array( str_replace( 'VERSION:2.0', "VERSION:2.0\r\nMETHOD:REQUEST", $valid ) ),
			'non event' => array( str_replace( 'VEVENT', 'VTODO', $valid ) ),
			'missing start' => array( str_replace( "DTSTART:20261104T100000Z\r\n", '', $valid ) ),
			'different UID' => array( $this->calendar( '', "DTSTART:20261104T100000Z", "BEGIN:VEVENT\r\nUID:another@example.test\r\nDTSTAMP:20261101T100000Z\r\nDTSTART:20261105T100000Z\r\nEND:VEVENT\r\n" ) ),
			'no master' => array( $this->calendar( "RECURRENCE-ID:20261104T100000Z\r\n" ) ),
			'malformed date' => array( str_replace( '20261104T100000Z', 'not-a-date', $valid ) ),
			'invalid hour' => array( str_replace( '20261104T100000Z', '20261104T250000Z', $valid ) ),
			'period RDATE' => array( $this->calendar( "RDATE;VALUE=PERIOD:20261105T100000Z/20261105T110000Z\r\n" ) ),
			'future range' => array( $this->calendar( "RECURRENCE-ID;RANGE=THISANDFUTURE:20261104T100000Z\r\n" ) ),
			'end and duration' => array( $this->calendar( "DURATION:PT1H\r\n" ) ),
			'end before start' => array( str_replace( 'DTEND:20261104T110000Z', 'DTEND:20261104T090000Z', $valid ) ),
			'required version' => array( str_replace( "VERSION:2.0\r\n", '', $valid ) ),
		);
	}

	public function test_timezone_definition_and_unchanged_series_dates_survive_web_patch() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$zone = "BEGIN:VTIMEZONE\r\nTZID:Atlantic/Canary\r\nBEGIN:STANDARD\r\nDTSTART:19701025T020000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
		$data = $this->calendar( "RRULE:FREQ=DAILY;COUNT=3\r\n", "DTSTART;TZID=Atlantic/Canary:20261104T100000\r\nDTEND;TZID=Atlantic/Canary:20261104T110000", $zone );
		$id = Decker_Event_Store::put( 0, 'timezone.ics', $data );
		$patch = array( 'event_start' => '2026-11-04 10:00:00', 'event_end' => '2026-11-04 11:00:00', 'event_allday' => false );
		Decker_Event_Store::web_meta( $id, $patch );
		$this->assertStringContainsString( 'DTSTART;TZID=Atlantic/Canary:', Decker_Event_Store::resource( $id ) );
		$this->assertStringContainsString( 'BEGIN:VTIMEZONE', Decker_Event_Store::resource( $id ) );
		$before = Decker_Event_Store::resource( $id );
		$patch['event_start'] = '2026-11-04 09:00:00';
		try {
			Decker_Event_Store::web_meta( $id, $patch );
			$this->fail( 'Recurring date edits must be rejected.' );
		} catch ( Sabre\DAV\Exception\Conflict $error ) {
			$this->assertSame( $before, Decker_Event_Store::resource( $id ) );
		}
	}

	public function test_direct_metadata_date_patch_reconciles_canonical_resource() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = Decker_Event_Store::put( 0, 'dates.ics', $this->calendar() );
		update_post_meta( $id, 'event_start', '2026-11-04 12:00:00' );
		update_post_meta( $id, 'event_end', '2026-11-04 13:00:00' );
		$data = Decker_Event_Store::resource( $id );
		$this->assertStringContainsString( 'DTSTART:20261104T120000Z', $data );
		$this->assertStringContainsString( 'DTEND:20261104T130000Z', $data );
		$this->assertSame( $data, Decker_Event_Store::resource( $id ) );
	}

	public function test_calendar_window_is_bounded_and_cancelled_occurrences_are_hidden() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		Decker_Event_Store::put( 0, 'cancelled.ics', $this->calendar( "STATUS:CANCELLED\r\n" ) );
		$calendar = new Decker_Calendar();
		$this->assertSame( array(), $calendar->get_occurrences( 'event', '2026-11-01', '2026-12-01' ) );
		foreach ( array( array( '2026-11-04', '2026-11-03' ), array( '2026-01-01', '2028-01-01' ), array( 'invalid', '2026-12-01' ) ) as $range ) {
			$error = $calendar->get_occurrences( '', $range[0], $range[1] );
			$this->assertWPError( $error );
			$this->assertSame( 400, $error->get_error_data()['status'] );
		}
	}

	public function test_all_day_modal_displays_inclusive_end_without_changing_storage() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = Decker_Event_Store::put( 0, 'all-day.ics', $this->calendar( '', "DTSTART;VALUE=DATE:20261104\r\nDTEND;VALUE=DATE:20261105" ) );
		$_GET = array( 'id' => $id, 'nonce' => wp_create_nonce( 'decker_event_card' ) );
		ob_start();
		try {
			include dirname( __DIR__, 2 ) . '/public/layouts/event-card.php';
			$html = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertMatchesRegularExpression( '/id="event-end"[^>]+data-utc="2026-11-04"/s', $html );
		$this->assertSame( '2026-11-05', get_post_meta( $id, 'event_end', true ) );
	}

	public function test_admin_form_rejects_invalid_dates_instead_of_persisting_them() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = Decker_Event_Store::put( 0, 'admin.ics', $this->calendar() );
		$_POST = array( 'decker_event_meta_box_nonce' => wp_create_nonce( 'decker_event_meta_box' ), 'event_start' => 'not-a-date' );
		try {
			( new Decker_Event_Meta_Saver() )->save_event_meta( $id, get_post( $id ), true );
			$this->fail( 'The form must stop with an error.' );
		} catch ( WPDieException $error ) {
			$this->assertSame( 'Invalid calendar dates.', $error->getMessage() );
			$this->assertSame( '2026-11-04 10:00:00', get_post_meta( $id, 'event_start', true ) );
		}
	}
	public function test_dav_dispatch_does_not_intercept_web_rest_or_similar_path_prefixes() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user );
		$original = $_SERVER['REQUEST_URI'] ?? null;
		try {
			foreach ( array( '/', '/wp-admin/profile.php', '/wp-json/wp/v2/users/me', '/decker-dav-unrelated/' ) as $path ) {
				$_SERVER['REQUEST_URI'] = $path;
				Decker_Dav::dispatch( new WP() );
				$this->assertSame( $user, get_current_user_id() );
			}
		} finally {
			if ( null === $original ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $original; }
		}
	}

	public function test_calendar_lock_contention_returns_unavailable_instead_of_proceeding() {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$wpdb->prefix = 'decker_contention_' . wp_generate_password( 8, false );
		$key = 'decker-calendar-' . md5( DB_NAME . $wpdb->prefix );
		try {
			$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) );
			try {
				Decker_Dav::lock();
				$this->fail( 'A competing connection must prevent the calendar operation.' );
			} catch ( Sabre\DAV\Exception\ServiceUnavailable $error ) {
				$this->assertSame( 503, $error->getHTTPCode() );
			}
		} finally {
			$wpdb->prefix = $prefix;
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			$other->close();
		}
	}

}
