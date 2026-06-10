<?php
/**
 * Characterization tests for Decker_Events::render_event_details_meta_box().
 *
 * These pin the exact rendered markup (including the pre-existing
 * `step=&quot;60s&quot;` quirk) so the markup-splitting refactor stays
 * byte-identical.
 *
 * @package Decker
 */
class DeckerEventsMetaBoxTest extends Decker_Test_Base {

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
	 * Render the meta box for a given post and return the markup.
	 *
	 * @param int $post_id The post ID.
	 * @return string The rendered HTML.
	 */
	private function render( $post_id ) {
		ob_start();
		( new Decker_Events() )->render_event_details_meta_box( get_post( $post_id ) );
		return ob_get_clean();
	}

	/**
	 * Timed event markup: datetime-local inputs, the step quirk and the script.
	 */
	public function test_render_meta_box_timed_event_markup() {
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => false,
					'event_start'  => '2025-01-01 10:00:00',
					'event_end'    => '2025-01-01 11:00:00',
				),
			)
		);

		$html = $this->render( $this->event_id );

		$this->assertStringContainsString( 'name="decker_event_meta_box_nonce"', $html );
		$this->assertStringContainsString( 'type="datetime-local"', $html );
		$this->assertStringContainsString( 'value="2025-01-01T10:00:00"', $html );
		$this->assertStringContainsString( 'value="2025-01-01T11:00:00"', $html );
		$this->assertStringContainsString( 'step=&quot;60s&quot;', $html );
		$this->assertStringContainsString( 'toggleDateType', $html );
	}

	/**
	 * All-day event markup: date inputs, checked checkbox and no step attr.
	 */
	public function test_render_meta_box_allday_event_markup() {
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_allday' => true,
					'event_start'  => '2025-02-01',
					'event_end'    => '2025-02-02',
				),
			)
		);

		$html = $this->render( $this->event_id );

		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'value="2025-02-01"', $html );
		$this->assertStringContainsString( "checked='checked'", $html );
		$this->assertStringNotContainsString( 'step=&quot;', $html );
	}

	/**
	 * Category select and text-field values are reflected in the markup.
	 */
	public function test_render_meta_box_category_and_text_values() {
		$this->event_id = self::factory()->event->create(
			array(
				'meta_input' => array(
					'event_category' => 'bg-info',
					'event_location' => 'Room A',
					'event_url'      => 'https://example.com',
				),
			)
		);

		$html = $this->render( $this->event_id );

		$this->assertStringContainsString( "selected='selected'", $html );
		$this->assertMatchesRegularExpression(
			'/value="bg-info"\s+selected=\'selected\'/',
			$html
		);
		$this->assertStringContainsString( 'value="Room A"', $html );
		$this->assertStringContainsString( 'value="https://example.com"', $html );
	}

	/**
	 * A bare post with no meta renders cleanly (no PHP notices, empty values).
	 */
	public function test_render_meta_box_post_without_meta_renders_cleanly() {
		$this->event_id = self::factory()->post->create(
			array(
				'post_type' => 'decker_event',
			)
		);

		$html = $this->render( $this->event_id );

		$this->assertStringContainsString( 'id="event_start"', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'type="datetime-local"', $html );
	}
}
