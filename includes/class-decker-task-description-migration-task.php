<?php
/**
 * One-task migration preparation, commit and interruption recovery.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** One-task migration preparation, commit and interruption recovery. */
class Decker_Task_Description_Migration_Task {

	/**
	 * Reconcile one task, including an interruption after content commit.
	 *
	 * @param WP_Post $post Raw task read by the current batch.
	 * @param bool    $dry_run Whether to avoid every persistence operation.
	 * @return string Report category.
	 * @throws RuntimeException When the original or prepared state changed.
	 */
	public static function run( WP_Post $post, bool $dry_run ): string {
		self::assert_snapshot( $post );
		$id = (int) $post->ID;
		$before = $dry_run ? '' : Decker_Task_Description_Migration_State::fingerprint( $id );
		$state = get_option( Decker_Task_Description_Migration_State::key( $id ), array() );
		if ( 'restoring' === ( $state['phase'] ?? '' ) ) {
			throw new RuntimeException( 'Restoration is pending; resume restore-descriptions first.' );
		}
		if ( $dry_run ) {
			return self::content( $post ) === $post->post_content ? 'unchanged' : 'converted';
		}
		if ( 'complete' === ( $state['phase'] ?? '' ) ) {
			return self::completed( $post, $state );
		}
		return self::migrate( $post, $state, $before );
	}

	/**
	 * Compare persisted fields, ignoring WP_Post's virtual filter property.
	 *
	 * @param WP_Post $post Batch snapshot.
	 * @throws RuntimeException When any field changed since enumeration.
	 */
	private static function assert_snapshot( WP_Post $post ): void {
		$id = (int) $post->ID;
		clean_post_cache( $id );
		$current = get_post( $id );
		$expected_fields = array_map( 'strval', get_object_vars( $post ) );
		$current_fields = $current ? array_map( 'strval', get_object_vars( $current ) ) : array();
		unset( $expected_fields['filter'], $current_fields['filter'] );
		ksort( $expected_fields );
		ksort( $current_fields );
		if ( $current_fields !== $expected_fields ) {
			throw new RuntimeException( 'Task changed after its batch was read.' );
		}
	}

	/**
	 * A completed journal must not hide a missing backup or legacy reappearance.
	 *
	 * @param WP_Post $post Current task.
	 * @param array   $state Completed journal.
	 * @return string Completed report category.
	 * @throws RuntimeException When completion requires manual review.
	 */
	private static function completed( WP_Post $post, array $state ): string {
		$id = (int) $post->ID;
		Decker_Task_Description_Backup::read( $id );
		if ( Decker_Task_Description_Converter::convert( $post->post_content ) !== $post->post_content ) {
			throw new RuntimeException( 'Legacy content reappeared after completion; review required.' );
		}
		if ( isset( $state['error'] ) ) {
			unset( $state['error'] );
			Decker_Task_Description_Migration_State::save( $id, $state );
		}
		return 'complete';
	}

	/**
	 * Secure the original before either conversion or a commit-resume decision.
	 *
	 * @param WP_Post $post Original task.
	 * @param array   $state Previous journal.
	 * @param string  $before Current task fingerprint.
	 * @return string Report category.
	 * @throws RuntimeException When the current data conflicts with preparation.
	 */
	private static function migrate( WP_Post $post, array $state, string $before ): string {
		$id = (int) $post->ID;
		$backup = Decker_Task_Description_Backup::create( $post );
		if ( self::is_committed( $post, $state ) ) {
			if ( isset( $state['after'] ) && $state['after'] !== $before ) {
				throw new RuntimeException( 'Task data changed after content commit; review required.' );
			}
			self::finish( $post, $state );
			return 'converted';
		}
		self::validate_prepared( $post, $backup, $state, $before );
		$content = self::content( $post );
		if ( $content === $post->post_content ) {
			Decker_Task_Description_Migration_State::save(
				$id,
				array(
					'phase' => 'complete',
					'fingerprint' => Decker_Task_Description_Migration_State::fingerprint( $id ),
				)
			);
			return 'unchanged';
		}
		$state = self::prepare( $post, $content, $before );
		self::write( $post, $content, $state );
		return 'converted';
	}

	/**
	 * Identify the already committed output before attempting another write.
	 *
	 * @param WP_Post $post Current task.
	 * @param array   $state Previous journal.
	 * @return bool Whether content and date match the planned output.
	 */
	private static function is_committed( WP_Post $post, array $state ): bool {
		return isset( $state['output_hash'] ) && hash( 'sha256', $post->post_content ) === $state['output_hash'] && $state['gmt'] === $post->post_modified_gmt;
	}

