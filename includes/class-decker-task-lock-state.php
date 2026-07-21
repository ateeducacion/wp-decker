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
 * `{"user","token","time"}`:
 *
 * - `user`/`token` — the current owner and its unique generation token.
 * - `time`         — last activity; 0 once the lock is released (the token is
 *                    kept so a stale form is still rejected after release).
 *
 * The compare-and-swap is best-effort under true concurrency: WordPress has no
 * unique constraint on (post_id, meta_key), so the very first `add_post_meta`
 * can race; every later transition uses the conditional `update_post_meta`,
 * which is atomic at the SQL layer.
 */
class Decker_Task_Lock_State {

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
	 * Decode the authoritative lock state.
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
	 * Whether the state is an active lock held by exactly this session.
	 *
	 * @param array|null $state   The decoded state, or null.
	 * @param int        $user_id The expected owner.
	 * @param string     $token   The expected generation token.
	 * @return bool True when owner and token both match an active lock.
	 */
	public function owned_by( $state, int $user_id, string $token ): bool {
		return $this->is_active( $state )
			&& (int) $state['user'] === (int) $user_id
			&& (string) $state['token'] === $token;
	}

	/**
	 * Compare-and-swap write of the authoritative state.
	 *
	 * @param int        $post_id  The task post ID.
	 * @param array|null $expected Previous state, or null when absent.
	 * @param array      $new      Desired state (user/token/time).
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
	 * Encode a state for storage. Key order is fixed for stable CAS comparisons.
	 *
	 * @param array $state The state (user/token/time).
	 * @return string JSON payload.
	 */
	private function encode( array $state ): string {
		return wp_json_encode(
			array(
				'user'  => isset( $state['user'] ) ? (int) $state['user'] : 0,
				'token' => isset( $state['token'] ) ? (string) $state['token'] : '',
				'time'  => isset( $state['time'] ) ? (int) $state['time'] : 0,
			)
		);
	}
}
