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
		$this->assertNotEmpty( $info['generation'] );
		$this->assertIsString( $info['generation'] );

		$meta = get_post_meta( $this->task_id, '_edit_lock', true );
		$this->assertMatchesRegularExpression( '/^\d+:' . $this->user_a . '$/', $meta );
	}

	/**
	 * The same user can refresh or reacquire their own lock.
	 */
	public function test_same_user_can_refresh_own_lock() {
		$first = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info  = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertFalse( $info['locked'] );
		// Same-owner refresh must not invalidate the open editor session.
		$this->assertSame( $first['generation'], $info['generation'] );
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
	 * Takeover issues a new generation token so a released lock still rejects
	 * the previous editor's session token.
	 */
	public function test_takeover_bumps_generation_and_invalidates_stale_session() {
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertNotEmpty( $info_a['generation'] );

		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		$this->assertNotEmpty( $info_b['generation'] );
		$this->assertNotSame( $info_a['generation'], $info_b['generation'] );

		// Simulate the new owner closing the modal (lock released, token kept).
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $info_b['generation'] ) );
		$this->assertEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );
		$this->assertSame( $info_b['generation'], $this->locks->get_generation( $this->task_id ) );

		// Without a generation token, an unlocked card is free (legacy behaviour).
		$this->assertTrue( $this->locks->assert_user_can_save( $this->task_id, $this->user_a ) );

		// With the original form generation, the stale session is rejected.
		$result = $this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a['generation'] );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_locked', $result->get_error_code() );

		// The new owner re-acquiring the lock they just released is still the same
		// editing session: their generation token is preserved (only an ownership
		// change or an explicit takeover mints a new one) so their open form keeps
		// saving.
		$reacquired = $this->locks->acquire_lock( $this->task_id, $this->user_b );
		$this->assertSame( $info_b['generation'], $reacquired['generation'] );
		$this->assertTrue(
			$this->locks->assert_user_can_save(
				$this->task_id,
				$this->user_b,
				$reacquired['generation']
			)
		);
	}

	/**
	 * A sole editor whose lock lapsed into staleness must keep their generation
	 * token when the lock is re-acquired (for example the heartbeat resumes after
	 * the tab regained focus). Bumping it here would reject the editor's own open
	 * form as a phantom takeover.
	 */
	public function test_same_owner_reacquire_after_stale_keeps_generation() {
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertNotEmpty( $info_a['generation'] );

		// Force the authoritative state stale while A still owns it.
		$window = $this->locks->get_lock_window();
		update_post_meta(
			$this->task_id,
			Decker_Task_Lock_Store::STATE_META,
			wp_json_encode(
				array(
					'user'  => $this->user_a,
					'token' => $info_a['generation'],
					'time'  => time() - $window - 60,
				)
			)
		);

		$reacquired = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertTrue( $reacquired['owned_by_current_user'] );
		$this->assertSame( $info_a['generation'], $reacquired['generation'] );

		// The original form token still saves: no takeover happened.
		$this->assertTrue(
			$this->locks->assert_user_can_save(
				$this->task_id,
				$this->user_a,
				$info_a['generation']
			)
		);
	}

	/**
	 * Successive ownership changes must not reuse the same generation token.
	 */
	public function test_ownership_changes_issue_unique_generation_tokens() {
		$first  = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$second = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		$third  = $this->locks->take_over_lock( $this->task_id, $this->user_a );

		$tokens = array( $first['generation'], $second['generation'], $third['generation'] );
		$this->assertCount( 3, array_unique( $tokens ) );
	}

	/**
	 * Owner and generation always come from the same atomic state, even when a
	 * concurrent takeover wins between read and CAS (injected via action).
	 *
	 * Simulates the race that used to leave generation=TC with _edit_lock=B:
	 * while B is taking over, C fully commits first. B's CAS must either fail
	 * and retry against C, or succeed only as a consistent B/token pair — never
	 * leave C's token with B's owner.
	 */
	public function test_concurrent_takeover_cannot_decouple_owner_from_token() {
		$user_c = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->locks->acquire_lock( $this->task_id, $this->user_a );

		$injected = false;
		$locks    = $this->locks;

		add_action(
			'decker_task_lock_before_cas',
			static function ( $post_id, $current, $user_id ) use ( &$injected, $locks, $user_c ) {
				if ( $injected || (int) $user_id !== (int) $GLOBALS['decker_test_user_b'] ) {
					return;
				}
				$injected = true;
				// C fully wins a takeover before B's CAS runs.
				$locks->take_over_lock( (int) $post_id, (int) $user_c );
			},
			10,
			3
		);

		$GLOBALS['decker_test_user_b'] = $this->user_b;
		$info_b                        = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		unset( $GLOBALS['decker_test_user_b'] );
		remove_all_actions( 'decker_task_lock_before_cas' );

		$this->assertTrue( $injected, 'The CAS barrier must have injected C\'s takeover.' );

		// Authoritative generation always matches the owner reported for that generation.
		$generation = $this->locks->get_generation( $this->task_id );
		$raw_state  = get_post_meta( $this->task_id, Decker_Task_Lock_Store::STATE_META, true );
		$state      = json_decode( (string) $raw_state, true );

		$this->assertIsArray( $state );
		$this->assertSame( $generation, $state['token'] );
		$this->assertGreaterThan( 0, (int) $state['user'] );
		$this->assertNotEmpty( $state['token'] );

		// Whoever finally owns the lock, their info generation matches the meta token.
		$owner_id = (int) $state['user'];
		$info     = $this->locks->get_lock_info( $this->task_id, $owner_id );
		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertSame( $generation, $info['generation'] );

		// Mirror must not disagree with the authoritative owner.
		$edit_lock = get_post_meta( $this->task_id, '_edit_lock', true );
		$this->assertMatchesRegularExpression( '/^\d+:' . $owner_id . '$/', (string) $edit_lock );

		// If B lost the race, their returned token must not be the live generation
		// after a later release by the real owner — unless B actually won.
		if ( (int) $owner_id === (int) $this->user_b ) {
			$this->assertSame( $info_b['generation'], $generation );
		} else {
			// C owns. B's session token must not match the live generation after C releases.
			if ( ! empty( $info_b['generation'] ) && $info_b['generation'] !== $generation ) {
				$this->assertTrue( $this->locks->release_lock( $this->task_id, $owner_id, $generation ) );
				$result = $this->locks->assert_user_can_save(
					$this->task_id,
					$this->user_b,
					$info_b['generation']
				);
				$this->assertWPError( $result );
				$this->assertSame( 'decker_task_locked', $result->get_error_code() );
			}
		}

		wp_delete_user( $user_c );
	}

	/**
	 * A token that is not paired with its owner in the atomic state cannot save
	 * after release (guards against the historical desync bug).
	 */
	public function test_foreign_token_rejected_after_owner_releases() {
		$user_c = self::factory()->user->create( array( 'role' => 'editor' ) );

		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		$info_c = $this->locks->take_over_lock( $this->task_id, $user_c );

		$this->assertNotSame( $info_a['generation'], $info_b['generation'] );
		$this->assertNotSame( $info_b['generation'], $info_c['generation'] );
		$this->assertSame( $info_c['generation'], $this->locks->get_generation( $this->task_id ) );

		// C releases; only C's token remains authoritative.
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $user_c, $info_c['generation'] ) );
		$this->assertSame( $info_c['generation'], $this->locks->get_generation( $this->task_id ) );

		// B lost the takeover chain: their token must not save after release.
		$result_b = $this->locks->assert_user_can_save(
			$this->task_id,
			$this->user_b,
			$info_b['generation']
		);
		$this->assertWPError( $result_b );
		$this->assertSame( 'decker_task_locked', $result_b->get_error_code() );

		// Divergent mirror (_edit_lock points at B while state token is C's) must
		// not resurrect B's session: generation still comes from atomic state.
		update_post_meta( $this->task_id, '_edit_lock', time() . ':' . $this->user_b );
		$result_b2 = $this->locks->assert_user_can_save(
			$this->task_id,
			$this->user_b,
			$info_b['generation']
		);
		$this->assertWPError( $result_b2 );

		wp_delete_user( $user_c );
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
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		// Wrong user cannot release, and neither can the owner with a wrong token.
		$this->assertFalse( $this->locks->release_lock( $this->task_id, $this->user_b, $info['generation'] ) );
		$this->assertFalse( $this->locks->release_lock( $this->task_id, $this->user_a, 'not-the-token' ) );
		$this->assertNotEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );

		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_a, $info['generation'] ) );
		$this->assertEmpty( get_post_meta( $this->task_id, '_edit_lock', true ) );
	}

	/**
	 * Release must match the token, not just the owner: a stale session must not
	 * release a newer session owned by the same user (which would leave that
	 * newer editor without an active lock).
	 */
	public function test_release_requires_matching_generation_not_just_owner() {
		$info_a1 = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->locks->take_over_lock( $this->task_id, $this->user_b );
		$info_a2 = $this->locks->take_over_lock( $this->task_id, $this->user_a );

		// A1 (the stale session) releases with its old token: it owns nothing now.
		$this->assertFalse(
			$this->locks->release_lock( $this->task_id, $this->user_a, $info_a1['generation'] )
		);

		// A2's session is still active and can still save.
		$info = $this->locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertTrue( $info['owned_by_current_user'] );
		$this->assertSame( $info_a2['generation'], $info['generation'] );
		$this->assertTrue(
			$this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a2['generation'] )
		);
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

	/**
	 * A heartbeat refresh extends the sole owner's lock without ever bumping the
	 * generation, so their open form keeps saving.
	 */
	public function test_refresh_lock_keeps_sole_owner_session() {
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		$refresh = $this->locks->refresh_lock( $this->task_id, $this->user_a, $info_a['generation'] );

		$this->assertTrue( $refresh['owned_by_current_user'] );
		$this->assertSame( $info_a['generation'], $refresh['generation'] );
		$this->assertArrayNotHasKey( 'stale_session', $refresh );
		$this->assertTrue(
			$this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a['generation'] )
		);
	}

	/**
	 * The heartbeat must never re-acquire a released lock for a previous editor:
	 * after a takeover and release, the stale editor stays stale (regression for
	 * the heartbeat re-authorization hole).
	 */
	public function test_refresh_lock_does_not_reauthorize_after_takeover_and_release() {
		$info_a = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$info_b = $this->locks->take_over_lock( $this->task_id, $this->user_b );
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_b, $info_b['generation'] ) );

		// A's heartbeat with the pre-takeover token must not grant a new one.
		$refresh = $this->locks->refresh_lock( $this->task_id, $this->user_a, $info_a['generation'] );

		$this->assertFalse( $refresh['owned_by_current_user'] );
		$this->assertNotEmpty( $refresh['stale_session'] );
		$this->assertSame( $info_b['generation'], $this->locks->get_generation( $this->task_id ) );

		// The stale session is still rejected on save.
		$result = $this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a['generation'] );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_locked', $result->get_error_code() );
	}

	/**
	 * Session validity is decided by the token, not the owner id: when ownership
	 * cycles back to the same user (a second session of that user takes over), the
	 * original session's stale token is still flagged and must not adopt the new
	 * one (regression for the same-user re-authorization hole).
	 */
	public function test_refresh_lock_flags_stale_session_when_ownership_returns_to_same_user() {
		// Session A1.
		$info_a1 = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		// User B takes over, then a different session of user A takes over again.
		$this->locks->take_over_lock( $this->task_id, $this->user_b );
		$info_a2 = $this->locks->take_over_lock( $this->task_id, $this->user_a );
		$this->assertNotSame( $info_a1['generation'], $info_a2['generation'] );

		// A1 heartbeats with its now-stale token. The owner is user A again, but
		// the token differs, so the session must be reported stale.
		$refresh = $this->locks->refresh_lock( $this->task_id, $this->user_a, $info_a1['generation'] );
		$this->assertNotEmpty( $refresh['stale_session'] );
		// The reported (authoritative) generation is A2's; the client must block
		// instead of adopting it, and A1's save stays rejected.
		$this->assertSame( $info_a2['generation'], $refresh['generation'] );

		$result = $this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a1['generation'] );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_locked', $result->get_error_code() );

		// A2 (the live session) is not stale and can still save.
		$this->assertTrue(
			$this->locks->assert_user_can_save( $this->task_id, $this->user_a, $info_a2['generation'] )
		);
	}

	/**
	 * Releasing a lock must not delete a newer native `_edit_lock` written by
	 * another user (for example a wp-admin editor) after our state CAS.
	 */
	public function test_release_preserves_a_foreign_native_lock() {
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		// A concurrent wp-admin editor writes a newer native lock for user B.
		update_post_meta( $this->task_id, '_edit_lock', time() . ':' . $this->user_b );

		// User A releases its Decker lock; B's native lock must survive.
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_a, $info['generation'] ) );

		$native = get_post_meta( $this->task_id, '_edit_lock', true );
		$this->assertStringEndsWith( ':' . $this->user_b, (string) $native );
	}

	/**
	 * When the Decker state is released (inactive), a native `_edit_lock` set
	 * afterwards (for example from the wp-admin editor) is still respected.
	 */
	public function test_native_lock_detected_after_released_decker_state() {
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertTrue( $this->locks->release_lock( $this->task_id, $this->user_a, $info['generation'] ) );

		// The Decker state row still exists but is released; a native lock appears.
		update_post_meta( $this->task_id, '_edit_lock', time() . ':' . $this->user_b );

		$info = $this->locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertTrue( $info['locked'] );
		$this->assertFalse( $info['owned_by_current_user'] );
		$this->assertSame( $this->user_b, $info['owner']['id'] );
	}

	/**
	 * Seeding an inactive state on creation lets the first acquire use the atomic
	 * update path; the seed never reads as a lock and is idempotent.
	 */
	public function test_initialize_seeds_inactive_state() {
		// Simulate a legacy task that has no state row yet.
		delete_post_meta( $this->task_id, Decker_Task_Lock_Store::STATE_META );
		$this->assertSame( '', get_post_meta( $this->task_id, Decker_Task_Lock_Store::STATE_META, true ) );

		$this->locks->initialize_lock_state( $this->task_id );

		$state = json_decode( (string) get_post_meta( $this->task_id, Decker_Task_Lock_Store::STATE_META, true ), true );
		$this->assertIsArray( $state );
		$this->assertSame( 0, (int) $state['user'] );
		$this->assertSame( 0, (int) $state['time'] );

		// The seed is inactive: not a lock and no generation.
		$info = $this->locks->get_lock_info( $this->task_id, $this->user_a );
		$this->assertFalse( $info['locked'] );
		$this->assertSame( '', $info['generation'] );

		// First acquire still works and mints a generation.
		$acquired = $this->locks->acquire_lock( $this->task_id, $this->user_a );
		$this->assertTrue( $acquired['owned_by_current_user'] );
		$this->assertNotEmpty( $acquired['generation'] );

		// Re-seeding is a no-op: it must not clobber the active lock.
		$this->locks->initialize_lock_state( $this->task_id );
		$this->assertTrue(
			$this->locks->get_lock_info( $this->task_id, $this->user_a )['owned_by_current_user']
		);
	}
}
