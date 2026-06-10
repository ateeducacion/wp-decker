<?php
/**
 * Characterization / lock-in tests for Decker_Calendar.
 *
 * These tests pin the CURRENT observable behavior of Decker_Calendar before a
 * behavior-preserving refactor (header builder, per-VEVENT builder, event-args
 * builder extractions). They assert exact ICS byte sequences and exact
 * get_events() array shapes, plus the side-effect/exit ordering of
 * handle_ical_request().
 *
 * @package Decker
 */

use om\IcalParser;

class DeckerCalendarCharacterizationTest extends Decker_Test_Base {

	/**
	 * Per-user calendar token used to authenticate the feed requests.
	 *
	 * @var string
	 */
	private $calendar_token = '';

	/**
	 * Make sure the rewrite endpoint exists and an authenticated user is set.
	 */
	public function set_up() {
		parent::set_up();

		// Re-register CPT and endpoints (init has already fired in bootstrap).
		do_action( 'init' );

		// Flush rewrite rules once.
		global $wp_rewrite;
		$wp_rewrite->init();
		$wp_rewrite->add_endpoint( 'decker-calendar', EP_ROOT );
		$wp_rewrite->flush_rules( false );

		// Provision a user with a per-user calendar token and log them in so the
		// iCal feed (which now requires authentication) is accessible.
		$user_id              = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->calendar_token = 'char-calendar-token-' . $user_id;
		update_user_meta( $user_id, 'decker_calendar_token', $this->calendar_token );
		wp_set_current_user( $user_id );
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

	/* ----------  HEADER (build_ical_header)  ---------- */

	/** @test */
	public function test_ical_header_contains_calendar_properties_without_type() {
		$ics = ( new Decker_Calendar() )->generate_ical_string();

		$this->assertStringContainsString( "X-WR-CALNAME:Decker\r\n", $ics );
		$this->assertStringContainsString( "X-NAME:Decker\r\n", $ics );
		$this->assertStringContainsString( 'PRODID:-//Decker//NONSGML Decker//EN', $ics );
		$this->assertStringContainsString( "CALSCALE:GREGORIAN\r\n", $ics );
		$this->assertStringContainsString( "METHOD:PUBLISH\r\n", $ics );
		$this->assertStringContainsString( "X-WR-TIMEZONE:UTC\r\n", $ics );
		$this->assertStringContainsString( 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', $ics );
		$this->assertStringContainsString( 'X-PUBLISHED-TTL:PT1H', $ics );
	}

	/** @test */
	public function test_ical_header_uses_type_specific_calendar_name() {
		$ics = ( new Decker_Calendar() )->generate_ical_string( 'absence' );

		$this->assertStringContainsString( "X-WR-CALNAME:Decker - Absences\r\n", $ics );
		$this->assertStringContainsString( 'PRODID:-//Decker - Absences//NONSGML Decker//EN', $ics );
	}

	/* ----------  SORT (sort_events_by_start)  ---------- */

	/** @test */
	public function test_events_are_sorted_by_start_date_ascending() {
		// Create the later event FIRST so source order is reversed vs sorted order.
		$b_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'    => '2025-02-01 10:00:00',
					'event_end'      => '2025-02-01 11:00:00',
					'event_category' => 'bg-success',
				),
			)
		);
		$a_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 11:00:00',
					'event_category' => 'bg-success',
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string();

