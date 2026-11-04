<?php
/** Calendar credential lifecycle through the real nonce-protected AJAX action. */
class DeckerCalendarTokenAjaxTest extends WP_Ajax_UnitTestCase {
	public function test_rotation_persists_before_response_and_revocation_removes_access() {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $id );
		$_POST = array( 'user_id' => $id, 'nonce' => wp_create_nonce( 'generate_calendar_token' ) );
		try { $this->_handleAjax( 'generate_calendar_token' ); } catch ( WPAjaxDieContinueException $error ) {}
		$response = json_decode( $this->_last_response, true );
		$this->assertTrue( $response['success'] );
		$old = $response['data']['token'];
		$this->assertSame( $old, get_user_meta( $id, 'decker_calendar_token', true ) );
		$this->assertNotSame( $old, get_user_meta( $id, 'decker_calendar_read_token', true ) );
		$this->_last_response = '';
		try { $this->_handleAjax( 'generate_calendar_token' ); } catch ( WPAjaxDieContinueException $error ) {}
		$this->assertNotSame( $old, get_user_meta( $id, 'decker_calendar_token', true ) );
		$_POST['revoke'] = '1';
		$this->_last_response = '';
		try { $this->_handleAjax( 'generate_calendar_token' ); } catch ( WPAjaxDieContinueException $error ) {}
		$this->assertSame( '', get_user_meta( $id, 'decker_calendar_token', true ) );
		$this->assertSame( '', get_user_meta( $id, 'decker_calendar_read_token', true ) );
	}
}
