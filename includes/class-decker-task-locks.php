<?php
/**
 * File class-decker-task-locks
 *
 * WordPress-compatible edit locking for Decker task/card editing.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-decker-task-lock-state.php';

/**
 * Class Decker_Task_Locks
 *
 * Front-end counterpart of WordPress core's advisory post locking
 * (`wp_set_post_lock()` / `wp_check_post_lock()` are admin-only), plus the one
 * thing core does not provide: detect-and-reject on save.
 *
 * Concurrency model (optimistic, advisory — see docs/adr/001):
 * - every save of an existing task presents the generation token its form
 *   rendered with; a mismatch (a takeover happened, even if since released) is
 *   rejected with 409;
 * - the token is minted on ownership change / explicit takeover and rotated on
 *   each successful save, so any other stale form (including a second tab of
 *   the same user) is rejected on its next save;
 * - lock-state writes are plain meta writes, exactly like core's
 *   `wp_set_post_lock()`. Sub-second races between lock operations are accepted
 *   and self-heal on the next heartbeat; task revisions remain the recovery net.
 *
 * Persistence (and the core `_edit_lock` mirror for wp-admin interop) lives in
 * {@see Decker_Task_Lock_State}.
 */
class Decker_Task_Locks {

	/**
	 * The post type this manager is allowed to lock.
	 *
	 * @var string
	 */
	const POST_TYPE = 'decker_task';

	/**
	 * Authoritative lock state meta key.
	 *
	 * @var string
	 */
	const STATE_META = Decker_Task_Lock_State::STATE_META;

