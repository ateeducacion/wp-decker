<?php
/**
 * File class-decker-task-native-lock
 *
 * Thin wrapper around WordPress core's native `_edit_lock` post meta convention.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Native_Lock
 *
 * Reads and writes the native WordPress `_edit_lock` / `_edit_last` post meta so
 * Decker locks stay interoperable with the wp-admin post editor. This is the only
 * place that touches those core keys; Decker's authoritative state lives in
 * {@see Decker_Task_Lock_Store} and mirrors here purely for compatibility.
 */
class Decker_Task_Native_Lock {

	/**
	 * Parse the native `_edit_lock` meta into owner and time.
	 *
	 * @param int $post_id The task post ID.
	 * @return array{time:int,user:int}|null The parsed lock, or null when absent.
	 */
	public function read( int $post_id ) {
		$raw = get_post_meta( $post_id, '_edit_lock', true );
		if ( ! $raw ) {
			return null;
		}

		$parts = explode( ':', (string) $raw );

		return array(
			'time' => isset( $parts[0] ) ? (int) $parts[0] : 0,
			'user' => isset( $parts[1] ) ? (int) $parts[1] : 0,
		);
	}

	/**
	 * Write the native `_edit_lock` / `_edit_last` fields for admin interoperability.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The lock owner.
	 * @return void
	 */
	public function set( int $post_id, int $user_id ) {
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );
		update_post_meta( $post_id, '_edit_last', $user_id );
	}

	/**
	 * Remove the native `_edit_lock` mirror.
	 *
	 * @param int $post_id The task post ID.
	 * @return void
	 */
	public function clear( int $post_id ) {
		delete_post_meta( $post_id, '_edit_lock' );
	}

	/**
	 * Remove the native `_edit_lock` mirror only when the user still owns it.
	 *
	 * @param int $post_id The task post ID.
	 * @param int $user_id The user releasing the lock.
	 * @return bool True when the native lock was removed.
	 */
	public function release_if_owned( int $post_id, int $user_id ): bool {
		$lock = $this->read( $post_id );
		if ( $lock && (int) $lock['user'] === (int) $user_id ) {
			$this->clear( $post_id );
			return true;
		}

		return false;
	}
}
