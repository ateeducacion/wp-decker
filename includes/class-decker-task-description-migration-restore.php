<?php
/**
 * Exact backup restoration with resumable conflict detection.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Exact backup restoration with resumable conflict detection. */
class Decker_Task_Description_Migration_Restore {

	/**
	 * Restore original bytes only when the task still matches its journal.
	 *
	 * @param int $id Task ID, protected by the caller's executor lock.
	 * @return bool Whether exact restoration succeeded.
	 * @throws RuntimeException When restoration would overwrite an edit.
	 */
	public static function run( int $id ): bool {
		$backup = Decker_Task_Description_Backup::read( $id );
		clean_post_cache( $id );
		$state = get_option( Decker_Task_Description_Migration_State::key( $id ), array() );
		$fingerprint = Decker_Task_Description_Migration_State::fingerprint( $id );
		if ( 'restored' === ( $state['phase'] ?? '' ) && ( $state['fingerprint'] ?? '' ) === $fingerprint ) {
			return true;
		}
		if ( 'restoring' !== ( $state['phase'] ?? '' ) ) {
			$state = self::prepare( $id, $backup['content'], $state, $fingerprint );
		}
		if ( ! in_array( $fingerprint, array( $state['before'], $state['after'] ), true ) ) {
			throw new RuntimeException( 'Task changed during restoration; review required.' );
		}
		Decker_Task_Description_Migration_State::disable( 'restoration' );
		self::write_if_needed( $id, $backup['content'], $state, $fingerprint );
		self::finish( $id, $backup['content'], $state );
		return true;
	}

	/**
	 * Record the exact restoration boundaries before changing content.
	 *
	 * @param int    $id Task ID.
	 * @param string $content Original exact content.
	 * @param array  $state Completed migration journal.
	 * @param string $fingerprint Current task fingerprint.
	 * @return array Verified restoration journal.
	 * @throws RuntimeException When a later task edit is detected.
	 */
	private static function prepare( int $id, string $content, array $state, string $fingerprint ): array {
		if ( 'complete' !== ( $state['phase'] ?? '' ) || ( $state['fingerprint'] ?? '' ) !== $fingerprint ) {
			throw new RuntimeException( 'Task was edited after migration; automatic restore refused.' );
		}
		$post = get_post( $id );
		$gmt = Decker_Task_Description_Migration_State::next_date( $post );
		$expected = Decker_Task_Description_Migration_State::expected_post( $post, $content, $gmt );
		$state = array(
			'phase' => 'restoring',
			'before' => $fingerprint,
			'after' => Decker_Task_Description_Migration_State::fingerprint( $id, $expected ),
			'gmt' => $gmt,
		);
		Decker_Task_Description_Migration_State::save( $id, $state );
		return $state;
	}

	/**
	 * Resume a committed restore without rewriting dates or revisions.
	 *
	 * @param int    $id Task ID.
	 * @param string $content Original exact content.
	 * @param array  $state Prepared restoration.
	 * @param string $fingerprint Current task fingerprint.
	 * @throws RuntimeException When WordPress rejects the content write.
	 */
	private static function write_if_needed( int $id, string $content, array $state, string $fingerprint ): void {
		if ( $fingerprint !== $state['before'] ) {
			return;
		}
		$result = Decker_Task_Description::write_content( get_post( $id ), $content, $state['gmt'] );
		if ( is_wp_error( $result ) || ! $result ) {
			throw new RuntimeException( 'Exact description restore failed.' );
		}
	}

	/**
	 * Verify exact content and unrelated data before invalidating old forms.
	 *
	 * @param int    $id Task ID.
	 * @param string $content Original exact content.
	 * @param array  $state Prepared restoration.
	 * @throws RuntimeException When the expected output cannot be verified.
	 */
	private static function finish( int $id, string $content, array $state ): void {
		clean_post_cache( $id );
		if ( get_post( $id )->post_content !== $content || Decker_Task_Description_Migration_State::fingerprint( $id ) !== $state['after'] ) {
			throw new RuntimeException( 'Exact description restore failed.' );
		}
		Decker_Task_Description_Migration_State::invalidate( $id, 'Restored session invalidation failed.' );
		Decker_Task_Description_Migration_State::save(
			$id,
			array(
				'phase' => 'restored',
				'fingerprint' => Decker_Task_Description_Migration_State::fingerprint( $id ),
			)
		);
	}
}
