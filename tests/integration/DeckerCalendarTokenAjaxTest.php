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
	public function test_failed_persistence_never_enables_or_returns_a_usable_token() {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $id );
		$_POST = array( 'user_id' => $id, 'nonce' => wp_create_nonce( 'generate_calendar_token' ) );
		$fail = static function ( $check, $user_id, $key ) use ( $id ) {
			return $user_id === $id && 'decker_calendar_token' === $key ? false : $check;
		};
		add_filter( 'update_user_metadata', $fail, 10, 3 );
		try {
			try { $this->_handleAjax( 'generate_calendar_token' ); } catch ( WPAjaxDieContinueException $error ) {}
		} finally {
			remove_filter( 'update_user_metadata', $fail, 10 );
		}
		$response = json_decode( $this->_last_response, true );
		$this->assertFalse( $response['success'] );
		$this->assertEmpty( $response['data'] ?? array() );
		$this->assertFalse( (bool) get_user_meta( $id, 'decker_calendar_dav_enabled', true ) );
		$this->assertSame( '', get_user_meta( $id, 'decker_calendar_token', true ) );
	}

	public function test_another_users_credential_cannot_be_rotated_without_permission() {
		$target = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $target, 'decker_calendar_token', 'retained-test-credential' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array( 'user_id' => $target, 'nonce' => wp_create_nonce( 'generate_calendar_token' ) );
		try { $this->_handleAjax( 'generate_calendar_token' ); } catch ( WPAjaxDieContinueException $error ) {}
		$this->assertFalse( json_decode( $this->_last_response, true )['success'] );
		$this->assertSame( 'retained-test-credential', get_user_meta( $target, 'decker_calendar_token', true ) );
	}

}
