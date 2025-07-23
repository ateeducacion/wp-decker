<?php
/**
 * Integration tests for Decker Calendar ICS functionality.
 *
 * @package Decker
 */

use ICal\ICal;

class Decker_Calendar_ICS_Test extends Decker_Test_Base {

	/**
	 * Test ICS download for a single event.
	 */
	public function test_single_event_ics_download() {
		// Create a test event.
		$event = self::factory()->event->create(
			array(
				'post_title'   => 'Test Event',
				'post_content' => 'Event Description',
				'event_start'  => '2025-01-01 10:00:00',
				'event_end'    => '2025-01-01 12:00:00',
				'event_location' => 'Test Location',
				'event_url' => 'https://example.com',
				'event_category' => 'bg-success',
			)
		);

		// Set up the request.
		$this->go_to( home_url( '/?decker-calendar&type=meeting' ) );

		// Capture the output.
		ob_start();
		do_action( 'template_redirect' );
		$ical_content = ob_get_clean();

		// Parse the ICS content.
		$ical = new ICal();
		$ical->initString( $ical_content );

		// Verify the parsed event.
		$events = $ical->events();
		$this->assertCount( 1, $events );
		$this->assertEquals( 'Test Event', $events[0]->summary );
		$this->assertEquals( 'Event Description', $events[0]->description );
		$this->assertEquals( '20250101T100000Z', $events[0]->dtstart );
		$this->assertEquals( '20250101T120000Z', $events[0]->dtend );
		$this->assertEquals( 'Test Location', $events[0]->location );
		$this->assertEquals( 'https://example.com', $events[0]->url );
	}

	/**
	 * Test ICS download for multiple events.
	 */
	public function test_multiple_events_ics_download() {
		// Create test events.
		$event1 = self::factory()->event->create(
			array(
				'post_title'   => 'Event 1',
				'event_start'  => '2025-01-01 09:00:00',
				'event_end'    => '2025-01-01 10:00:00',
			)
		);

		$event2 = self::factory()->event->create(
			array(
				'post_title'   => 'Event 2',
				'event_start'  => '2025-01-02 14:00:00',
				'event_end'    => '2025-01-02 16:00:00',
			)
		);

		// Set up the request.
		$this->go_to( home_url( '/?decker-calendar' ) );

		// Capture the output.
		ob_start();
		do_action( 'template_redirect' );
		$ical_content = ob_get_clean();

		// Parse the ICS content.
		$ical = new ICal();
		$ical->initString( $ical_content );

		// Verify the parsed events.
		$events = $ical->events();
		$this->assertCount( 2, $events );
		$this->assertEquals( 'Event 1', $events[0]->summary );
		$this->assertEquals( 'Event 2', $events[1]->summary );
	}

	/**
	 * Test all-day event handling in ICS.
	 */
	public function test_all_day_event_ics_download() {
		// Create an all-day event.
		$event = self::factory()->event->create(
			array(
				'post_title'   => 'All Day Event',
				'event_allday' => true,
				'event_start'  => '2025-01-01',
				'event_end'    => '2025-01-02',
			)
		);

		// Set up the request.
		$this->go_to( home_url( '/?decker-calendar' ) );

		// Capture the output.
		ob_start();
		do_action( 'template_redirect' );
		$ical_content = ob_get_clean();

		// Parse the ICS content.
		$ical = new ICal();
		$ical->initString( $ical_content );

		// Verify the all-day event formatting.
		$events = $ical->events();
		$this->assertCount( 1, $events );
		$this->assertEquals( '20250101', $events[0]->dtstart );
		$this->assertEquals( '20250102', $events[0]->dtend );
	}
}
