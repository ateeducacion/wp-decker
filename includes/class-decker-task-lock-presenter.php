<?php
/**
 * Describes task lock state to API consumers.
 *
 * Deciding who holds a lock is one job; deciding what a client is allowed to
 * learn about it is another. This class owns the second: the public shape of a
 * lock owner and the conflict error that carries it.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Lock_Presenter
 */
class Decker_Task_Lock_Presenter {

	/**
	 * Build the public owner descriptor for a lock, or null when unavailable.
	 *
	 * Only the id and display name are exposed; private data such as the email
	 * address is never included.
	 *
	 * @param int $owner_id The lock owner user ID.
	 * @return array{id:int,display_name:string}|null The owner descriptor.
	 */
	public static function owner( int $owner_id ) {
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

	/**
	 * Build the standard `decker_task_locked` (409) error for a rejected save.
	 *
	 * @param string $message    Owner-specific message, or empty for the default takeover text.
	 * @param mixed  $owner      The lock owner descriptor, or null.
	 * @param string $generation The server generation to echo back.
	 * @return WP_Error The lock-conflict error.
	 */
	public static function locked_error( string $message, $owner, string $generation ): WP_Error {
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
}
