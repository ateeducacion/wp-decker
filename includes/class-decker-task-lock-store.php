<?php
/**
 * File class-decker-task-lock-store
 *
 * Lock operations (acquire, takeover, release, refresh, save lease) over the
 * authoritative Decker edit-lock state.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-decker-task-lock-state.php';
require_once __DIR__ . '/class-decker-task-native-lock.php';

/**
 * Class Decker_Task_Lock_Store
 *
 * Implements the lock state machine on top of {@see Decker_Task_Lock_State}
 * (persistence + predicates) and {@see Decker_Task_Native_Lock} (the WordPress
 * `_edit_lock` mirror). Decker_Task_Locks stays a thin policy layer above this.
 *
 * Save serialization: a save first claims a write lease ({@see begin_save()});
 * while that lease is fresh, a forced takeover refuses ({@see write()}), so a
 * takeover cannot interleave between a save's validation and its database write.
 */
class Decker_Task_Lock_Store {

	/**
	 * Authoritative lock state meta key.
	 *
	 * @var string
	 */
	const STATE_META = Decker_Task_Lock_State::STATE_META;

	/**
	 * Legacy generation-only meta key.
	 *
	 * @var string
	 */
	const GENERATION_META = Decker_Task_Lock_State::GENERATION_META;

	/**
	 * Maximum CAS attempts for a single lock mutation.
	 *
	 * @var int
	 */
	const CAS_MAX_ATTEMPTS = Decker_Task_Lock_State::CAS_MAX_ATTEMPTS;

	/**
	 * The authoritative state codec.
	 *
	 * @var Decker_Task_Lock_State
	 */
	private $state;

