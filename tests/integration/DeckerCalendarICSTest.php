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

		$this->go_to( home_url( '/?decker-calendar&type=meeting' ) );

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

		$this->go_to( home_url( '/?decker-calendar' ) );

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

		$this->go_to( home_url( '/?decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$ics = ob_get_clean();

		// $this->assertEmpty( $ics );
		$this->assertNotEmpty( $ics, 'ICS content should not be empty.' );

		$events = $this->parse_ics_with_debug( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( '20250101T000000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250102T000000Z', $events[0]['DTEND'] );
	}
}
