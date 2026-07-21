<?php
/**
 * File class-decker-task-lock-state
 *
 * Persistence for the Decker edit-lock state and the core `_edit_lock` mirror.
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
 * Reads and writes the `_decker_edit_lock_state` meta (`{user, token, time}`)
 * with plain meta writes — exactly like core's `wp_set_post_lock()` — and keeps
 * WordPress core's `_edit_lock` / `_edit_last` mirrored so wp-admin shows the
 * same lock. All policy lives in {@see Decker_Task_Locks}; this class only
 * persists and decodes state.
 */
class Decker_Task_Lock_State {

	/**
	 * Authoritative lock state meta (JSON: user, token, time).
	 *
	 * @var string
	 */
	const STATE_META = '_decker_edit_lock_state';

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

		$state = wp_parse_args(
			$decoded,
			array(
				'user'  => 0,
				'token' => '',
				'time'  => 0,
			)
		);

		return array(
			'user'  => (int) $state['user'],
			'token' => (string) $state['token'],
			'time'  => (int) $state['time'],
		);
	}

	/**
	 * Persist the lock state and refresh the native `_edit_lock` mirror.
	 *
	 * Plain writes, key order fixed. A released state (time 0) keeps the token so
	 * stale forms stay invalid, and drops the native mirror only when still ours.
	 *
	 * @param int    $post_id The task post ID.
	 * @param int    $user_id The lock owner.
	 * @param string $token   The session generation token.
	 * @param int    $time    Last-activity timestamp, or 0 when released.
	 * @return void
	 */
	public function save( int $post_id, int $user_id, string $token, int $time ) {
		update_post_meta(
			$post_id,
			self::STATE_META,
			wp_json_encode(
				array(
					'user'  => $user_id,
					'token' => $token,
					'time'  => $time,
				)
			)
		);

		if ( $time > 0 ) {
			update_post_meta( $post_id, '_edit_lock', $time . ':' . $user_id );
			update_post_meta( $post_id, '_edit_last', $user_id );
			return;
		}

		// Clear the native mirror only if it is still ours: a wp-admin editor may
		// have written a newer `_edit_lock` we must not delete.
		$native = $this->read_native( $post_id );
		if ( $native && (int) $native['user'] === $user_id ) {
			delete_post_meta( $post_id, '_edit_lock' );
		}
	}

	/**
	 * The current generation token, or empty string when the task was never locked.
	 *
	 * @param int $post_id The task post ID.
	 * @return string The token.
	 */
	public function generation( int $post_id ): string {
		$state = $this->read( $post_id );

		return is_array( $state ) ? (string) $state['token'] : '';
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
		return is_array( $state )
			&& (int) $state['time'] > 0
			&& (int) $state['user'] === $user_id
			&& '' !== $token
			&& (string) $state['token'] === $token;
	}

	/**
	 * Read the active lock (owner + time), falling back to the native mirror.
	 *
	 * The fallback covers locks set from the wp-admin editor, which only writes
	 * core's `_edit_lock`.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The active lock, or null when free/released.
	 */
	public function active_lock( int $post_id ) {
		$state = $this->read( $post_id );
		if ( is_array( $state ) && $state['time'] > 0 && $state['user'] > 0 ) {
			return array(
				'time' => $state['time'],
				'user' => $state['user'],
			);
		}

		return $this->read_native( $post_id );
	}

	/**
	 * The token to persist for a lock write: kept on a same-owner (re)acquire,
	 * minted on an ownership change or an explicit takeover.
	 *
	 * @param array|null $state   The current state, or null.
	 * @param int        $user_id The lock owner.
	 * @param bool       $bump    Force a new generation token (explicit takeover).
	 * @return string The token to persist.
	 */
	public function next_token( $state, int $user_id, bool $bump ): string {
		$keep = ! $bump
			&& is_array( $state )
			&& (int) $state['user'] === $user_id
			&& '' !== (string) $state['token'];

		return $keep ? (string) $state['token'] : wp_generate_uuid4();
	}

	/**
	 * Parse core's native `_edit_lock` meta ("time:user").
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The parsed lock, or null when absent.
	 */
	private function read_native( int $post_id ) {
		$raw = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! $raw ) {
			return null;
		}

		$parts = explode( ':', (string) $raw );

		return array(
			'time' => (int) ( $parts[0] ?? 0 ),
			'user' => (int) ( $parts[1] ?? 0 ),
		);
	}
}
