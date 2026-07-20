<?php
/**
 * File class-decker-task-lock-state
 *
 * Codec and predicates for the authoritative Decker edit-lock state meta.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Lock_State
 *
 * Reads, encodes and compare-and-swap-writes the `_decker_edit_lock_state` meta,
 * and answers predicates about a decoded state blob. The state is a JSON object
 * `{"user","token","time"[,"save"]}`:
 *
 * - `user`/`token` — the current owner and its unique generation token.
 * - `time`         — last activity; 0 once the lock is released.
 * - `save`         — timestamp of an in-progress commit (the write lease); the
 *                    key is omitted when 0 so states without a save stay
 *                    byte-identical to the pre-lease format (CAS-compatible).
 *
 * The compare-and-swap is best-effort under true concurrency: WordPress has no
 * unique constraint on (post_id, meta_key), so the very first `add_post_meta`
 * can race; every later transition uses the conditional `update_post_meta`,
 * which is atomic at the SQL layer.
 */
class Decker_Task_Lock_State {

	/**
	 * Authoritative lock state meta (JSON: user, token, time, save).
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
	 * Seconds a save may hold the write lease before it is treated as stale (so a
	 * crash mid-save cannot block takeovers forever).
	 *
	 * @var int
	 */
	const SAVE_LEASE = 15;

	/**
	 * Decode the authoritative lock state.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{user:int,token:string,time:int,save:int}|null The state, or null when absent.
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
			'save'  => isset( $decoded['save'] ) ? (int) $decoded['save'] : 0,
		);
	}

	/**
	 * The current generation token, or empty string when never locked.
	 *
	 * @param int $post_id The task post ID.
	 * @return string The token.
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
	 * Whether a state blob represents an actively held lock.
	 *
	 * @param array|null $state The decoded state, or null.
	 * @return bool True when the lock is actively held.
	 */
	public function is_active( $state ): bool {
		return is_array( $state )
			&& (int) $state['time'] > 0
			&& (int) $state['user'] > 0;
	}

	/**
	 * Whether a fresh write lease is held (a commit is in progress).
	 *
	 * @param array|null $state The decoded state, or null.
	 * @return bool True when a non-stale save lease is set.
	 */
	public function is_saving( $state ): bool {
		return is_array( $state )
			&& (int) $state['save'] > ( time() - self::SAVE_LEASE );
	}

	/**
	 * Compare-and-swap write of the authoritative state.
	 *
	 * @param int        $post_id  The task post ID.
	 * @param array|null $expected Previous state, or null when absent.
	 * @param array      $new      Desired state (user/token/time[/save]).
	 * @return bool True when this writer won the CAS.
	 */
	public function write( int $post_id, $expected, array $new ): bool {
		$new_raw = $this->encode( $new );

		if ( null === $expected ) {
			// Best-effort unique add (see class docblock).
			return (bool) add_post_meta( $post_id, self::STATE_META, $new_raw, true );
		}

		// update_post_meta only writes the row whose value still equals the
		// expected blob, so a concurrent winner makes this return false.
		return (bool) update_post_meta( $post_id, self::STATE_META, $new_raw, $this->encode( $expected ) );
	}

	/**
	 * Seed an empty, released state row when a task is created.
	 *
	 * The first real acquire then uses the atomic conditional update path instead
	 * of the best-effort unique add. No-op when a state already exists.
	 *
	 * @param int $post_id The task post ID.
	 * @return void
	 */
	public function initialize( int $post_id ) {
		$existing = get_post_meta( $post_id, self::STATE_META, true );
		if ( is_string( $existing ) && '' !== $existing ) {
			return;
		}

		add_post_meta( $post_id, self::STATE_META, $this->encode( array() ), true );
	}

	/**
	 * Encode a state for storage. Key order is fixed and `save` is omitted when 0
	 * so lease-free states match the pre-lease byte format for stable CAS.
	 *
	 * @param array $state The state (user/token/time[/save]).
	 * @return string JSON payload.
	 */
	private function encode( array $state ): string {
		$payload = array(
			'user'  => isset( $state['user'] ) ? (int) $state['user'] : 0,
			'token' => isset( $state['token'] ) ? (string) $state['token'] : '',
			'time'  => isset( $state['time'] ) ? (int) $state['time'] : 0,
		);

		if ( ! empty( $state['save'] ) ) {
			$payload['save'] = (int) $state['save'];
		}

		return wp_json_encode( $payload );
	}
}
