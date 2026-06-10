<?php
/**
 * Characterization tests for Decker_Events::process_and_save_meta().
 *
 * These pin the exact observable storage behavior of the meta-saving
 * pipeline so the extract-method refactor stays behavior-preserving.
 *
 * @package Decker
 */
class DeckerEventsProcessMetaTest extends Decker_Test_Base {

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

		$this->event_id = self::factory()->event->create();
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
	 * The admin checkbox POSTs 'on'; that must store the '1' string.
	 */
	public function test_allday_checkbox_on_value_saves_one() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_allday' => 'on',
				'event_start'  => '2025-01-01',
				'event_end'    => '2025-01-02',
			)
		);

		$this->assertSame( '1', get_post_meta( $this->event_id, 'event_allday', true ) );
	}

	/**
	 * Absent all-day key stores the falsy flag.
	 *
	 * The source writes the '0' string, but the registered 'event_allday'
	 * meta uses the rest_sanitize_boolean sanitize_callback, which coerces
	 * '0' to boolean false; a stored false is returned by get_post_meta()
	 * as an empty string.
	 */
	public function test_missing_allday_key_saves_zero() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-01-01 10:00:00',
			)
		);

		$this->assertSame( '', get_post_meta( $this->event_id, 'event_allday', true ) );
	}

	/**
	 * All-day with empty dates stores empty strings for both meta keys.
	 */
	public function test_allday_empty_dates_save_empty_strings() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_allday' => '1',
				'event_start'  => '',
				'event_end'    => '',
			)
		);

		$this->assertSame( '', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * All-day end before start is clamped to start.
	 */
	public function test_allday_end_before_start_clamped_to_start() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_allday' => '1',
				'event_start'  => '2025-01-05',
				'event_end'    => '2025-01-02',
			)
		);

		$this->assertSame( '2025-01-05', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '2025-01-05', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * The common datetime-local admin flow uses a 'T' separator.
	 */
	public function test_timed_datetime_local_t_separator_input() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-03-01T09:30',
				'event_end'   => '2025-03-01T10:30',
			)
		);

		$this->assertSame( '2025-03-01 09:30:00', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '2025-03-01 10:30:00', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * Timed date-only inputs get the asymmetric ' 00:00:00' / ' 01:00:00' suffixes.
	 */
	public function test_timed_date_only_inputs_set_midnight_and_one_oclock() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-05-10',
				'event_end'   => '2025-05-12',
			)
		);

		$this->assertSame( '2025-05-10 00:00:00', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '2025-05-12 01:00:00', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * Seconds are truncated to zero by the final gmdate( 'Y-m-d H:i:00' ) pass.
	 */
	public function test_timed_seconds_truncated_to_zero() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-01-01 10:00:30',
				'event_end'   => '2025-01-01 11:15:45',
			)
		);

		$this->assertSame( '2025-01-01 10:00:00', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '2025-01-01 11:15:00', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * Malformed timed start overwrites BOTH metas with the epoch pair.
	 */
	public function test_timed_malformed_start_resets_epoch_pair() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => 'not-a-date',
				'event_end'   => '2025-06-01 10:00:00',
			)
		);

		$this->assertSame( '1970-01-01 00:00:00', get_post_meta( $this->event_id, 'event_start', true ) );
		$this->assertSame( '1970-01-01 01:00:00', get_post_meta( $this->event_id, 'event_end', true ) );
	}

	/**
	 * Empty timed dates store the current UTC time (strtotime( ' UTC' ) === now).
	 */
	public function test_timed_empty_dates_store_current_utc_time() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '',
				'event_end'   => '',
			)
		);

		$stored_start = get_post_meta( $this->event_id, 'event_start', true );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/', $stored_start );
		$this->assertLessThan( MINUTE_IN_SECONDS * 5, abs( strtotime( $stored_start . ' UTC' ) - time() ) );
	}

	/**
	 * Text fields are preserved when their keys are absent (isset() guard).
	 */
	public function test_text_fields_untouched_when_keys_absent() {
		update_post_meta( $this->event_id, 'event_location', 'Seeded Location' );
		update_post_meta( $this->event_id, 'event_url', 'https://seeded.example.com' );
		update_post_meta( $this->event_id, 'event_category', 'bg-info' );

		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-01-01 10:00:00',
			)
		);

		$this->assertSame( 'Seeded Location', get_post_meta( $this->event_id, 'event_location', true ) );
		$this->assertSame( 'https://seeded.example.com', get_post_meta( $this->event_id, 'event_url', true ) );
		$this->assertSame( 'bg-info', get_post_meta( $this->event_id, 'event_category', true ) );
	}

	/**
	 * Assigned users are ALWAYS overwritten; an absent key clears them.
	 */
	public function test_assigned_users_cleared_when_key_absent() {
		update_post_meta( $this->event_id, 'event_assigned_users', array( $this->editor ) );

		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start' => '2025-01-01 10:00:00',
			)
		);

		$this->assertSame( array(), get_post_meta( $this->event_id, 'event_assigned_users', true ) );
	}

	/**
	 * Assigned users are intval'd and array_filter'd with keys preserved.
	 */
	public function test_assigned_users_filtered_with_keys_preserved() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start'          => '2025-01-01 10:00:00',
				'event_assigned_users' => array( '0', '7', 'abc' ),
			)
		);

		$this->assertSame( array( 1 => 7 ), get_post_meta( $this->event_id, 'event_assigned_users', true ) );
	}

	/**
	 * URL and location run through their per-field sanitize callbacks.
	 */
	public function test_event_url_sanitized_with_esc_url_raw() {
		( new Decker_Events() )->process_and_save_meta(
			$this->event_id,
			array(
				'event_start'    => '2025-01-01 10:00:00',
				'event_url'      => 'javascript:alert(1)',
				'event_location' => '<b>Room</b>',
			)
		);

		$this->assertSame( '', get_post_meta( $this->event_id, 'event_url', true ) );
		$this->assertSame( 'Room', get_post_meta( $this->event_id, 'event_location', true ) );
	}
}
