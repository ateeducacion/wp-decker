<?php
/**
 * File class-decker-task-lock-store
 *
 * Lock operations (acquire, takeover, release, refresh, generation rotation)
 * over the authoritative Decker edit-lock state.
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
 * Implements the optimistic edit-lock on top of {@see Decker_Task_Lock_State}
 * (persistence + predicates) and {@see Decker_Task_Native_Lock} (the WordPress
 * `_edit_lock` mirror). Decker_Task_Locks stays a thin policy layer above this.
 *
 * Concurrency model (optimistic locking, no write lease):
 * - every save presents the token it rendered with;
 * - the token is re-checked at the actual post write ({@see token_is_current()})
 *   so a takeover between validation and the write fails the write closed;
 * - a successful save rotates the token ({@see rotate_generation()}) so any other
 *   form still holding the old token is rejected on its next save.
 *
 * Accepted residual: two concurrent saves presenting the *same* still-valid token
 * (two tabs of one user / a double-submit) resolve last-write-wins on content.
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
	 * Whether the current authoritative generation still equals the given token.
	 *
	 * Used as a fail-closed check immediately before the post write: a takeover (or
	 * another session's completed save) since validation rotates the token, so a
	 * mismatch means this write must be aborted rather than overwrite newer content.
	 *
	 * @param int    $post_id The task post ID.
	 * @param string $token   The token the request validated with.
	 * @return bool True when the token is unchanged since validation.
	 */
	public function token_is_current( int $post_id, string $token ): bool {
		return $this->state->generation( $post_id ) === $token;
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

			// A non-forced acquire must not steal an active foreign lock.
			if ( ! $bump_generation && $this->is_active_foreign_lock( $current, $user_id, $window ) ) {
				return false;
			}

			$new = array(
				'user'  => (int) $user_id,
				'token' => $this->compute_token( $current, $user_id, $bump_generation ),
				'time'  => time(),
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

			if ( ! $this->state->owned_by( $current, $user_id, $session_generation ) ) {
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
	 * Refresh a still-held session's timestamp without acquiring.
	 *
	 * Extends the lock only when the caller still owns the exact session; a single
	 * attempt is enough since the next heartbeat retries.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user whose session is refreshing.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lock timestamp was refreshed.
	 */
	public function refresh( int $post_id, int $user_id, string $session_generation ): bool {
		$current = $this->state->read( $post_id );

		if ( ! $this->state->owned_by( $current, $user_id, $session_generation ) ) {
			return false;
		}

		$refreshed = array(
			'user'  => (int) $user_id,
			'token' => (string) $current['token'],
			'time'  => time(),
		);

		if ( ! $this->state->write( $post_id, $current, $refreshed ) ) {
			return false;
		}

		$this->native->set( $post_id, $user_id );
		return true;
	}

	/**
	 * Rotate the session generation after a successful save.
	 *
	 * Only rotates when the caller still holds the exact active session it saved
	 * with, so a second form of the same user (a tab sharing the token) can no
	 * longer save with the stale token. Never-locked (anonymous) writes have no
	 * session to rotate and leave the task free.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user finishing the save.
	 * @param string $session_generation The generation the save validated with.
	 * @return string|false The rotated generation, or false when nothing was rotated.
	 */
	public function rotate_generation( int $post_id, int $user_id, string $session_generation ) {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->state->read( $post_id );

			if ( ! $this->state->owned_by( $current, $user_id, $session_generation ) ) {
				return false;
			}

			$rotated = array(
				'user'  => (int) $current['user'],
				'token' => wp_generate_uuid4(),
				'time'  => (int) $current['time'],
			);

			if ( $this->state->write( $post_id, $current, $rotated ) ) {
				$this->native->set( $post_id, $user_id );
				return (string) $rotated['token'];
			}
		}

		return false;
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
