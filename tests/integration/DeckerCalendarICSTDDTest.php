<?php
/**
 * TDD tests for Decker iCal generation
 *
 * @package Decker
 *
 * Requires:  composer require --dev om/icalparser
 */

use om\IcalParser;

class Decker_Calendar_ICS_TDD_Test extends Decker_Test_Base {

	// /**
	//  * Parser helper – throws useful messages when the ICS is malformed.
	//  *
	//  * @param string $ics
	//  * @return array Parsed events
	//  */
	// private function parse_ics( $ics ) {
	// 	$parser = new IcalParser();
	// 	try {
	// 		$parser->parseString( $ics );
	// 	} catch ( Exception $e ) {
	// 		$this->fail( "Malformed iCal: " . $e->getMessage() . "\n---\n$ics\n---" );
	// 	}
	// 	return $parser->getEvents();
	// }
/**
 * Parse ICS and return a plain array of events with *string* values.
 *
 * @param string $ics
 * @return array<int,array<string,mixed>>
 */
private function parse_ics( string $ics ): array {
	$parser = new IcalParser();
	$parser->parseString( $ics );

	$events = [];
	foreach ( $parser->getEvents() as $event ) {
		// Detect if it is a full-day event
		$isFullDay = isset( $event['DTSTART_array'][0]['VALUE'] )
			&& $event['DTSTART_array'][0]['VALUE'] === 'DATE';

		// Normalise DTSTART / DTEND
		foreach ( [ 'DTSTART', 'DTEND' ] as $key ) {
			if ( ! isset( $event[ $key ] ) ) {
				continue;
			}

			/** @var \DateTime $dt */
			$dt         = $event[ $key ];
			$event[ $key ] = $isFullDay
				? $dt->format( 'Ymd' )
				: $dt->format( 'Ymd\THis\Z' );
		}

		$events[] = $event;
	}
	return $events;
}

	/* -------------------------------------------------------------------------
	 *  ACTUAL TESTS
	 * ----------------------------------------------------------------------- */

	/** @test */
	public function empty_calendar_should_return_valid_ical_without_events() {
		$ics = ( new Decker_Calendar() )->generate_ical_string();
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringContainsString( 'END:VCALENDAR', $ics );

		$events = $this->parse_ics( $ics );
		$this->assertEmpty( $events );
	}

	/** @test */
	public function calendar_with_one_event_should_contain_the_event() {
		$event_id = self::factory()->event->create( [
			'post_title'   => 'Single Event',
			'post_content' => 'Event body',
			'meta_input'   => [
				'event_start'  => '2025-07-26 10:00:00',
				'event_end'    => '2025-07-26 11:00:00',
				'event_location' => 'Room 101',
				'event_url'      => 'https://example.com',
				'event_category' => 'bg-success',
				'event_allday'   => false,
			],
		] );

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Single Event', $events[0]['SUMMARY'] );
		$this->assertSame( 'Event body',   $events[0]['DESCRIPTION'] );
		$this->assertSame( '20250726T100000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250726T110000Z', $events[0]['DTEND'] );
		$this->assertSame( 'Room 101', $events[0]['LOCATION'] );
		$this->assertSame( 'https://example.com', $events[0]['URL'] );
	}

	/** @test */
	public function calendar_with_two_events_should_list_both() {
		self::factory()->event->create( [ 'post_title' => 'First',  'meta_input' => [ 'event_start' => '2025-07-26 09:00:00', 'event_end' => '2025-07-26 09:30:00' ] ] );
		self::factory()->event->create( [ 'post_title' => 'Second', 'meta_input' => [ 'event_start' => '2025-07-27 09:00:00', 'event_end' => '2025-07-27 09:30:00' ] ] );

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertCount( 2, $events );
		$titles = array_column( $events, 'SUMMARY' );
		$this->assertEqualsCanonicalizing( [ 'First', 'Second' ], $titles );
	}

	/** @test */
	public function all_day_event_should_use_date_only_format() {
		self::factory()->event->create( [
			'post_title'   => 'All day',
			'meta_input'   => [
				'event_allday' => true,
				'event_start'  => '2025-07-26',
				'event_end'    => '2025-07-27',
			],
		] );

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertSame( '20250726T000000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250727T000000Z', $events[0]['DTEND'] );
	}

	/** @test */
	public function events_can_be_filtered_by_category() {
		// “event”  -> bg-success
		self::factory()->event->create( [ 'meta_input' => [ 'event_category' => 'bg-success', 'event_start' => '2025-07-26 08:00:00', 'event_end' => '2025-07-26 09:00:00' ] ] );
		// “warning” -> bg-warning
		self::factory()->event->create( [ 'meta_input' => [ 'event_category' => 'bg-warning', 'event_start' => '2025-07-26 10:00:00', 'event_end' => '2025-07-26 11:00:00' ] ] );

		$ics_event = ( new Decker_Calendar() )->generate_ical_string( 'event' );
		$ics_warning = ( new Decker_Calendar() )->generate_ical_string( 'warning' );

		$this->assertCount( 1, $this->parse_ics( $ics_event ) );
		$this->assertCount( 1, $this->parse_ics( $ics_warning ) );
	}

	/** @test */
	public function tasks_are_included_when_no_type_filter_is_given() {
		// Create one event
		self::factory()->event->create( [ 'post_title' => 'Event', 'meta_input' => [ 'event_start' => '2025-07-26 08:00:00', 'event_end' => '2025-07-26 09:00:00' ] ] );


		// Create an editor user using WordPress factory (neede to create the board (innternally by the task))
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// Create one task (due today)
		$task_id = self::factory()->task->create( [ 'post_title' => 'Task',  'stack' => 'in-progress', 'duedate' => '2025-09-26'] );
		$task_id = self::factory()->task->create( [ 'post_title' => 'Task',  'stack' => 'in-progress', 'duedate' => '2025-09-26'] );
		$task_id = self::factory()->task->create( [ 'post_title' => 'Task',  'stack' => 'in-progress', 'duedate' => '2025-09-26'] );

		$this->assertIsInt($task_id, 'The task should be created successfully.');
		$this->assertNotWPError( $task_id, 'The task should be created successfully.' );
		$this->assertGreaterThan(0, $task_id, 'The task should be created successfully.' );

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		// Debug: vuelca el contenido al log
		error_log( 'DEBUG: events = ' . var_export( $events, true ) );

		$titles = array_column( $events, 'SUMMARY' );
		$this->assertContains( 'Event', $titles );
		$this->assertContains( 'Task',  $titles );
	}

	/** @test */
	public function tasks_are_excluded_when_filtering_by_event_type() {
		self::factory()->event->create( [ 'meta_input' => [ 'event_category' => 'bg-success', 'event_start' => '2025-07-26 08:00:00', 'event_end' => '2025-07-26 09:00:00' ] ] );
		self::factory()->task->create( [ 'post_title' => 'Should not appear', 'meta_input' => [ 'duedate' => '2025-07-26' ] ] );

		$ics    = ( new Decker_Calendar() )->generate_ical_string( 'event' );
		$events = $this->parse_ics( $ics );

		$titles = array_column( $events, 'SUMMARY' );
		$this->assertNotContains( 'Should not appear', $titles );
	}
}