		$this->assertLessThan(
			strpos( $ics, 'UID:event_' . $b_id . '@decker' ),
			strpos( $ics, 'UID:event_' . $a_id . '@decker' )
		);
	}

	/* ----------  VALUE-ONLY FOLD (fold_ical_property)  ---------- */

	/** @test */
	public function test_long_description_is_folded_at_74_chars_value_only() {
		self::factory()->event->create(
			array(
				'post_content' => str_repeat( 'a', 200 ),
				'meta_input'   => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 11:00:00',
					'event_category' => 'bg-success',
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string();

		// 'DESCRIPTION:' prefix is NOT counted in the 74-char fold width.
		$expected = 'DESCRIPTION:' . str_repeat( 'a', 74 ) . "\r\n "
			. str_repeat( 'a', 74 ) . "\r\n "
			. str_repeat( 'a', 52 ) . "\r\n";
		$this->assertStringContainsString( $expected, $ics );

		// Round-trips to the full 200-char text after unfolding.
		$parser = new IcalParser();
		$parser->parseString( $ics );
		$events = $parser->getEvents()->getArrayCopy();
		$this->assertSame( str_repeat( 'a', 200 ), $events[0]['DESCRIPTION'] );
	}

	/** @test */
	public function test_long_location_and_url_are_folded_value_only() {
		$url = 'https://example.com/' . str_repeat( 'x', 100 );
		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 11:00:00',
					'event_location' => str_repeat( 'L', 100 ),
					'event_url'      => $url,
					'event_category' => 'bg-success',
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string();

		$this->assertStringContainsString(
			'LOCATION:' . str_repeat( 'L', 74 ) . "\r\n " . str_repeat( 'L', 26 ) . "\r\n",
			$ics
		);

		$escaped_url = esc_url_raw( $url );
		$url_chunks  = str_split( $escaped_url, 74 );
		$this->assertStringContainsString(
			'URL:' . $url_chunks[0] . "\r\n " . $url_chunks[1] . "\r\n",
			$ics
		);
	}

	/* ----------  WHOLE-LINE FOLD (fold_ical_line / ATTENDEE)  ---------- */

	/** @test */
	public function test_attendee_line_is_folded_including_property_name() {
		$email = str_repeat( 'a', 40 ) . '@example.com';
		$uid   = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => $email,
			)
		);

		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'          => '2025-01-01 10:00:00',
					'event_end'            => '2025-01-01 11:00:00',
					'event_category'       => 'bg-success',
					'event_assigned_users' => array( $uid ),
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string();

		$line   = 'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $email;
		$chunks = str_split( $line, 74 );
		// ATTENDEE folds the WHOLE line (property name counted) - opposite of DESCRIPTION.
		$this->assertStringContainsString( $chunks[0] . "\r\n " . $chunks[1], $ics );
	}

	/* ----------  ESCAPING (ical_escape)  ---------- */

	/** @test */
	public function test_special_characters_are_escaped_in_summary_and_description() {
		self::factory()->event->create(
			array(
				'post_title'   => 'A,B;C:D',
				'post_content' => "line1\nline2",
				'meta_input'   => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 11:00:00',
					'event_category' => 'bg-success',
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string();

		$this->assertStringContainsString( 'SUMMARY:A\\,B\\;C\\:D', $ics );
		$this->assertStringContainsString( 'DESCRIPTION:line1\\nline2', $ics );
	}

	/* ----------  ALL-DAY TRUTHINESS (build_vevent_dates / get_events)  ---------- */

	/** @test */
	public function test_allday_string_meta_renders_value_date_and_raw_json_dates() {
		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday'   => '1',
					'event_start'    => '2025-03-01',
					'event_end'      => '2025-03-02',
					'event_category' => 'bg-success',
				),
			)
		);

		$calendar = new Decker_Calendar();

		// (a) ICS uses VALUE=DATE; DTEND is exclusive (stored end + 1 day).
		$ics = $calendar->generate_ical_string();
		$this->assertStringContainsString( 'DTSTART;VALUE=DATE:20250301', $ics );
		$this->assertStringContainsString( 'DTEND;VALUE=DATE:20250303', $ics );

		// (b) get_events() passes raw stored YYYY-MM-DD through (no gmdate conversion).
		$events = $calendar->get_events();
		$this->assertSame( '2025-03-01', $events[0]['start'] );
		$this->assertSame( '2025-03-02', $events[0]['end'] );
	}

	/* ----------  get_events() EVENT SHAPE (map_event_post_to_array)  ---------- */

	/** @test */
	public function test_timed_event_json_array_shape() {
		$uid = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'shape@example.com',
			)
		);

		$id = self::factory()->event->create(
			array(
				'post_title'   => 'Shape Event',
				'post_content' => 'Shape body',
				'meta_input'   => array(
					'event_start'          => '2025-01-01 10:00:00',
					'event_end'            => '2025-01-01 11:00:00',
					'event_location'       => 'Loc',
					'event_url'            => 'https://example.com',
					'event_category'       => 'bg-success',
					'event_assigned_users' => array( $uid ),
				),
			)
		);

		$events = ( new Decker_Calendar() )->get_events();
		$e      = $events[0];

		$this->assertSame(
			array(
				'post_id',
				'id',
				'title',
				'description',
				'allDay',
				'start',
				'end',
				'location',
				'url',
				'className',
				'assigned_users',
				'type',
			),
			array_keys( $e )
		);
		$this->assertSame( 'event_' . $id, $e['id'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '2025-01-01 10:00:00' ) ), $e['start'] );
		$this->assertSame( 'bg-success', $e['className'] );
		$this->assertSame( 'event', $e['type'] );
	}

	/** @test */
	public function test_event_missing_end_date_is_skipped() {
		// Insert the event post directly so process_and_save_meta does not
		// synthesize a missing event_end (which it does for timed events).
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'decker_event',
				'post_status' => 'publish',
				'post_title'  => 'No End',
			)
		);
		update_post_meta( $post_id, 'event_start', '2025-01-01 10:00:00' );

		$this->assertSame( array(), ( new Decker_Calendar() )->get_events() );
	}

	/* ----------  get_events() TASK SHAPE (get_task_calendar_events)  ---------- */

	/** @test */
	public function test_task_json_array_shape() {
		$board_id = self::factory()->board->create( array( 'color' => '#ff0000' ) );
		self::factory()->task->create(
			array(
				'post_title' => 'Shape Task',
				'board'      => $board_id,
				'duedate'    => '2025-01-03',
			)
		);

		$events = ( new Decker_Calendar() )->get_events();
		$task   = null;
		foreach ( $events as $event ) {
			if ( 'task' === $event['type'] ) {
				$task = $event;
				break;
			}
		}

		$this->assertNotNull( $task, 'A task event should be present.' );
		$this->assertTrue( $task['allDay'] );
		$this->assertSame( $task['start'], $task['end'] );
		$this->assertSame( '#ff0000', $task['className'] );
		$this->assertSame( '#ff0000', $task['color'] );
		$this->assertSame( 'task', $task['type'] );
		$this->assertContainsOnly( 'int', $task['assigned_users'] );
	}

	/* ----------  UNKNOWN TYPE (build_event_query_args vs task predicate)  ---------- */

	/** @test */
	public function test_unknown_type_returns_events_unfiltered_but_excludes_tasks() {
		$event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'    => '2025-01-01 10:00:00',
					'event_end'      => '2025-01-01 11:00:00',
					'event_category' => 'bg-success',
				),
			)
		);
		$board_id = self::factory()->board->create();
		$task_id  = self::factory()->task->create(
			array(
				'post_title' => 'Hidden Task',
				'board'      => $board_id,
				'duedate'    => '2025-01-03',
			)
		);

		$calendar = new Decker_Calendar();
		$events   = $calendar->get_events( 'meeting' );
		$ids      = wp_list_pluck( $events, 'id' );

		$this->assertContains( 'event_' . $event_id, $ids );
		$this->assertNotContains( 'task_' . $task_id, $ids );

		// Unknown slug keeps the default calendar name.
		$this->assertStringContainsString(
			"X-WR-CALNAME:Decker\r\n",
			$calendar->generate_ical( $events, 'meeting' )
		);
	}

	/* ----------  SUMMARY PREFIX (build_summary_prefix)  ---------- */

	/** @test */
	public function test_no_user_prefix_in_mixed_feed_but_prefix_with_type_filter() {
		$uid = self::factory()->user->create(
			array(
				'display_name' => 'Jane Doe',
				'user_email'   => 'jane@example.com',
				'role'         => 'author',
			)
		);

		self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_start'          => '2025-01-01 10:00:00',
					'event_end'            => '2025-01-01 11:00:00',
					'event_category'       => 'bg-success',
					'event_assigned_users' => array( $uid ),
				),
			)
		);

		$calendar = new Decker_Calendar();

		// (a) No type filter: no prefix is added.
		$this->assertStringNotContainsString( ' » ', $calendar->generate_ical_string( '' ) );

		// (b) Type filter: display names prefix is added.
		$this->assertStringContainsString( 'SUMMARY:Jane Doe » ', $calendar->generate_ical_string( 'event' ) );
	}

	/** @test */
	public function test_prefix_separator_kept_when_no_assigned_user_has_email() {
		// Assign a non-existent user id so no display name resolves.
		self::factory()->event->create(
			array(
				'post_title' => 'Orphan Assignee',
				'meta_input' => array(
					'event_start'          => '2025-01-01 10:00:00',
					'event_end'            => '2025-01-01 11:00:00',
					'event_category'       => 'bg-success',
					'event_assigned_users' => array( 999999 ),
				),
			)
		);

		$ics = ( new Decker_Calendar() )->generate_ical_string( 'event' );

		// The ' » ' separator is emitted even when no user resolves (current quirk).
		$this->assertStringContainsString( 'SUMMARY: » ', $ics );
	}

	/* ----------  handle_ical_request() ORDERING / SIDE EFFECTS  ---------- */

	/** @test */
	public function test_handle_ical_request_is_noop_without_flag() {
		$this->go_to( home_url( '/' ) );
		unset( $_GET['decker-calendar'] );

		ob_start();
		do_action( 'template_redirect' );
		$this->assertSame( '', ob_get_clean() );
	}

	/** @test */
	public function test_handle_ical_request_outputs_ics_and_sets_all_transient() {
		delete_transient( Decker_Calendar::TRANSIENT_PREFIX . 'all' );

		$this->go_to( $this->feed_url( 'decker-calendar' ) );

		ob_start();
		do_action( 'template_redirect' );
		$out = ob_get_clean();

		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $out );
		// The guard runs BEFORE get_cached_ical(): the transient side effect fires
		// even though the cache READ is bypassed under WP_TESTS_RUNNING.
		$this->assertSame( $out, get_transient( Decker_Calendar::TRANSIENT_PREFIX . 'all' ) );
	}

	/** @test */
	public function test_handle_ical_request_with_type_param_uses_typed_transient() {
		delete_transient( Decker_Calendar::TRANSIENT_PREFIX . 'event' );

		$this->go_to( $this->feed_url( 'decker-calendar&type=event' ) );
		$_GET['type'] = 'event';

		ob_start();
		do_action( 'template_redirect' );
		$out = ob_get_clean();

		$this->assertNotFalse( get_transient( Decker_Calendar::TRANSIENT_PREFIX . 'event' ) );
		$this->assertStringContainsString( 'X-WR-CALNAME:Decker - Events', $out );

		unset( $_GET['type'] );
	}
}