	/**
	 * Refuse content, field, metadata or taxonomy changes after preparation.
	 *
	 * @param WP_Post $post Current task.
	 * @param array   $backup Verified immutable backup.
	 * @param array   $state Previous journal.
	 * @param string  $before Current task fingerprint.
	 * @throws RuntimeException When preparation no longer matches current data.
	 */
	private static function validate_prepared( WP_Post $post, array $backup, array $state, string $before ): void {
		$hash = hash( 'sha256', $post->post_content );
		if ( $backup['sha256'] !== $hash || ( isset( $state['input_hash'] ) && $state['input_hash'] !== $hash ) ) {
			throw new RuntimeException( 'Content changed since the immutable backup; review required.' );
		}
		if ( isset( $state['before'] ) && $state['before'] !== $before ) {
			throw new RuntimeException( 'Task changed since migration preparation; review required.' );
		}
	}

	/**
	 * Require both structural conversion and lossless post-context filtering.
	 *
	 * @param WP_Post $post Task to convert.
	 * @return string Safe converted HTML.
	 * @throws RuntimeException When sanitization would discard historical data.
	 */
	private static function content( WP_Post $post ): string {
		$converted = Decker_Task_Description_Converter::convert( $post->post_content );
		$content = Decker_Task_Description::sanitize_task_description( $converted );
		if ( is_wp_error( $content ) ) {
			throw new RuntimeException( 'Description conversion requires review.' );
		}
		if ( ! Decker_Task_Description_Html::same_safe_html( $converted, $content ) ) {
			throw new RuntimeException( 'Historical HTML would lose unsupported markup; review required.' );
		}
		return $content;
	}

	/**
	 * Persist both expected fingerprints before the content update.
	 *
	 * @param WP_Post $post Original task.
	 * @param string  $content Target content.
	 * @param string  $before Original task fingerprint.
	 * @return array Verified prepared journal.
	 */
	private static function prepare( WP_Post $post, string $content, string $before ): array {
		$id = (int) $post->ID;
		$gmt = Decker_Task_Description_Migration_State::next_date( $post );
		$expected = Decker_Task_Description_Migration_State::expected_post( get_post( $id ), $content, $gmt );
		$state = array(
			'phase' => 'prepared',
			'input_hash' => hash( 'sha256', $post->post_content ),
			'output_hash' => hash( 'sha256', $content ),
			'gmt' => $gmt,
			'before' => $before,
			'after' => Decker_Task_Description_Migration_State::fingerprint( $id, $expected ),
		);
		Decker_Task_Description_Migration_State::save( $id, $state );
		return $state;
	}

	/**
	 * Verify the before/after boundaries around a content-only WordPress write.
	 *
	 * @param WP_Post $post Original task.
	 * @param string  $content Converted content.
	 * @param array   $state Prepared journal.
	 * @throws RuntimeException When writing or verification fails.
	 */
	private static function write( WP_Post $post, string $content, array $state ): void {
		$id = (int) $post->ID;
		clean_post_cache( $id );
		if ( Decker_Task_Description_Migration_State::fingerprint( $id ) !== $state['before'] ) {
			throw new RuntimeException( 'Task changed before content write.' );
		}
		$result = Decker_Task_Description::write_content( $post, $content, $state['gmt'] );
		if ( is_wp_error( $result ) || ! $result ) {
			throw new RuntimeException( 'Description write failed.' );
		}
		clean_post_cache( $id );
		$written = get_post( $id );
		if ( hash( 'sha256', $written->post_content ) !== $state['output_hash'] || $state['gmt'] !== $written->post_modified_gmt || Decker_Task_Description_Migration_State::fingerprint( $id ) !== $state['after'] ) {
			throw new RuntimeException( 'Description write verification failed.' );
		}
		self::finish( $written, $state );
	}

	/**
	 * Invalidate old forms before advancing the committed journal to completion.
	 *
	 * @param WP_Post $post Written task.
	 * @param array   $state Prepared journal.
	 */
	private static function finish( WP_Post $post, array $state ): void {
		$id = (int) $post->ID;
		unset( $state['error'] );
		$state['phase'] = 'written';
		Decker_Task_Description_Migration_State::save( $id, $state );
		Decker_Task_Description_Migration_State::invalidate( $id, 'Session invalidation failed.' );
		$state['phase'] = 'complete';
		$state['fingerprint'] = Decker_Task_Description_Migration_State::fingerprint( $id );
		Decker_Task_Description_Migration_State::save( $id, $state );
	}
}
