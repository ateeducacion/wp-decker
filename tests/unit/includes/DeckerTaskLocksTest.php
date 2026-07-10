<?php
/**
 * Unit tests for the Decker_Task_Locks manager.
 *
 * These tests specify the WordPress-compatible edit-locking behavior used to
 * prevent concurrent task/card editing. They exercise the native `_edit_lock`
 * meta convention directly, without mocking WordPress lock behavior.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskLocksTest
 */
class DeckerTaskLocksTest extends Decker_Test_Base {

	/**
	 * First editor user (initial lock owner in most scenarios).
	 *
	 * @var int
	 */
	private $user_a;

	/**
	 * Second editor user (the user that opens a locked card).
	 *
	 * @var int
	 */
	private $user_b;

	/**
	 * Board used to host the task fixtures.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixture under test.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * The lock manager under test.
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
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$this->locks = new Decker_Task_Locks();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->user_a );
		wp_delete_user( $this->user_b );
		parent::tear_down();
	}

	/**
	 * A user can acquire a lock on an unlocked task, stored in native format.
	 */
	public function test_user_can_acquire_lock_on_unlocked_task() {
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		$this->assertIsArray( $info );
		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertFalse( $info['locked'] );

		$meta = get_post_meta( $this->task_id, '_edit_lock', true );
		$this->assertMatchesRegularExpression( '/^\d+:' . $this->user_a . '$/', $meta );
	}

	/**
	 * The same user can refresh or reacquire their own lock.
	 */
	public function test_same_user_can_refresh_own_lock() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertFalse( $info['locked'] );
	}

	/**
	 * A second user detects that the task is locked by the first user.
	 */
	public function test_second_user_detects_lock() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$info = $this->locks->get_lock_info( $this->task_id, $this->user_b );

		$this->assertTrue( $info['locked'] );
		$this->assertFalse( $info['owned_by_current_user'] );
		$this->assertTrue( $info['can_take_over'] );
	}

	/**
	 * Lock metadata includes the lock owner display name and message.
	 */
	public function test_lock_info_includes_owner_display_name() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$info  = $this->locks->get_lock_info( $this->task_id, $this->user_b );
		$owner = get_userdata( $this->user_a );

		$this->assertSame( $this->user_a, $info['owner']['id'] );
		$this->assertSame( $owner->display_name, $info['owner']['display_name'] );
		$this->assertStringContainsString( $owner->display_name, $info['message'] );
	}

	/**
	 * The lock response must not leak the owner email address.
	 */
	public function test_lock_info_does_not_expose_owner_email() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$info = $this->locks->get_lock_info( $this->task_id, $this->user_b );

		$this->assertArrayNotHasKey( 'email', $info['owner'] );
		$owner = get_userdata( $this->user_a );
		$this->assertStringNotContainsString( $owner->user_email, wp_json_encode( $info ) );
	}

	/**
	 * A second user cannot save while another user owns the active lock.
	 */
	public function test_second_user_cannot_save_while_locked() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$result = $this->locks->assert_user_can_save( $this->task_id, $this->user_b );

		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_locked', $result->get_error_code() );
	}

	/**
	 * The active lock owner is allowed to save.
	 */
	public function test_lock_owner_can_save() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_a ) );
	}

	/**
	 * A second user can explicitly take over the lock.
	 */
	public function test_second_user_can_take_over() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$info = $this->locks->take_over_lock( $this->task_id, $this->user_b );

		$this->assertIsArray( $info );
		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertFalse( $info['locked'] );

		$info_a = $this->locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertTrue( $info_a['locked'] );
		$this->assertFalse( $info_a['owned_by_current_user'] );
	}

	/**
	 * After takeover, the first user cannot save stale changes.
	 */
	public function test_after_takeover_previous_owner_cannot_save() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->locks->take_over_lock( $this->task_id, $this->user_b );

		$result = $this->locks->assert_user_can_save( $this->task_id, $this->user_a );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_locked', $result->get_error_code() );

		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_b ) );
	}

	/**
	 * Expired/stale locks do not block a new user from acquiring the lock.
	 */
	public function test_stale_lock_does_not_block_new_user() {
		$window = $this->locks->get_lock_window();
		update_post_meta( $this->task_id, '_edit_lock', ( time() - $window - 60 ) . ':' . $this->user_a );

		$info = $this->locks->get_lock_info( $this->task_id, $this->user_b );
		$this->assertFalse( $info['locked'] );
		$this->assertTrue( $info['is_stale'] );

		$acquired = $this->locks->acquire_lock( $this->task_id, $this->user_b );
		$this->assertTrue( $acquired['owned_by_current_user'] );

		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_b ) );
	}

	/**
	 * Acquiring must not steal an active lock owned by another user.
	 */
	public function test_acquire_does_not_steal_active_foreign_lock() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$info = $this->locks->acquire_lock( $this->task_id, $this->user_b );
		$this->assertFalse( $info['owned_by_current_user'] );
		$this->assertTrue( $info['locked'] );

		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_a ) );
	}

	/**
	 * Invalid post IDs are rejected.
	 */
	public function test_invalid_post_id_rejected() {
		$this->assertWPError( $this->locks->acquire_lock( 999999, $this->user_a ) );
		$this->assertWPError( $this->locks->assert_user_can_save( 999999, $this->user_a ) );
	}

	/**
	 * Unsupported post types are rejected.
	 */
	public function test_unsupported_post_type_rejected() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$result = $this->locks->acquire_lock( $page_id, $this->user_a );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_invalid_task', $result->get_error_code() );
	}

	/**
	 * Users without edit permissions cannot acquire a lock.
	 */
	public function test_user_without_edit_permission_cannot_acquire() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->locks->acquire_lock( $this->task_id, $subscriber );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_cannot_edit', $result->get_error_code() );

		wp_delete_user( $subscriber );
	}

	/**
	 * Users without edit permissions cannot take over a lock.
	 */
	public function test_user_without_edit_permission_cannot_take_over() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$result = $this->locks->take_over_lock( $this->task_id, $subscriber );
		$this->assertWPError( $result );

		$info = $this->locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertTrue( $info['owned_by_current_user'] );

		wp_delete_user( $subscriber );
	}

	/**
	 * Releasing a lock only works for the lock owner and must not remove
	 * another user's active lock.
	 */
	public function test_release_lock_only_for_owner() {
		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$this->assertFalse( $this->locks->release_lock( $this->task_id, $this->user_b ) );
		$this->assertNotEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );

		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_a ) );
		$this->assertEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );
	}

	/**
	 * With no lock present at all, any editor is allowed to save.
	 */
	public function test_no_lock_allows_save() {
		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_b ) );
	}

	/**
	 * When collaborative editing is enabled, locking stands down: a second
	 * user is never blocked and can save, because the CRDT resolves concurrency.
	 */
	public function test_locking_stands_down_when_collaboration_enabled() {
		update_option( 'decker_settings', array( 'collaborative_editing' => '1' ) );

		$this->assertFalse( $this->locks->is_enabled() );

		// User A "acquires" the lock, but nothing is actually stored.
		$this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );

		// A second user still sees the card as unlocked and may save.
		$info = $this->locks->get_lock_info( $this->task_id, $this->user_b );
		$this->assertFalse( $info['locked'] );
		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_b ) );

		delete_option( 'decker_settings' );
	}
}
