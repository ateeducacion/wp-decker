<?php
/**
 * Integration tests for server-side lock enforcement on task saves.
 *
 * These tests exercise Decker_Tasks::handle_save_decker_task() with real
 * WordPress post meta to prove that a stale editing session cannot overwrite
 * newer changes once another user owns the active lock.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskLockSaveProtectionTest
 */
class DeckerTaskLockSaveProtectionTest extends Decker_Test_Base {

	/**
	 * First editor user.
	 *
	 * @var int
	 */
	private $user_a;

	/**
	 * Second editor user.
	 *
	 * @var int
	 */
	private $user_b;

	/**
	 * Board fixture.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Lock manager.
	 *
	 * @var Decker_Task_Locks
	 */
	private $locks;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->user_a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->user_b = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->user_a );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'post_title' => 'Original title',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);

		$this->locks = new Decker_Task_Locks();

		// Make handle_save_decker_task() return its payload instead of dying.
		add_filter( 'decker_save_task_send_response', '__return_false' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'decker_save_task_send_response', '__return_false' );
		$_POST = array();
		wp_delete_user( $this->user_a );
		wp_delete_user( $this->user_b );
		parent::tear_down();
	}

	/**
	 * Build a valid save payload for the task under test.
	 *
	 * @param string   $title      The title to save.
	 * @param string|null $generation Optional lock generation token from the editor session.
	 * @return array The $_POST payload.
	 */
	private function save_payload( string $title, $generation = null ): array {
		$payload = array(
			'task_id' => $this->task_id,
			'title'   => $title,
			'stack'   => 'to-do',
			'board'   => $this->board_id,
		);

		if ( null !== $generation ) {
			$payload['lock_generation'] = $generation;
		}

		return $payload;
	}

	/**
	 * A second user cannot save while another user owns the active lock.
	 */
	public function test_second_user_save_is_rejected_when_locked() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		wp_set_current_user( $this->user_b );
		$_POST = $this->save_payload( 'Updated by user B attempt' );

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'decker_task_locked', $resp['code'] );
		$this->assertSame( 'Original title', get_post( $this->task_id )->post_title );
	}

	/**
	 * The active lock owner is allowed to save when they submit their session
	 * generation (as the rendered editor form always does).
	 */
	public function test_lock_owner_can_save() {
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		wp_set_current_user( $this->user_a );
		$_POST = $this->save_payload( 'Owner update', $info['generation'] );

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'Owner update', get_post( $this->task_id )->post_title );
	}

	/**
	 * A public save of an existing task must carry a session generation while
	 * locking is enabled: a missing token cannot be validated against a takeover
	 * and must not be allowed to fail open (even for the current owner).
	 */
	public function test_existing_task_save_requires_generation() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		wp_set_current_user( $this->user_a );
		$_POST = $this->save_payload( 'Save without a token' );

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'decker_task_locked', $resp['code'] );
		$this->assertSame( 'Original title', get_post( $this->task_id )->post_title );
	}

	/**
	 * The fail-open hole: after a takeover and release, a stale client that omits
	 * lock_generation entirely must still be rejected, not allowed to overwrite.
	 */
	public function test_save_after_release_without_generation_is_rejected() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );

		wp_set_current_user( $this->user_b );
		$_POST  = $this->save_payload( 'Updated by user B', $info_b['generation'] );
		$resp_b = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $resp_b['success'] );

		// New owner leaves (modal close / pagehide): active lock released. The save
		// rotated the token, so release uses the generation from the save response.
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $resp_b['generation'] ) );

		// A malformed/old client omits the token; it must not fail open.
		wp_set_current_user( $this->user_a );
		$_POST  = $this->save_payload( 'Stale overwrite by A' );
		$resp_a = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertFalse( $resp_a['success'] );
		$this->assertSame( 'decker_task_locked', $resp_a['code'] );
		$this->assertSame( 'Updated by user B', get_post( $this->task_id )->post_title );
	}

	/**
	 * A heartbeat must never re-authorize an already-open stale form after a
	 * takeover and release: the previous editor stays stale and its original
	 * save is rejected (regression test for the heartbeat re-acquire hole).
	 */
	public function test_heartbeat_does_not_reauthorize_stale_editor_after_release() {
		$tasks = new Decker_Tasks();

		// A opens the card and acquires the lock.
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		// B takes over, saves, and releases (modal close / pagehide).
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		wp_set_current_user( $this->user_b );
		$_POST  = $this->save_payload( 'Updated by user B', $info_b['generation'] );
		$resp_b = $tasks->handle_save_decker_task();
		$this->assertTrue( $resp_b['success'] );
		$b_generation = $resp_b['generation'];
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $b_generation ) );

		// A's heartbeat, carrying A's now-stale generation, must not re-acquire.
		wp_set_current_user( $this->user_a );
		$payload = array(
			'decker_task_lock' => array(
				'post_id'    => $this->task_id,
				'generation' => $info_a['generation'],
			),
		);
		$resp = $tasks->refresh_task_lock_heartbeat( array(), $payload );
		$this->assertFalse( $resp['decker_task_lock']['owned_by_current_user'] );
		$this->assertNotEmpty( $resp['decker_task_lock']['stale_session'] );

		// The server generation is B's post-save one; A never received a fresh one.
		$this->assertSame( $b_generation, $this->locks->get_lock_info( $this->task_id, $this->user_a )['generation'] );

		// A's original save is still rejected after the heartbeat.
		wp_set_current_user( $this->user_a );
		$_POST  = $this->save_payload( 'Updated by user A', $info_a['generation'] );
		$resp_a = $tasks->handle_save_decker_task();
		$this->assertFalse( $resp_a['success'] );
		$this->assertSame( 'decker_task_locked', $resp_a['code'] );
		$this->assertSame( 'Updated by user B', get_post( $this->task_id )->post_title );
	}

	/**
	 * After a takeover, the previous owner's stale save is rejected and the
	 * new owner's value is preserved (the core save-conflict scenario).
	 */
	public function test_stale_save_after_takeover_is_rejected() {
		// User A opens the card and acquires the lock.
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		// User B explicitly takes over.
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );

		// User B saves their change.
		wp_set_current_user( $this->user_b );
		$_POST  = $this->save_payload( 'Updated by user B', $info_b['generation'] );
		$resp_b = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $resp_b['success'] );

		// User A attempts to save a stale change with the original session token.
		wp_set_current_user( $this->user_a );
		$_POST  = $this->save_payload( 'Updated by user A', $info_a['generation'] );
		$resp_a = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertFalse( $resp_a['success'] );
		$this->assertSame( 'decker_task_locked', $resp_a['code'] );

		// The final saved title must be user B's value.
		$this->assertSame( 'Updated by user B', get_post( $this->task_id )->post_title );
	}

	/**
	 * After takeover + save + lock release (modal close / pagehide), the previous
	 * owner's form generation is still rejected so they cannot overwrite B.
	 */
	public function test_stale_save_rejected_after_takeover_even_when_lock_released() {
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );

		wp_set_current_user( $this->user_b );
		$_POST  = $this->save_payload( 'Updated by user B', $info_b['generation'] );
		$resp_b = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $resp_b['success'] );

		// Modal hide / pagehide releases the active lock but keeps generation. The
		// save rotated the token, so release uses the response generation.
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $resp_b['generation'] ) );
		$this->assertEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );

		wp_set_current_user( $this->user_a );
		$_POST  = $this->save_payload( 'Updated by user A', $info_a['generation'] );
		$resp_a = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertFalse( $resp_a['success'] );
		$this->assertSame( 'decker_task_locked', $resp_a['code'] );
		$this->assertSame( 'Updated by user B', get_post( $this->task_id )->post_title );
	}

	/**
	 * The heartbeat refreshes the owner's lock and, after a takeover, reports
	 * the loss to the previous editor so the UI can block them automatically.
	 */
	public function test_heartbeat_reports_takeover_to_previous_owner() {
		$tasks = new Decker_Tasks();
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$payload = array( 'decker_task_lock' => array( 'post_id' => $this->task_id ) );

		// While user A still owns the lock, the heartbeat confirms ownership.
		wp_set_current_user( $this->user_a );
		$resp = $tasks->refresh_task_lock_heartbeat( array(), $payload );
		$this->assertTrue( $resp['decker_task_lock']['owned_by_current_user'] );

		// User B takes over.
		$this->locks->take_over_lock( $this->task_id, $this->user_b );

		// User A's next heartbeat reports the lock is now held by user B.
		wp_set_current_user( $this->user_a );
		$resp = $tasks->refresh_task_lock_heartbeat( array(), $payload );
		$this->assertTrue( $resp['decker_task_lock']['locked'] );
		$this->assertFalse( $resp['decker_task_lock']['owned_by_current_user'] );
		$this->assertSame( $this->user_b, $resp['decker_task_lock']['owner']['id'] );
	}

	/**
	 * Saving a brand-new task (id 0) is never blocked by locking.
	 */
	public function test_new_task_creation_is_not_blocked() {
		wp_set_current_user( $this->user_b );
		$_POST = array(
			'task_id' => 0,
			'title'   => 'Brand new task',
			'stack'   => 'to-do',
			'board'   => $this->board_id,
		);

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertTrue( $resp['success'] );
	}

	/**
	 * A generic REST update must respect the edit lock: while another user owns
	 * the active lock, /wp/v2/tasks/{id} is rejected with 409 and the task is
	 * unchanged (the generic REST path bypassed save_decker_task's guard).
	 */
	public function test_rest_update_is_blocked_while_another_user_holds_the_lock() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$original = get_post( $this->task_id )->post_title;

		wp_set_current_user( $this->user_b );
		do_action( 'init' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $this->task_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'title' => 'REST overwrite by user B' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'decker_task_locked', $response->get_data()['code'] );
		$this->assertSame( $original, get_post( $this->task_id )->post_title );
	}

	/**
	 * After a takeover and release, a token-less generic REST update must be
	 * rejected with 409 (the task now carries a newer authoritative generation),
	 * not silently accepted.
	 */
	public function test_rest_update_without_generation_is_rejected_after_release() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $info_b['generation'] ) );

		$original = get_post( $this->task_id )->post_title;
		wp_set_current_user( $this->user_a );
		do_action( 'init' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $this->task_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'title' => 'Token-less REST overwrite' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'decker_task_locked', $response->get_data()['code'] );
		$this->assertSame( $original, get_post( $this->task_id )->post_title );
	}

	/**
	 * The lock owner's REST update with a valid token succeeds; the token keeps
	 * working for successive REST updates (only Decker saves rotate it).
	 */
	public function test_rest_update_with_valid_generation_succeeds() {
		$generation = $this->locks->acquire_lock( $this->task_id, $this->user_a )['generation'];
		wp_set_current_user( $this->user_a );
		do_action( 'init' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $this->task_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'title'           => 'REST update by owner',
				'lock_generation' => $generation,
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertLessThan( 300, $response->get_status() );
		$this->assertSame( 'REST update by owner', get_post( $this->task_id )->post_title );
	}

	/**
	 * Two forms of the same user (two tabs) share the generation at open. After
	 * the first tab saves, the generation rotates, so the second tab's later stale
	 * save is rejected instead of silently overwriting the first.
	 */
	public function test_second_same_user_tab_stale_save_is_rejected() {
		// Both tabs render and receive the same generation.
		$tab1 = $this->locks->acquire_lock( $this->task_id, $this->user_a )['generation'];
		$tab2 = $this->locks->acquire_lock( $this->task_id, $this->user_a )['generation'];
		$this->assertSame( $tab1, $tab2 );

		// Tab 1 saves: the generation rotates and only tab 1 learns the new one.
		wp_set_current_user( $this->user_a );
		$_POST = $this->save_payload( 'Saved by tab 1', $tab1 );
		$resp1 = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $resp1['success'] );
		$this->assertNotSame( $tab1, $resp1['generation'] );

		// Tab 2 saves stale content with the old shared token: rejected.
		$_POST = $this->save_payload( 'Stale save by tab 2', $tab2 );
		$resp2 = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertFalse( $resp2['success'] );
		$this->assertSame( 'decker_task_locked', $resp2['code'] );
		$this->assertSame( 'Saved by tab 1', get_post( $this->task_id )->post_title );
	}

	/**
	 * A validation failure does not rotate the generation, so the corrected retry
	 * from the same form (same token) can still succeed.
	 */
	public function test_failed_ajax_save_does_not_rotate_generation() {
		$generation = $this->locks->acquire_lock( $this->task_id, $this->user_a )['generation'];
		wp_set_current_user( $this->user_a );

		$_POST = $this->save_payload( '', $generation );
		$failed = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertFalse( $failed['success'] );
		$this->assertSame( $generation, $this->locks->get_lock_info( $this->task_id, $this->user_a )['generation'] );

		$_POST = $this->save_payload( 'Corrected AJAX save', $generation );
		$corrected = ( new Decker_Tasks() )->handle_save_decker_task();
		$this->assertTrue( $corrected['success'] );
		$this->assertSame( 'Corrected AJAX save', get_post( $this->task_id )->post_title );
	}

}