	/**
	 * The native WordPress `_edit_lock` mirror.
	 *
	 * @var Decker_Task_Native_Lock
	 */
	private $native;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->state  = new Decker_Task_Lock_State();
		$this->native = new Decker_Task_Native_Lock();
	}

	/**
	 * The current generation token, or empty string when never locked.
	 *
	 * @param int $post_id The task post ID.
	 * @return string The token.
	 */
	public function generation( int $post_id ): string {
		return $this->state->generation( $post_id );
	}

	/**
	 * Seed the lock state for a freshly created task.
	 *
	 * @param int $post_id The task post ID.
	 * @return void
	 */
	public function initialize( int $post_id ) {
		$this->state->initialize( $post_id );
	}

	/**
	 * Read the active lock (owner + time) from the authoritative state.
	 *
	 * Falls back to the native `_edit_lock` mirror whenever there is no active
	 * Decker lock (absent or released), so a native lock set afterwards from the
	 * WordPress admin editor is still respected.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The active lock, or null when free/released.
	 */
	public function read_active_lock( int $post_id ) {
		$state = $this->state->read( $post_id );
		if ( $this->state->is_active( $state ) ) {
			return array(
				'time' => (int) $state['time'],
				'user' => (int) $state['user'],
			);
		}

		return $this->native->read( $post_id );
	}

	/**
	 * Acquire, refresh or take over the lock as one CAS-protected state write.
	 *
	 * @param int  $post_id         The task post ID.
	 * @param int  $user_id         The lock owner.
	 * @param bool $bump_generation Force a new generation token (explicit takeover).
	 * @param int  $window          Stale-lock window in seconds (foreign-lock guard).
	 * @return bool True when the CAS write succeeded.
	 */
	public function write( int $post_id, int $user_id, bool $bump_generation, int $window ): bool {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->state->read( $post_id );

			/**
			 * Fires after the expected state is read and before the CAS write.
			 *
			 * Tests may use this to inject a concurrent CAS winner between read and write.
			 *
			 * @param int        $post_id The task post ID.
			 * @param array|null $current The expected previous state, or null.
			 * @param int        $user_id The user about to write the lock.
			 * @param bool       $bump    Whether a new generation token is forced.
			 */
			do_action( 'decker_task_lock_before_cas', $post_id, $current, $user_id, $bump_generation );

			// Re-read after the action so injected concurrent writes are observed.
			$current = $this->state->read( $post_id );

			// An in-progress commit (write lease) may only be extended by the same
			// owner without a generation bump; any takeover, different owner or
			// generation change must wait so the lease can never be dropped.
			if ( $this->blocked_by_save_lease( $current, $user_id, $bump_generation ) ) {
				return false;
			}
			// A non-forced acquire must not steal an active foreign lock.
			if ( ! $bump_generation && $this->is_active_foreign_lock( $current, $user_id, $window ) ) {
				return false;
			}

			$new = array(
				'user'  => (int) $user_id,
				'token' => $this->compute_token( $current, $user_id, $bump_generation ),
				'time'  => time(),
				// Preserve the lease across a same-owner refresh (the only write
				// allowed here while saving); every other case clears it.
				'save'  => $this->state->is_saving( $current ) ? (int) $current['save'] : 0,
			);

			if ( $this->state->write( $post_id, $current, $new ) ) {
				$this->native->set( $post_id, $user_id );
				return true;
			}

			// CAS lost to a concurrent writer: retry against fresh state.
		}

		return false;
	}

	/**
	 * Release the lock held by the given session, keeping the token (time set to 0).
	 *
	 * Both the owner **and** the session generation must match, so a stale session
	 * cannot release a newer session owned by the same user.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user releasing the lock.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lock was released.
	 */
	public function release( int $post_id, int $user_id, string $session_generation ): bool {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->state->read( $post_id );

			// Never release while a save is in progress: dropping the lease would
			// let a takeover interleave with the still-running save (a pagehide
			// release during the same session's save must not undo it).
			if ( ! $this->owns_session( $current, $user_id, $session_generation )
				|| $this->state->is_saving( $current ) ) {
				return false;
			}

			$released = array(
				'user'  => (int) $user_id,
				'token' => (string) $current['token'],
				'time'  => 0,
			);

			if ( $this->state->write( $post_id, $current, $released ) ) {
				// Clear the native mirror only if it is still ours: a wp-admin
				// editor may have written a newer `_edit_lock` we must not delete.
				$this->native->release_if_owned( $post_id, $user_id );
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a write must wait for an in-progress save (write lease).
	 *
	 * A fresh lease blocks everything except the lease owner extending it without
	 * a generation bump, so no operation can silently drop it.
	 *
	 * @param array|null $current The current state, or null.
	 * @param int        $user_id The user attempting to write.
	 * @param bool       $bump    Whether a new generation token is forced.
	 * @return bool True when the write must be refused.
	 */
	private function blocked_by_save_lease( $current, int $user_id, bool $bump ): bool {
		if ( ! $this->state->is_saving( $current ) ) {
			return false;
		}

		return $bump
			|| ! is_array( $current )
			|| (int) $current['user'] !== (int) $user_id;
	}

	/**
	 * Refresh a still-held session's timestamp without acquiring.
	 *
	 * Extends the lock only when the caller still owns the exact session; a single
	 * attempt is enough since the next heartbeat retries. Any in-progress save
	 * lease is preserved.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user whose session is refreshing.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lock timestamp was refreshed.
	 */
	public function refresh( int $post_id, int $user_id, string $session_generation ): bool {
		$current = $this->state->read( $post_id );

		if ( ! $this->owns_session( $current, $user_id, $session_generation ) ) {
			return false;
		}

		$refreshed = array(
			'user'  => (int) $user_id,
			'token' => (string) $current['token'],
			'time'  => time(),
			'save'  => (int) $current['save'],
		);

		if ( ! $this->state->write( $post_id, $current, $refreshed ) ) {
			return false;
		}

		$this->native->set( $post_id, $user_id );
		return true;
	}

	/**
	 * Claim the write lease for a save, atomically with a generation check.
	 *
	 * Succeeds only when the caller still owns the exact active session and no
	 * other save is in progress. Once held, a forced takeover refuses (see
	 * {@see write()}), so a takeover cannot interleave with the caller's commit.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user starting the save.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lease was claimed.
	 */
	public function begin_save( int $post_id, int $user_id, string $session_generation ): bool {
		$current = $this->state->read( $post_id );

		if ( ! $this->owns_session( $current, $user_id, $session_generation )
			|| $this->state->is_saving( $current ) ) {
			return false;
		}

		$new = array(
			'user'  => (int) $user_id,
			'token' => (string) $current['token'],
			'time'  => (int) $current['time'],
			'save'  => time(),
		);

		return $this->state->write( $post_id, $current, $new );
	}

	/**
	 * Release the write lease after a save completes (best-effort).
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user finishing the save.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return void
	 */
	public function end_save( int $post_id, int $user_id, string $session_generation ) {
		$current = $this->state->read( $post_id );

		if ( ! $this->owns_session( $current, $user_id, $session_generation ) ) {
			return;
		}

		$new = array(
			'user'  => (int) $user_id,
			'token' => (string) $current['token'],
			'time'  => (int) $current['time'],
			'save'  => 0,
		);

		$this->state->write( $post_id, $current, $new );
	}

	/**
	 * Whether the state is an active lock held by exactly this session.
	 *
	 * @param array|null $state              The decoded state, or null.
	 * @param int        $user_id            The expected owner.
	 * @param string     $session_generation The expected token.
	 * @return bool True when owner and token both match an active lock.
	 */
	private function owns_session( $state, int $user_id, string $session_generation ): bool {
		return $this->state->is_active( $state )
			&& (int) $state['user'] === (int) $user_id
			&& (string) $state['token'] === $session_generation;
	}

	/**
	 * Whether the state is an active lock held by a different, non-stale owner.
	 *
	 * @param array|null $state   The decoded state, or null.
	 * @param int        $user_id The user attempting to acquire.
	 * @param int        $window  Stale-lock window in seconds.
	 * @return bool True when another user currently holds a live lock.
	 */
	private function is_active_foreign_lock( $state, int $user_id, int $window ): bool {
		return $this->state->is_active( $state )
			&& (int) $state['user'] !== (int) $user_id
			&& (int) $state['time'] > ( time() - $window );
	}

	/**
	 * Keep the caller's token across refreshes and staleness; mint a new one only
	 * on an ownership change or a forced takeover.
	 *
	 * @param array|null $current The current state, or null.
	 * @param int        $user_id The lock owner.
	 * @param bool       $bump    Force a new token.
	 * @return string The token to persist.
	 */
	private function compute_token( $current, int $user_id, bool $bump ): string {
		$same_owner_has_token = is_array( $current )
			&& (int) $current['user'] === (int) $user_id
			&& '' !== $current['token'];

		return ( ! $bump && $same_owner_has_token )
			? (string) $current['token']
			: wp_generate_uuid4();
	}
}
