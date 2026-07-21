<?php
/**
 * Integration tests for Decker Calendar ICS download
 * (now re-using the public generate_ical_string() method)
 *
 * @package Decker
 */

use om\IcalParser;

class Decker_Calendar_ICS_Test extends Decker_Test_Base {

	/**
	 * Per-user calendar token used to authenticate the feed requests.
	 *
	 * @var string
	 */
	private $calendar_token = '';

	/**
	 * Make sure the rewrite endpoint exists for every test.
	 */
	public function set_up() {
		parent::set_up();

		// Re-register CPT and endpoints (init has already fired in bootstrap)
		do_action( 'init' );

		// Flush rewrite rules once
		global $wp_rewrite;
		$wp_rewrite->init();                      // reset internal state
		$wp_rewrite->add_endpoint( 'decker-calendar', EP_ROOT );
		$wp_rewrite->flush_rules( false );        // soft flush

		// The iCal feed now requires authentication. Provision a user with a
		// per-user calendar token so the requests below can authenticate via
		// the ?token= parameter without relying on a logged-in session.
		$user_id              = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->calendar_token = 'test-calendar-token-' . $user_id;
		update_user_meta( $user_id, 'decker_calendar_token', $this->calendar_token );
	}

	/**
	 * Build a feed URL appending the calendar token for authentication.
	 *
	 * @param string $query Query string (without leading ?).
	 * @return string
	 */
	private function feed_url( string $query ): string {
		return home_url( '/?' . $query . '&token=' . rawurlencode( $this->calendar_token ) );
	}

	/**
	 * Parse raw ICS and return a plain array of events.
	 *
	 * @param string $ical_content
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_ics_with_debug( string $ical_content ): array {
		$ical = new IcalParser();
		try {
			$ical->parseString( $ical_content );
		} catch ( Exception $e ) {
			$this->fail(
				"ICS parse error: {$e->getMessage()}\n\n--- ICS BEGIN ---\n{$ical_content}\n---  ICS END  ---"
			);
		}

		$events = array();
		foreach ( $ical->getEvents() as $event ) {
			foreach ( array( 'DTSTART', 'DTEND' ) as $k ) {
				if ( ! isset( $event[ $k ] ) ) {
					continue;
				}
				/** @var \DateTime $dt */
				$dt          = $event[ $k ];
				$isFullDay   = isset( $event[ $k . '_array' ][0]['VALUE'] )
					&& $event[ $k . '_array' ][0]['VALUE'] === 'DATE';
				$event[ $k ] = $isFullDay
					? $dt->format( 'Ymd' )
					: $dt->format( 'Ymd\THis\Z' );
			}
			$events[] = $event;
		}
		return $events;
	}

	/* ----------  TESTS  ---------- */

	/** @test */
	public function test_single_event_ics_download() {
		self::factory()->event->create(
			array(
				'post_title'   => 'Test Event',
				'post_content' => 'Event Description',
				'meta_input'   => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 12:00:00',
					'event_location' => 'Test Location',
					'event_url'      => 'https://example.com',
					'event_category' => 'bg-success',
				),
			)
		);

		$this->go_to( $this->feed_url( 'decker-calendar&type=meeting' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		// $this->assertEmpty( $ics );
		$this->assertNotEmpty( $ics, 'ICS content should not be empty.' );

		$events = $this->parse_ics_with_debug( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Test Event', $events[0]['SUMMARY'] );
		$this->assertSame( 'Event Description', $events[0]['DESCRIPTION'] );

		$this->assertStringNotContainsString( 'VTIMEZONE', $ics );
		$this->assertStringContainsString( 'X-WR-TIMEZONE:UTC', $ics );

		// Use UTC for comparison.
		$this->assertSame( '20250101T100000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250101T120000Z', $events[0]['DTEND'] );
		$this->assertSame( 'Test Location', $events[0]['LOCATION'] );
		$this->assertSame( 'https://example.com', $events[0]['URL'] );
	}

	/** @test */
	public function test_multiple_events_ics_download() {
		self::factory()->event->create(
			array(
				'post_title' => 'Event 1',
				'meta_input' => array(
					'event_start' => '2025-01-01 09:00:00',
					'event_end'   => '2025-01-01 10:00:00',
				),
			)
		);
		self::factory()->event->create(
			array(
				'post_title' => 'Event 2',
				'meta_input' => array(
					'event_start' => '2025-01-02 14:00:00',
					'event_end'   => '2025-01-02 16:00:00',
				),
			)
		);

		$this->go_to( $this->feed_url( 'decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		// $this->assertEmpty( $ics );
		$this->assertNotEmpty( $ics, 'ICS content should not be empty.' );

		$events = $this->parse_ics_with_debug( $ics );

		$this->assertCount( 2, $events );
		$titles = array_column( $events, 'SUMMARY' );
		$this->assertEqualsCanonicalizing( array( 'Event 1', 'Event 2' ), $titles );
	}

	/** @test */
	public function test_all_day_event_ics_download() {

		// A two-day inclusive all-day event (Jan 1 → Jan 2) is saved through the
		// real meta pipeline, so event_end is stored as the inclusive last day.
		self::factory()->event->create(
			array(
				'post_title' => 'All Day Event',
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-01-01',
					'event_end'    => '2025-01-02',
				),
			)
		);

		$this->go_to( $this->feed_url( 'decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		// $this->assertEmpty( $ics );
		$this->assertNotEmpty( $ics, 'ICS content should not be empty.' );

		$events = $this->parse_ics_with_debug( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( '20250101T000000Z', $events[0]['DTSTART'] );
		// Per RFC 5545, DTEND;VALUE=DATE is exclusive: the inclusive last day
		// (Jan 2) must be emitted as the next day (Jan 3).
		$this->assertSame( '20250103T000000Z', $events[0]['DTEND'] );
	}

	/** @test */
	public function test_single_day_all_day_event_dtend_is_exclusive() {

		// A single-day all-day event: start and end are the same stored day.
		self::factory()->event->create(
			array(
				'post_title' => 'Single Day All Day Event',
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-03-10',
					'event_end'    => '2025-03-10',
				),
			)
		);

		$this->go_to( $this->feed_url( 'decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		$this->assertNotEmpty( $ics, 'ICS content should not be empty.' );

		$events = $this->parse_ics_with_debug( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( '20250310T000000Z', $events[0]['DTSTART'] );
		// DTEND must be DTSTART + 1 day so the event spans exactly one day.
		$this->assertSame( '20250311T000000Z', $events[0]['DTEND'] );
	}

	/** @test */
	public function test_anonymous_request_without_token_is_forbidden() {

		self::factory()->event->create(
			array(
				'post_title'   => 'Secret Event',
				'post_content' => 'Confidential Description',
				'meta_input'   => array(
					'event_start' => '2025-01-01 10:00:00',
					'event_end'   => '2025-01-01 12:00:00',
				),
			)
		);

		// Ensure no logged-in user and no token: the feed must not leak data.
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/?decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		$this->assertEmpty( $ics, 'Anonymous request without a token must not receive feed content.' );
		$this->assertStringNotContainsString( 'Secret Event', (string) $ics );
		$this->assertStringNotContainsString( 'Confidential Description', (string) $ics );
	}
}
