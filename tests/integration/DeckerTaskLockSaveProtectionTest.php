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
	 * The active lock owner is allowed to save.
	 */
	public function test_lock_owner_can_save() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		wp_set_current_user( $this->user_a );
		$_POST = $this->save_payload( 'Owner update' );

		$resp = ( new Decker_Tasks() )->handle_save_decker_task();

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'Owner update', get_post( $this->task_id )->post_title );
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

		// Modal hide / pagehide releases the active lock but keeps generation.
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b ) );
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
}
