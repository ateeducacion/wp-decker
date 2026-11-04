<?php
/** Strict calendar dates shared by web and DAV. */
class DeckerEventsExtraDateTest extends Decker_Test_Base {
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		do_action( 'rest_api_init' );
	}
	/** @dataProvider invalid_dates */
	public function test_invalid_dates_are_rejected_before_rest_changes( $start, $end, $all_day ) {
		$id = self::factory()->event->create( array( 'meta_input' => array( 'event_start' => '2026-01-01 10:00:00', 'event_end' => '2026-01-01 11:00:00' ) ) );
		$request = new WP_REST_Request( 'POST', '/wp/v2/decker_event/' . $id );
		$request->set_param( 'title', 'Must not be saved' );
		$request->set_param( 'meta', array( 'event_start' => $start, 'event_end' => $end, 'event_allday' => $all_day ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertNotSame( 'Must not be saved', get_the_title( $id ) );
		$this->assertSame( '2026-01-01 10:00:00', get_post_meta( $id, 'event_start', true ) );
	}
	public function invalid_dates() {
		return array(
			array( 'invalid', '', false ), array( '', '', false ),
			array( '2026-02-30', '2026-03-02', true ),
			array( '2026-01-01', '2026-01-01', true ),
			array( '2026-01-02', '2026-01-01', true ),
			array( '2026-01-01 10:00:00', '2026-01-01 09:00:00', false ),
		);
	}
	/** @dataProvider valid_dates */
	public function test_valid_dates_roundtrip_without_repairs( $start, $end, $all_day, $expected_end ) {
		$id = self::factory()->event->create( array( 'meta_input' => array( 'event_start' => $start, 'event_end' => $end, 'event_allday' => $all_day ) ) );
		$this->assertSame( $expected_end, get_post_meta( $id, 'event_end', true ) );
		$data = Decker_Event_Store::resource( $id );
		$data = preg_replace( '/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:20000101T000000Z', $data );
		Decker_Event_Store::put( $id, 'roundtrip.ics', $data );
		$this->assertSame( $expected_end, get_post_meta( $id, 'event_end', true ) );
		$this->assertSame( $data, Decker_Event_Store::resource( $id ) );
	}
	public function valid_dates() {
		return array(
			array( '2026-01-01', '2026-01-02', true, '2026-01-02' ),
			array( '2026-01-01', '2026-01-05', true, '2026-01-05' ),
			array( '2026-01-01', '', true, '2026-01-02' ),
			array( '2026-01-01T10:00:30Z', '', false, '2026-01-01 10:00:30' ),
			array( '2026-01-01T10:00:30Z', '2026-01-01T11:00:45Z', false, '2026-01-01 11:00:45' ),
		);
	}
}
