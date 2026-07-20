<?php
/**
 * File class-decker-task-lock-store
 *
 * Persistence and compare-and-swap mechanics for Decker task edit locks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-decker-task-native-lock.php';

/**
 * Class Decker_Task_Lock_Store
 *
 * Owns the authoritative `_decker_edit_lock_state` meta and the compare-and-swap
 * writes that keep the owner and generation token consistent. The native
 * `_edit_lock` mirror used for WordPress admin interoperability is delegated to
 * {@see Decker_Task_Native_Lock}.
 *
 * Keeping every persistence detail here lets Decker_Task_Locks stay a thin policy
 * layer (capability checks, stale-lock window, save gating) instead of also
 * carrying the storage state machine.
 */
class Decker_Task_Lock_Store {

	/**
	 * Authoritative lock state meta (JSON: user, token, time).
	 *
	 * @var string
	 */
	const STATE_META = '_decker_edit_lock_state';

	/**
	 * Legacy generation-only meta key (read as fallback, no longer written).
	 *
	 * @var string
	 */
	const GENERATION_META = '_decker_edit_lock_generation';

	/**
	 * Maximum CAS attempts for a single lock mutation.
	 *
	 * @var int
	 */
	const CAS_MAX_ATTEMPTS = 5;

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
		$this->native = new Decker_Task_Native_Lock();
	}

	/**
	 * Read the authoritative Decker lock state, if present.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{user:int,token:string,time:int}|null The state, or null when absent.
	 */
	public function read( int $post_id ) {
		$raw = get_post_meta( $post_id, self::STATE_META, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return array(
			'user'  => isset( $decoded['user'] ) ? (int) $decoded['user'] : 0,
			'token' => isset( $decoded['token'] ) ? (string) $decoded['token'] : '',
			'time'  => isset( $decoded['time'] ) ? (int) $decoded['time'] : 0,
		);
	}

	/**
	 * Read the unique lock-generation token for a task.
	 *
	 * @param int $post_id The task post ID.
	 * @return string The current generation token, or empty string when never locked.
	 */
	public function generation( int $post_id ): string {
		$state = $this->read( $post_id );
		if ( $state && '' !== $state['token'] ) {
			return $state['token'];
		}

		// Legacy single-key generation used before the atomic state meta.
		return (string) get_post_meta( $post_id, self::GENERATION_META, true );
	}

	/**
	 * Read the active lock (owner + time) from the authoritative state.
	 *
	 * Falls back to the native `_edit_lock` mirror whenever there is no active
	 * Decker lock — either no Decker state at all, or a released state (time = 0).
	 * That way a native lock set afterwards from the WordPress admin editor is
	 * still detected and respected.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The active lock, or null when free/released.
	 */
	public function read_active_lock( int $post_id ) {
		$state = $this->read( $post_id );
		if ( $this->is_active( $state ) ) {
			return array(
				'time' => (int) $state['time'],
				'user' => (int) $state['user'],
			);
		}

		return $this->native->read( $post_id );
	}

	/**
	 * Whether a state blob represents an actively held lock.
	 *
	 * @param array|null $state The state (user/token/time), or null.
	 * @return bool True when the lock is actively held.
	 */
	private function is_active( $state ): bool {
		return is_array( $state )
			&& (int) $state['time'] > 0
			&& (int) $state['user'] > 0;
	}

	/**
	 * Write owner + generation as one CAS-protected state blob, retrying on contention.
	 *
	 * @param int  $post_id         The task post ID.
	 * @param int  $user_id         The lock owner.
	 * @param bool $bump_generation Force a new generation token.
	 * @param int  $window          Stale-lock window in seconds (foreign-lock guard).
	 * @return bool True when the CAS write succeeded.
	 */
	public function write( int $post_id, int $user_id, bool $bump_generation, int $window ): bool {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->read( $post_id );

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

			// Re-read after the action so injected concurrent writes are observed and
			// used as the CAS expected value.
			$current = $this->read( $post_id );

			// Non-forced acquire must not steal an active foreign lock; retrying cannot help.
			if ( ! $bump_generation && $this->is_active_foreign_lock( $current, $user_id, $window ) ) {
				return false;
			}

			// Keep the caller's generation token across refreshes, and even after the
			// lock went stale, as long as the same user still owns the state. A new
			// token is only minted on an ownership change or a forced takeover, so a
			// sole editor whose heartbeat lapsed is never invalidated by staleness
			// alone (which would surface as a false "another user took over").
			$same_owner_has_token = is_array( $current )
				&& (int) $current['user'] === (int) $user_id
				&& '' !== $current['token'];

			$token = ( ! $bump_generation && $same_owner_has_token )
				? $current['token']
				: wp_generate_uuid4();

			$new = array(
				'user'  => (int) $user_id,
				'token' => (string) $token,
				'time'  => time(),
			);

			if ( $this->cas_write( $post_id, $current, $new ) ) {
				$this->native->set( $post_id, $user_id );
				return true;
			}

			// CAS lost to a concurrent writer: retry against fresh state.
		}

		return false;
	}

	/**
	 * Release the lock owned by the given user, keeping the token (time set to 0).
	 *
	 * Retries the CAS on contention. When no Decker state exists it only removes
	 * a native `_edit_lock` mirror still owned by the user.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user releasing the lock.
	 * @return bool True when the lock was released.
	 */
	public function release( int $post_id, int $user_id ): bool {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; $attempt++ ) {
			$current = $this->read( $post_id );

			if ( ! $this->is_active( $current ) || (int) $current['user'] !== (int) $user_id ) {
				// Legacy mirror only: release native lock when we still own it.
				return $this->native->release_if_owned( $post_id, $user_id );
			}

			$released = array(
				'user'  => (int) $user_id,
				'token' => (string) $current['token'],
				'time'  => 0,
			);

			if ( $this->cas_write( $post_id, $current, $released ) ) {
				$this->native->clear( $post_id );
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh a still-held session's timestamp without acquiring.
	 *
	 * Unlike {@see write()}, this never acquires a free or released lock and never
	 * mints a new generation. It only extends the lock when the caller still owns
	 * an active lock whose token matches their session generation, so a takeover
	 * can never be undone by the previous editor's heartbeat.
	 *
	 * A single attempt is enough: on CAS contention the next heartbeat retries.
	 *
	 * @param int    $post_id            The task post ID.
	 * @param int    $user_id            The user whose session is refreshing.
	 * @param string $session_generation The generation token embedded in the editor form.
	 * @return bool True when the lock timestamp was refreshed.
	 */
	public function refresh( int $post_id, int $user_id, string $session_generation ): bool {
		$current = $this->read( $post_id );

		// Only the current owner holding the exact session token may refresh.
		if ( '' === $session_generation
			|| ! $this->is_active( $current )
			|| (int) $current['user'] !== (int) $user_id
			|| (string) $current['token'] !== $session_generation ) {
			return false;
		}

		$refreshed = array(
			'user'  => (int) $user_id,
			'token' => (string) $current['token'],
			'time'  => time(),
		);

		if ( ! $this->cas_write( $post_id, $current, $refreshed ) ) {
			return false;
		}

		$this->native->set( $post_id, $user_id );
		return true;
	}

	/**
	 * Whether the state is an active lock held by a different, non-stale owner.
	 *
	 * @param array|null $state   The state (user/token/time), or null.
	 * @param int        $user_id The user attempting to acquire.
	 * @param int        $window  Stale-lock window in seconds.
	 * @return bool True when another user currently holds a live lock.
	 */
	private function is_active_foreign_lock( $state, int $user_id, int $window ): bool {
		return $this->is_active( $state )
			&& (int) $state['user'] !== (int) $user_id
			&& (int) $state['time'] > ( time() - $window );
	}

	/**
	 * Encode lock state for storage. Key order is fixed for stable CAS comparisons.
	 *
	 * @param array $state The state (user/token/time).
	 * @return string JSON payload.
	 */
	private function encode( array $state ): string {
		return wp_json_encode(
			array(
				'user'  => (int) $state['user'],
				'token' => (string) $state['token'],
				'time'  => (int) $state['time'],
			)
		);
	}

	/**
	 * Compare-and-swap write of the authoritative lock state.
	 *
	 * This emulates CAS on top of post meta and is best-effort under true
	 * concurrency: WordPress has no unique constraint on (post_id, meta_key), so
	 * `add_post_meta( ..., $unique = true )` still races between its internal
	 * existence check and the INSERT. That window is limited to the very first
	 * lock on a never-locked task; every later transition goes through the
	 * conditional `update_post_meta` path, which is atomic at the SQL layer.
	 *
	 * @param int        $post_id  The task post ID.
	 * @param array|null $expected Previous state (user/token/time), or null when absent.
	 * @param array      $new      Desired state (user/token/time).
	 * @return bool True when this writer won the CAS.
	 */
	private function cas_write( int $post_id, $expected, array $new ): bool {
		$new_raw = $this->encode( $new );

		if ( null === $expected ) {
			// Best-effort unique add: fails when the key already exists, but two
			// simultaneous first writers can both succeed (see method docblock).
			$added = add_post_meta( $post_id, self::STATE_META, $new_raw, true );
			return (bool) $added;
		}

		$expected_raw = $this->encode( $expected );
		// update_post_meta only writes the row whose value still equals the
		// expected blob, so a concurrent winner makes this return false. It also
		// returns false when $new equals the stored value (no row changed); the
		// caller's retry loop tolerates that harmless no-op.
		$updated = update_post_meta( $post_id, self::STATE_META, $new_raw, $expected_raw );

		return (bool) $updated;
	}

	/**
	 * Seed an empty, released state row when a task is created.
	 *
	 * The first real acquire then goes through the atomic conditional
	 * `update_post_meta` path instead of the best-effort unique add, closing the
	 * duplicate-row window in {@see cas_write()} for tasks created after this
	 * runs. The seeded state is inactive (no owner, time 0), so it never reads as
	 * a lock and carries no generation. No-op when a state already exists, and
	 * race-free because a just-created task is not yet visible to other editors.
	 *
	 * @param int $post_id The task post ID.
	 * @return void
	 */
	public function initialize( int $post_id ) {
		$existing = get_post_meta( $post_id, self::STATE_META, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		add_post_meta(
			$post_id,
			self::STATE_META,
			$this->encode(
				array(
					'user'  => 0,
					'token' => '',
					'time'  => 0,
				)
			),
			true
		);
	}
}
