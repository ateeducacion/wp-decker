<?php
/**
 * "For today" must follow the site's timezone, not the server's.
 *
 * A WordPress host typically runs its PHP clock in UTC while the site is
 * configured for somewhere else. Decker decided which day a relation belonged
 * to with PHP's own clock, so near midnight it wrote and read the wrong day:
 * the person marks a task for today and it appears under yesterday, or
 * tomorrow. Every "today" decision now comes from one definition in the site's
 * timezone.
 *
 * The timezone used here is picked from the current UTC hour so that the site
 * date is always a different calendar day from the server date. Without that
 * the test would pass for most of the day whatever the code did.
 *
 * @package Decker
 */

class DeckerTodayTimezoneTest extends Decker_Test_Base {

	/**
	 * Original timezone option, restored afterwards.
	 *
	 * @var string
	 */
	private $original_timezone;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * The user marking tasks.
	 *
	 * @var int
	 */
	private $user_id;

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->original_timezone = (string) get_option( 'timezone_string' );

		// A fixed offset only shifts the date for part of the day, so the side
		// is chosen from the current UTC hour: ahead of UTC late in the UTC day,
		// behind it early. Either way the site's calendar day differs from the
		// server's. See timezone_for_utc_hour() for where the cut falls.
		update_option( 'timezone_string', self::timezone_for_utc_hour( (int) gmdate( 'G' ) ) );

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$board_id      = self::factory()->board->create();
		$this->task_id = self::factory()->task->create(
			array(
				'post_title' => 'Timezone task',
				'board'      => $board_id,
				'stack'      => 'to-do',
			)
		);
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->original_timezone );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The site timezone that puts the site on another calendar day.
	 *
	 * @param int $utc_hour The current UTC hour, 0-23.
	 * @return string A timezone identifier.
	 */
	private static function timezone_for_utc_hour( $utc_hour ) {
		// Kiritimati is UTC+14 and Midway UTC-11, so the cut is at 11: from
		// 11:00 UTC on, +14 rolls into tomorrow; before it, -11 falls back
		// into yesterday. A cut at 12 left the 11:00-11:59 UTC hour with both
		// clocks on the same day, and the suite failed there once a day.
		return $utc_hour >= 11 ? 'Pacific/Kiritimati' : 'Pacific/Midway';
	}

	/**
	 * The rule has to hold at every hour, not only when CI happens to run.
	 */
	public function test_the_timezone_rule_shifts_the_day_at_every_utc_hour() {
		foreach ( range( 0, 23 ) as $utc_hour ) {
			$timestamp = strtotime( sprintf( '2026-06-15 %02d:30:00 UTC', $utc_hour ) );
			$site_date = ( new DateTimeImmutable( '@' . $timestamp ) )
				->setTimezone( new DateTimeZone( self::timezone_for_utc_hour( $utc_hour ) ) )
				->format( 'Y-m-d' );
			$this->assertNotSame(
				gmdate( 'Y-m-d', $timestamp ),
				$site_date,
				"At {$utc_hour}:30 UTC the chosen timezone leaves the site on the server's calendar day."
			);
		}
	}

	/**
	 * The fixture must actually create the condition under test.
	 */
	public function test_the_fixture_puts_the_site_on_another_day() {
		$this->assertNotSame(
			gmdate( 'Y-m-d' ),
			current_time( 'Y-m-d' ),
			'The site and the server must be on different calendar days for this test to mean anything.'
		);
	}

	/**
	 * Marking for today stores the site's date.
	 */
	public function test_mark_stores_the_site_date() {
		$manager = new Decker_Task_Today_Manager();
		$manager->mark_for_today( $this->task_id, $this->user_id );

		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$dates     = array_column( (array) $relations, 'date' );

		$this->assertContains( current_time( 'Y-m-d' ), $dates );
		$this->assertNotContains( gmdate( 'Y-m-d' ), $dates );
	}

	/**
	 * A mark is then seen as "today" by the reader.
	 */
	public function test_mark_is_read_back_as_today() {
		$manager = new Decker_Task_Today_Manager();
		$manager->mark_for_today( $this->task_id, $this->user_id );

		// The regression this guards: writing with one clock and reading with
		// another makes a freshly marked task look unmarked.
		$this->assertTrue( $manager->is_marked_for_today( $this->task_id, $this->user_id ) );
	}

	/**
	 * A relation stored against the server's date is not today.
	 */
	public function test_a_relation_on_the_server_date_is_not_today() {
		update_post_meta(
			$this->task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->user_id,
					'date'    => gmdate( 'Y-m-d' ),
				),
			)
		);

		$manager = new Decker_Task_Today_Manager();
		$this->assertFalse( $manager->is_marked_for_today( $this->task_id, $this->user_id ) );
	}

	/**
	 * Unmarking removes the relation it just created.
	 */
	public function test_mark_and_unmark_are_consistent() {
		$manager = new Decker_Task_Today_Manager();

		$manager->mark_for_today( $this->task_id, $this->user_id );
		$this->assertTrue( $manager->is_marked_for_today( $this->task_id, $this->user_id ) );

		$manager->unmark_for_today( $this->task_id, $this->user_id );
		$this->assertFalse( $manager->is_marked_for_today( $this->task_id, $this->user_id ) );

		$relations = get_post_meta( $this->task_id, '_user_date_relations', true );
		$this->assertSame( array(), array_values( (array) $relations ) );
	}

	/**
	 * The agent listing filter agrees with the manager.
	 */
	public function test_the_today_listing_filter_agrees() {
		$manager = new Decker_Task_Today_Manager();
		$manager->mark_for_today( $this->task_id, $this->user_id );

		$listing = ( new Decker_Ability_Service() )->list_tasks( array( 'today' => true ) );
		$ids     = array_map( static fn( $task ) => (int) $task['id'], $listing['tasks'] );

		$this->assertContains( $this->task_id, $ids );
	}

	/**
	 * The shared definition is the site's clock.
	 */
	public function test_the_shared_definition_uses_the_site_timezone() {
		$this->assertSame(
			current_time( 'Y-m-d' ),
			Decker_Task_Date_Relations::today_string()
		);
		$this->assertSame(
			wp_timezone()->getName(),
			Decker_Task_Date_Relations::now()->getTimezone()->getName()
		);
	}
}
