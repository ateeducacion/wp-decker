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

	/**
	 * Parse ICS and return a plain array of events with *string* values.
	 *
	 * @param string $ics
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_ics( string $ics ): array {
		$parser = new IcalParser();
		$parser->parseString( $ics );

		$events = array();
		foreach ( $parser->getEvents() as $event ) {
			// Detect if it is a full-day event
			$isFullDay = isset( $event['DTSTART_array'][0]['VALUE'] )
			&& $event['DTSTART_array'][0]['VALUE'] === 'DATE';

			// Normalise DTSTART / DTEND
			foreach ( array( 'DTSTART', 'DTEND' ) as $key ) {
				if ( ! isset( $event[ $key ] ) ) {
					continue;
				}

				/** @var \DateTime $dt */
				$dt            = $event[ $key ];
				$event[ $key ] = $isFullDay
				? $dt->format( 'Ymd' )
				: $dt->format( 'Ymd\THis\Z' );
			}

			$events[] = $event;
		}
		return $events;
	}

	/*
	 -------------------------------------------------------------------------
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
		$event_id = self::factory()->event->create(
			array(
				'post_title'   => 'Single Event',
				'post_content' => 'Event body',
				'meta_input'   => array(
					'event_start'    => '2025-07-26 10:00:00',
					'event_end'      => '2025-07-26 11:00:00',
					'event_location' => 'Room 101',
					'event_url'      => 'https://example.com',
					'event_category' => 'bg-success',
					'event_allday'   => false,
				),
			)
		);

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertCount( 1, $events );
		$this->assertSame( 'Single Event', $events[0]['SUMMARY'] );
		$this->assertSame( 'Event body', $events[0]['DESCRIPTION'] );
		$this->assertSame( '20250726T100000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250726T110000Z', $events[0]['DTEND'] );
		$this->assertSame( 'Room 101', $events[0]['LOCATION'] );
		$this->assertSame( 'https://example.com', $events[0]['URL'] );
	}

	/** @test */
	public function calendar_with_two_events_should_list_both() {
		self::factory()->event->create(
			array(
				'post_title' => 'First',
				'meta_input' => array(
					'event_start' => '2025-07-26 09:00:00',
					'event_end'   => '2025-07-26 09:30:00',
				),
			)
		);
		self::factory()->event->create(
			array(
				'post_title' => 'Second',
				'meta_input' => array(
					'event_start' => '2025-07-27 09:00:00',
					'event_end'   => '2025-07-27 09:30:00',
				),
			)
		);

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertCount( 2, $events );
		$titles = array_column( $events, 'SUMMARY' );
		$this->assertEqualsCanonicalizing( array( 'First', 'Second' ), $titles );
	}

	/** @test */
	public function all_day_event_should_use_date_only_format() {
		self::factory()->event->create(
			array(
				'post_title' => 'All day',
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-07-26',
					'event_end'    => '2025-07-27',
				),
			)
		);

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$this->assertSame( '20250726T000000Z', $events[0]['DTSTART'] );
		$this->assertSame( '20250727T000000Z', $events[0]['DTEND'] );
	}

	/** @test */
	public function events_can_be_filtered_by_category() {
		// “event”  -> bg-success
		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_category' => 'bg-success',
					'event_start'    => '2025-07-26 08:00:00',
					'event_end'      => '2025-07-26 09:00:00',
				),
			)
		);
		// “warning” -> bg-warning
		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_category' => 'bg-warning',
					'event_start'    => '2025-07-26 10:00:00',
					'event_end'      => '2025-07-26 11:00:00',
				),
			)
		);

		$ics_event   = ( new Decker_Calendar() )->generate_ical_string( 'event' );
		$ics_warning = ( new Decker_Calendar() )->generate_ical_string( 'warning' );

		$this->assertCount( 1, $this->parse_ics( $ics_event ) );
		$this->assertCount( 1, $this->parse_ics( $ics_warning ) );
	}

	/** @test */
	public function tasks_are_included_when_no_type_filter_is_given() {
		// Create one event
		self::factory()->event->create(
			array(
				'post_title' => 'Event',
				'meta_input' => array(
					'event_start' => '2025-07-26 08:00:00',
					'event_end'   => '2025-07-26 09:00:00',
				),
			)
		);

		// Create an editor user using WordPress factory (neede to create the board (innternally by the task))
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		// Create one task (due today)
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Task',
				'stack'      => 'in-progress',
				'duedate'    => '2025-09-26',
			)
		);
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Task',
				'stack'      => 'in-progress',
				'duedate'    => '2025-09-26',
			)
		);
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Task',
				'stack'      => 'in-progress',
				'duedate'    => '2025-09-26',
			)
		);

		$this->assertIsInt( $task_id, 'The task should be created successfully.' );
		$this->assertNotWPError( $task_id, 'The task should be created successfully.' );
		$this->assertGreaterThan( 0, $task_id, 'The task should be created successfully.' );

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		$titles = array_column( $events, 'SUMMARY' );
		$this->assertContains( 'Event', $titles );
		$this->assertContains( 'Task', $titles );
	}

	/** @test */
	public function tasks_are_excluded_when_filtering_by_event_type() {
		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_category' => 'bg-success',
					'event_start'    => '2025-07-26 08:00:00',
					'event_end'      => '2025-07-26 09:00:00',
				),
			)
		);
		self::factory()->task->create(
			array(
				'post_title' => 'Should not appear',
				'meta_input' => array( 'duedate' => '2025-07-26' ),
			)
		);

		$ics    = ( new Decker_Calendar() )->generate_ical_string( 'event' );
		$events = $this->parse_ics( $ics );

		$titles = array_column( $events, 'SUMMARY' );
		$this->assertNotContains( 'Should not appear', $titles );
	}

	/** @test */
	public function event_with_assigned_users_should_include_prefix_and_attendees() {

		$user_id1 = self::factory()->user->create(
			array(
				'display_name' => 'Alice',
				'user_email'   => 'alice@example.com',
				'role'         => 'author', // Must be at least author.
			)
		);
		$user_id2 = self::factory()->user->create(
			array(
				'display_name' => 'Bob',
				'user_email'   => 'bob@example.com',
				'role'         => 'author', // Must be at least author.
			)
		);

		self::factory()->event->create(
			array(
				'post_title' => 'Evento con gente',
				'meta_input' => array(
					'event_start'          => '2025-07-26 09:00:00',
					'event_end'            => '2025-07-26 10:00:00',
					'event_category'       => 'bg-success',
					'event_assigned_users' => array( $user_id1, $user_id2 ),
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string( 'event' );

		// error_log( "[DECKER EVENTS] " . print_r( $ics, true ) );

		$events = $this->parse_ics( $ics );

		// error_log( "[ICS EVENTS] " . print_r( $events, true ) );

		$this->assertCount( 1, $events );
		$this->assertStringStartsWith( 'Alice, Bob » ', $events[0]['SUMMARY'] );
		$this->assertArrayHasKey( 'ATTENDEE', $events[0] );

		$this->assertCount( 1, $events );
		$event = $events[0];

		$this->assertArrayHasKey( 'ATTENDEES', $event );
		$this->assertCount( 2, $event['ATTENDEES'] );
		$this->assertSame( 'mailto:alice@example.com', $event['ATTENDEES'][0]['VALUE'] );
		$this->assertSame( 'mailto:bob@example.com', $event['ATTENDEES'][1]['VALUE'] );
	}


	/** @test */
	public function task_with_assigned_users_should_not_include_prefix_but_include_attendees() {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Charlie',
				'role'         => 'editor', // Must be at least author.
				'user_email'   => 'charlie@example.com',
			)
		);
		wp_set_current_user( $user_id );

		$task_id = self::factory()->task->create(
			array(
				'post_title'     => 'Important task',
				'stack'          => 'in-progress',
				'duedate'        => '2025-07-27',
				'assigned_users' => array( $user_id ),
			)
		);

		$ics    = ( new Decker_Calendar() )->generate_ical_string();
		$events = $this->parse_ics( $ics );

		// Filtramos por la tarea.
		$task = array_filter(
			$events,
			function ( $e ) {
				return isset( $e['SUMMARY'] ) && str_contains( $e['SUMMARY'], 'Important task' );
			}
		);
		$task = array_values( $task )[0];

		$this->assertSame( 'Important task', $task['SUMMARY'] );
		$this->assertArrayHasKey( 'ATTENDEE', $task );

		$attendees = (array) $task['ATTENDEE'];
		$this->assertContains( 'mailto:' . get_userdata( $user_id )->user_email, $attendees );
	}
}
