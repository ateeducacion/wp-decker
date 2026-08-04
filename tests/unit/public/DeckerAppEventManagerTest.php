<?php
/**
 * Characterization tests for the event manager and calendar page templates.
 *
 * The event manager renders one table row per decker_event, translating the
 * stored category class into a human label and formatting the dates according
 * to the all-day flag. The calendar page renders the filter chrome the
 * FullCalendar bootstrap script hooks into.
 *
 * @package Decker
 */

class DeckerAppEventManagerTest extends Decker_Test_Base {

	/**
	 * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
		wp_set_current_user( 1 );
	}

	/**
	 * Render the event manager page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_event_manager_page(): string {
		set_query_var( 'decker_page', 'event-manager' );

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-event-manager.php';
		return ob_get_clean();
	}

	/**
	 * Render the calendar page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_calendar_page(): string {
		set_query_var( 'decker_page', 'calendar' );

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-calendar.php';
		return ob_get_clean();
	}

	/**
	 * Create a decker_event with the given meta.
	 *
	 * @param string $title Event title.
	 * @param array  $meta  Event meta input.
	 * @return int The event ID.
	 */
	private function create_event( string $title, array $meta ): int {
		return self::factory()->event->create(
			array(
				'post_title' => $title,
				'meta_input' => $meta,
			)
		);
	}

	/**
	 * A timed event renders its formatted start and end plus the action buttons.
	 */
	public function test_timed_event_renders_formatted_dates_and_actions() {
		$event_id = $this->create_event(
			'Sprint Review',
			array(
				'event_allday'   => false,
				'event_start'    => '2030-04-05 10:30:00',
				'event_end'      => '2030-04-05 11:30:00',
				'event_category' => 'bg-success',
			)
		);

		$output = $this->render_event_manager_page();

		$this->assertStringContainsString( 'Sprint Review', $output );
		$this->assertStringContainsString( '5 Apr 2030 10:30', $output );
		$this->assertStringContainsString( '5 Apr 2030 11:30', $output );

		// bg-success maps to the Meeting label.
		$this->assertStringContainsString( 'Meeting', $output );
		$this->assertStringContainsString( 'data-event-id="' . $event_id . '"', $output );
		$this->assertStringContainsString( 'window.deleteEvent(' . $event_id . ',', $output );
	}

	/**
	 * An all-day event drops the time from the rendered dates and ticks the box.
	 */
	public function test_all_day_event_renders_dates_without_time() {
		$this->create_event(
			'Public Holiday',
			array(
				'event_allday'   => true,
				'event_start'    => '2030-05-01 00:00:00',
				'event_end'      => '2030-05-01 23:59:00',
				'event_category' => 'bg-info',
			)
		);

		$output = $this->render_event_manager_page();

		$this->assertStringContainsString( '1 May 2030', $output );
		$this->assertStringNotContainsString( '1 May 2030 00:00', $output );
		$this->assertStringContainsString( 'disabled readonly ', $output );

		// bg-info maps to the Absence label.
		$this->assertStringContainsString( 'Absence', $output );
	}

	/**
	 * An event with no category falls back to the Uncategorized label.
	 */
	public function test_event_without_category_renders_uncategorized() {
		$this->create_event(
			'Loose Event',
			array(
				'event_allday' => false,
				'event_start'  => '2030-06-01 09:00:00',
				'event_end'    => '2030-06-01 10:00:00',
			)
		);

		$output = $this->render_event_manager_page();

		$this->assertStringContainsString( 'Loose Event', $output );
		$this->assertStringContainsString( 'Uncategorized', $output );
	}

	/**
	 * An unknown category class falls back to the class name with bg- stripped.
	 */
	public function test_unknown_category_falls_back_to_the_stripped_class_name() {
		$this->create_event(
			'Odd Event',
			array(
				'event_allday'   => false,
				'event_start'    => '2030-07-01 09:00:00',
				'event_end'      => '2030-07-01 10:00:00',
				'event_category' => 'bg-purple',
			)
		);

		$output = $this->render_event_manager_page();

		$this->assertStringContainsString( '<span class="badge bg-purple">', $output );
		$this->assertStringContainsString( 'purple', $output );
	}

	/**
	 * The calendar page renders the type filter and hosts both modals.
	 */
	public function test_calendar_page_renders_filters_and_modals() {
		self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Cara Calendar',
				'user_login'   => 'caracalendar',
			)
		);

		$output = $this->render_calendar_page();

		$this->assertStringContainsString( 'id="calendar"', $output );
		$this->assertStringContainsString( '<option value="absence">', $output );
		$this->assertStringContainsString( '<option value="task">', $output );
		$this->assertStringContainsString( 'Cara Calendar', $output );

		// Both the event and the task modal are available on this page.
		$this->assertStringContainsString( 'id="event-modal"', $output );
		$this->assertStringContainsString( 'id="task-modal"', $output );
	}
}
