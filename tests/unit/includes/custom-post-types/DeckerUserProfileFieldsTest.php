<?php
/**
 * Characterization tests for the Decker section of the user profile screen.
 *
 * The screen is assembled from several independent rows, so these tests pin
 * the fields each row contributes and the conditions under which it appears.
 *
 * @package Decker
 */

class DeckerUserProfileFieldsTest extends Decker_Test_Base {

	/**
	 * Instance under test.
	 *
	 * @var Decker_User_Extended
	 */
	private $extended;

	/**
	 * Profile owner.
	 *
	 * @var WP_User
	 */
	private $user;

	/**
	 * Board fixture used by the default board selector.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->extended = new Decker_User_Extended();

		$this->board_id = self::factory()->board->create( array( 'name' => 'Profile Board' ) );
		$this->assertNotWPError( $this->board_id );

		$this->user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		update_user_meta( $this->user->ID, 'decker_calendar_token', 'fixed-token' );
		update_user_meta( $this->user->ID, 'decker_color', '#123456' );
		update_user_meta( $this->user->ID, 'decker_default_board', $this->board_id );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Render the profile section and return its markup.
	 *
	 * @return string Rendered HTML.
	 */
	private function render() {
		ob_start();
		$this->extended->add_custom_user_profile_fields( $this->user );

		return ob_get_clean();
	}

	/**
	 * The section renders inside a single settings table.
	 */
	public function test_renders_settings_table() {
		$html = $this->render();

		$this->assertStringContainsString( 'Decker Settings', $html );
		$this->assertSame( 1, substr_count( $html, '<table class="form-table">' ) );
		$this->assertSame( 1, substr_count( $html, '</table>' ) );
	}

	/**
	 * The calendar token row exposes the token and its regeneration button.
	 */
	public function test_renders_calendar_token_row() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="decker_calendar_token"', $html );
		$this->assertStringContainsString( 'id="decker_calendar_token"', $html );
		$this->assertStringContainsString( 'value="fixed-token"', $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'id="generate-calendar-token"', $html );
	}

	/**
	 * A user without a stored token is offered a freshly generated one.
	 */
	public function test_generates_a_token_when_none_is_stored() {
		delete_user_meta( $this->user->ID, 'decker_calendar_token' );

		$html = $this->render();

		$this->assertSame(
			1,
			preg_match( '/id="decker_calendar_token"\s*\n?\s*value="([0-9a-f-]{36})"/', $html ),
			'A UUID token should be offered when none is stored.'
		);
	}

	/**
	 * Every calendar subscription target is offered.
	 */
	public function test_renders_calendar_subscription_links() {
		$html = $this->render();

		$this->assertStringContainsString( 'Google Calendar', $html );
		$this->assertStringContainsString( 'iCalendar', $html );
		$this->assertStringContainsString( 'Outlook 365', $html );
		$this->assertStringContainsString( 'Export .ics file', $html );

		// Subscription links must use the webcal scheme, not http.
		$this->assertStringContainsString( 'webcal://', $html );
	}

	/**
	 * A per-type feed is offered for each supported event type.
	 */
	public function test_renders_event_type_feeds() {
		$html = $this->render();

		$this->assertStringContainsString( 'Event type feeds:', $html );

		foreach ( array( 'event', 'absence', 'warning' ) as $type ) {
			$this->assertStringContainsString( 'type=' . $type, $html );
		}
	}

	/**
	 * The colour picker row is rendered with the stored colour.
	 */
	public function test_renders_color_row() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="decker_color"', $html );
		$this->assertStringContainsString( 'value="#123456"', $html );
		$this->assertStringContainsString( 'wpColorPicker()', $html );
	}

	/**
	 * The board selector lists the available boards and marks the stored one.
	 */
	public function test_renders_default_board_row() {
		$html = $this->render();

		$this->assertStringContainsString( 'name="decker_default_board"', $html );
		$this->assertStringContainsString( 'Profile Board', $html );
		$this->assertSame(
			1,
			preg_match( '/value="' . $this->board_id . '"\s+selected=/', $html ),
			'The stored board should be pre-selected.'
		);
	}

	/**
	 * The notification checkboxes appear only when the feature is enabled globally.
	 */
	public function test_email_notifications_row_follows_the_global_setting() {
		update_option( 'decker_settings', array( 'allow_email_notifications' => '1' ) );
		$enabled = $this->render();

		$this->assertStringContainsString( 'Email Notifications', $enabled );
		foreach ( array( 'task_assigned', 'task_completed', 'task_commented' ) as $key ) {
			$this->assertStringContainsString( 'decker_email_notifications[' . $key . ']', $enabled );
		}

		update_option( 'decker_settings', array( 'allow_email_notifications' => '0' ) );
		$disabled = $this->render();

		$this->assertStringNotContainsString( 'decker_email_notifications', $disabled );
	}

	/**
	 * Notification checkboxes default to enabled when the user has no preference.
	 */
	public function test_email_notifications_default_to_enabled() {
		update_option( 'decker_settings', array( 'allow_email_notifications' => '1' ) );

		$html = $this->render();

		$this->assertSame(
			3,
			substr_count( $html, "checked='checked'" ),
			'All three notification types should be checked by default.'
		);
	}
}
