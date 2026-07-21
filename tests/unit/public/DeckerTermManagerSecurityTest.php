<?php
/**
 * Class DeckerTermManagerSecurityTest
 *
 * Regression tests for the CSRF/capability guards in the term manager
 * page handler (public/app-term-manager.php).
 *
 * @package Decker
 */

class DeckerTermManagerSecurityTest extends Decker_Test_Base {

	/**
	 * Clean up the forged request superglobals after each test.
	 */
	public function tear_down(): void {
		unset( $_POST['decker_term_nonce'], $_POST['term_type'], $_POST['term_id'], $_POST['action'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A forged delete request with an invalid nonce must be rejected.
	 *
	 * This guards against the broken CSRF check that verified the wrong
	 * action and inverted the result, which allowed any garbage nonce to
	 * proceed and delete a board.
	 */
	public function test_invalid_nonce_does_not_delete_board() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$term_id = self::factory()->board->create(
			array(
				'name'  => 'Sprint Security',
				'color' => '#5733ff',
			)
		);

		$this->assertInstanceOf( WP_Term::class, get_term( $term_id, 'decker_board' ), 'The board should exist before the forged request.' );

		// Forge a delete request with a bogus nonce.
		$_POST['decker_term_nonce'] = 'totally-invalid-nonce';
		$_POST['term_type']         = 'board';
		$_POST['term_id']           = (string) $term_id;
		$_POST['action']            = 'delete';

		// Expect the security guard to halt execution.
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Security check failed' );

		try {
			include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-term-manager.php';
		} finally {
			// The board must still exist because the request was rejected.
			$this->assertInstanceOf( WP_Term::class, get_term( $term_id, 'decker_board' ), 'The board must not be deleted by an invalid nonce.' );
		}
	}

	/**
	 * A user without the required capability must be rejected.
	 */
	public function test_user_without_capability_is_denied() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create the board as an administrator so the term exists.
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		$term_id = self::factory()->board->create(
			array(
				'name'  => 'Sprint Capability',
				'color' => '#33ff57',
			)
		);

		wp_set_current_user( $subscriber );

		// Forge a delete request with a valid nonce; capability must still block it.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term_type']         = 'board';
		$_POST['term_id']           = (string) $term_id;
		$_POST['action']            = 'delete';

		$this->expectException( WPDieException::class );

		try {
			include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-term-manager.php';
		} finally {
			$this->assertInstanceOf( WP_Term::class, get_term( $term_id, 'decker_board' ), 'The board must not be deleted by a user without permission.' );
		}
	}
}