	/**
	 * The lock-state persistence backend.
	 *
	 * @var Decker_Task_Lock_State
	 */
	private $state;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->state = new Decker_Task_Lock_State();
	}

	/**
	 * Get the stale-lock window in seconds.
	 *
	 * Mirrors WordPress core, which filters the default 150 second window via
	 * `wp_check_post_lock_window`.
	 *
	 * @return int The number of seconds a lock stays active without a refresh.
	 */
	public function get_lock_window(): int {
		return (int) apply_filters( 'wp_check_post_lock_window', 150 );
	}

	/**
	 * Determine whether edit locking is active.
	 *
	 * Locking is the fallback for concurrent editing. When Decker's real-time
	 * collaborative editing is enabled it already resolves concurrency through
	 * the shared CRDT, so locking stands down to avoid blocking a second editor
	 * from joining the collaborative session.
	 *
	 * @return bool True when locking should be enforced.
	 */
	public function is_enabled(): bool {
		$options = wp_parse_args(
			(array) get_option( 'decker_settings', array() ),
			array( 'collaborative_editing' => '' )
		);

		// Locking stands down only when collaborative editing is explicitly on.
		return '1' !== $options['collaborative_editing'];
	}

	/**
	 * Build the normalized lock information for a task from a viewer's point of view.
	 *
	 * @param int $post_id         The task post ID.
	 * @param int $current_user_id The user the information is computed for.
	 * @return array The normalized lock metadata.
	 */
	public function get_lock_info( int $post_id, int $current_user_id ): array {
		$base = array(
			'post_id'               => $post_id,
			'valid'                 => false,
			'locked'                => false,
			'owned_by_current_user' => false,
			'owner'                 => null,
			'is_stale'              => false,
			'can_take_over'         => false,
			'lock_window'           => $this->get_lock_window(),
			'generation'            => '',
			'message'               => '',
		);

		// Unsupported post, or locking disabled: always report as unlocked.
		if ( ! $this->is_supported_task( $post_id ) ) {
			return $base;
		}

		$base['valid']      = true;
		$base['generation'] = $this->state->generation( $post_id );

		if ( ! $this->is_enabled() ) {
			return $base;
		}

		$lock = $this->state->active_lock( $post_id );
		if ( ! $lock ) {
			return $base;
		}

		$base['owner'] = $this->build_owner_info( $lock['user'] );

		// A lock older than the window is stale and does not block a new editor.
		if ( $lock['time'] <= ( time() - $this->get_lock_window() ) ) {
			$base['is_stale'] = true;
			return $base;
		}

		// Active lock owned by the viewer themselves.
		if ( $lock['user'] === $current_user_id ) {
			$base['owned_by_current_user'] = true;
			return $base;
		}

		// Active lock owned by another user.
		$base['locked']        = true;
		$base['can_take_over'] = user_can( $current_user_id, 'edit_post', $post_id );

		$owner_name = $base['owner'] ? $base['owner']['display_name'] : '';
		/* translators: %s is the display name of the user currently editing the card. */
		$base['message'] = sprintf( __( 'This card is currently locked by %s.', 'decker' ), $owner_name );

		return $base;
	}

	/**
	 * Acquire or refresh the lock for the given user.
	 *
	 * The lock is only written when the task is unlocked, already owned by the
	 * user, or held by a stale lock. An active lock owned by another user is
	 * never stolen; the caller receives the current lock info instead so it can
	 * offer an explicit takeover. A same-owner (re)acquire keeps the session
	 * token so the open editor form stays valid.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user acquiring the lock.
	 * @return array|WP_Error The lock info on success, or WP_Error on failure.
	 */
	public function acquire_lock( int $post_id, int $user_id ) {
		// Locking is disabled (collaborative editing takes over): report unlocked.
		if ( ! $this->is_enabled() ) {
			return $this->get_lock_info( $post_id, $user_id );
		}

		$guard = $this->guard_lock_operation( $post_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Do not steal an active lock owned by another user.
		$info = $this->get_lock_info( $post_id, $user_id );
		if ( $info['locked'] ) {
			return $info;
		}

		$this->write_lock( $post_id, $user_id, false );

		return $this->get_lock_info( $post_id, $user_id );
	}

	/**
	 * Take over an active lock owned by another user.
	 *
	 * Always mints a new generation token so the previous editor's form session
	 * is invalidated even after this owner later releases the lock.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user taking over the lock.
	 * @return array|WP_Error The lock info on success, or WP_Error on failure.
	 */
	public function take_over_lock( int $post_id, int $user_id ) {
		// Locking is disabled (collaborative editing takes over): report unlocked.
		if ( ! $this->is_enabled() ) {
			return $this->get_lock_info( $post_id, $user_id );
		}

		$guard = $this->guard_lock_operation( $post_id, $user_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$this->write_lock( $post_id, $user_id, true );

		return $this->get_lock_info( $post_id, $user_id );
	}

	/**
	 * Release the lock only when it is held by the given session.
	 *
	 * Another user's lock is never removed by this method, and neither is a newer
	 * session owned by the same user: the caller must present the session
	 * generation it holds, and both owner and token must match. The token is kept
	 * (time set to 0) so stale editor sessions remain invalid.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user releasing the lock.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lock was released, false otherwise.
	 */
	public function release_lock( int $post_id, int $user_id, string $session_generation = '' ): bool {
		if ( ! $this->is_enabled() || ! $this->is_supported_task( $post_id ) ) {
			return false;
		}

		$state = $this->state->read( $post_id );
		if ( ! $this->state->owned_by( $state, $user_id, $session_generation ) ) {
			return false;
		}

		$this->state->save( $post_id, $user_id, (string) $state['token'], 0 );

		return true;
	}

	/**
	 * Refresh a still-held lock from the heartbeat without ever acquiring.
	 *
	 * The heartbeat must never grant a new generation to an already-open form: a
	 * previous editor whose card was taken over (and possibly released) must stay
	 * stale. This only extends the timestamp when the caller still owns the exact
	 * session (owner + generation); otherwise it reports the current lock info and
	 * flags `stale_session` so the client can block the superseded editor.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user whose session is refreshing.
	 * @param string $session_generation Generation token embedded in the editor form.
	 * @return array The current lock info, possibly flagged with `stale_session`.
	 */
	public function refresh_lock( int $post_id, int $user_id, string $session_generation = '' ) {
		if ( ! $this->is_enabled() || ! $this->is_supported_task( $post_id ) ) {
			return $this->get_lock_info( $post_id, $user_id );
		}

		// Extend our own session only; never acquire a free/released lock here.
		$state = $this->state->read( $post_id );
		if ( $this->state->owned_by( $state, $user_id, $session_generation ) ) {
			$this->state->save( $post_id, $user_id, (string) $state['token'], time() );
		}

		$info = $this->get_lock_info( $post_id, $user_id );

		// Session validity is decided by the generation token, not the owner id:
		// a submitted token that no longer matches the authoritative generation is
		// stale even when ownership has cycled back to the same user (a second
		// session of that user took over).
		if ( '' !== $session_generation
			&& $session_generation !== (string) $info['generation'] ) {
			$info['stale_session'] = true;
		}

		return $info;
	}

	/**
	 * Invalidate every open editing session for a task.
	 *
	 * Mutations that reach a task outside the editor form (moving a card between
	 * stacks, assigning a user, changing the due date, merging) leave any open
	 * form holding data that is now stale. Those forms resubmit the whole task,
	 * so without this the next save silently reverts the change.
	 *
	 * Rotating the token here turns that save into a 409 the client already
	 * handles: the editor is told the card changed instead of overwriting it.
	 * The lock owner and timestamp are preserved, so whoever is editing keeps
	 * the lock and only has to reload.
	 *
	 * No-ops when the task was never locked (there is no session to invalidate).
	 *
	 * @param int $post_id The task post ID.
	 * @return string|false The new generation, or false when nothing was rotated.
	 */
	public function invalidate_sessions( int $post_id ) {
		$state = $this->state->read( $post_id );
		if ( ! is_array( $state ) || '' === (string) $state['token'] ) {
			return false;
		}

		$token = wp_generate_uuid4();
		$this->state->save( $post_id, (int) $state['user'], $token, (int) $state['time'] );

		return $token;
	}

	/**
	 * Rotate the session generation after a successful save.
	 *
	 * A successful save rotates the token so any other form still holding the old
	 * token (for example a second tab of the same user) can no longer save; the
	 * new generation is returned so the saving form can adopt it. Returns false
	 * when there is no owned session to rotate.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user finishing the save.
	 * @param string $session_generation The generation the save validated with.
	 * @return string|false The rotated generation, or false when nothing rotated.
	 */
	public function rotate_generation( int $post_id, int $user_id, string $session_generation ) {
		$state = $this->state->read( $post_id );
		if ( ! $this->state->owned_by( $state, $user_id, $session_generation ) ) {
			return false;
		}

		$token = wp_generate_uuid4();
		$this->state->save( $post_id, $user_id, $token, (int) $state['time'] );

		return $token;
	}

	/**
	 * Ensure the given user is allowed to save the task (detect-and-reject).
	 *
	 * A save is rejected when:
	 * - another user currently owns an active lock,
	 * - the editor session submitted a lock generation that no longer matches
	 *   the server generation (the card was taken over, even if the lock has
	 *   since been released), or
	 * - `$require_generation` is set (the public save endpoints while locking is
	 *   enabled), no session generation was submitted, and the task already
	 *   carries a server generation (so a missing token cannot fail open after a
	 *   takeover and release).
	 *
	 * Admin/meta and internal save paths leave `$require_generation` false; a
	 * never-locked task (no server generation) is always saveable without a token.
	 *
	 * @param int         $post_id            The task post ID.
	 * @param int         $user_id            The user attempting to save.
	 * @param string|null $session_generation Generation token embedded in the editor form, or null.
	 * @param bool        $require_generation Reject when no session generation is provided.
	 * @return true|WP_Error True when the save may proceed, WP_Error otherwise.
	 */
	public function assert_user_can_save( int $post_id, int $user_id, $session_generation = null, bool $require_generation = false ) {
		// When collaborative editing is enabled, locking does not gate saves.
		if ( ! $this->is_enabled() ) {
			return true;
		}

		if ( ! $this->is_supported_task( $post_id ) ) {
			return new WP_Error(
				'decker_invalid_task',
				__( 'Invalid task.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		$info = $this->get_lock_info( $post_id, $user_id );

		if ( $info['locked'] ) {
			return $this->locked_error( $info['message'], $info['owner'], $info['generation'] );
		}

		// A null or empty submitted generation both mean "no token" ('' cast).
		$has_generation = '' !== (string) $session_generation;

		if ( $has_generation ) {
			if ( (string) $session_generation !== $info['generation'] ) {
				return $this->locked_error( $info['message'], $info['owner'], $info['generation'] );
			}

			return true;
		}

		// No session generation was submitted. A public save is rejected only when
		// the task actually carries a server generation (it was locked or taken
		// over at some point) — the fail-open case after a takeover and release. A
		// never-locked task has no newer change to protect and stays saveable, so
		// admin/meta and internal save paths keep working.
		if ( $require_generation && '' !== $info['generation'] ) {
			return $this->locked_error( '', $info['owner'], $info['generation'] );
		}

		return true;
	}

	/**
	 * Write the lock state for an owner (plain write, like wp_set_post_lock()).
	 *
	 * @param int  $post_id The task post ID.
	 * @param int  $user_id The lock owner.
	 * @param bool $bump    Force a new generation token (explicit takeover).
	 * @return void
	 */
	private function write_lock( int $post_id, int $user_id, bool $bump ) {
		$state = $this->state->read( $post_id );
		$token = $this->state->next_token( $state, $user_id, $bump );

		$this->state->save( $post_id, $user_id, $token, time() );
	}

	/**
	 * Build the standard `decker_task_locked` (409) error for a rejected save.
	 *
	 * @param string $message    Owner-specific message, or empty for the default takeover text.
	 * @param mixed  $owner      The lock owner descriptor, or null.
	 * @param string $generation The server generation to echo back.
	 * @return WP_Error The lock-conflict error.
	 */
	private function locked_error( string $message, $owner, string $generation ): WP_Error {
		if ( '' === $message ) {
			$message = __( 'You can no longer save this card because another user has taken over editing. Please reload the card to see the latest changes.', 'decker' );
		}

		return new WP_Error(
			'decker_task_locked',
			$message,
			array(
				'status'     => 409,
				'owner'      => $owner,
				'generation' => $generation,
			)
		);
	}

	/**
	 * Validate a lock operation before it mutates state.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user performing the operation.
	 * @return true|WP_Error True when the operation may proceed, WP_Error otherwise.
	 */
	private function guard_lock_operation( int $post_id, int $user_id ) {
		if ( ! $this->is_supported_task( $post_id ) ) {
			return new WP_Error(
				'decker_invalid_task',
				__( 'Invalid task.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $user_id || ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'decker_task_cannot_edit',
				__( 'You are not allowed to edit this card.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Determine whether a post ID refers to an existing supported task.
	 *
	 * @param int $post_id The post ID to validate.
	 * @return bool True when the post exists and is a decker_task.
	 */
	private function is_supported_task( int $post_id ): bool {
		// The explicit guard matters: get_post_type( 0 ) falls back to the global post.
		if ( $post_id <= 0 ) {
			return false;
		}

		return self::POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * Build the public owner descriptor for a lock, or null when unavailable.
	 *
	 * Only the id and display name are exposed; private data such as the email
	 * address is never included.
	 *
	 * @param int $owner_id The lock owner user ID.
	 * @return array{id:int,display_name:string}|null The owner descriptor.
	 */
	private function build_owner_info( int $owner_id ) {
		if ( ! $owner_id ) {
			return null;
		}

		$owner = get_userdata( $owner_id );
		if ( ! $owner ) {
			return null;
		}

		return array(
			'id'           => $owner_id,
			'display_name' => $owner->display_name,
		);
	}
}
