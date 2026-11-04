<?php
/**
 * Temporary resumable description executor.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-decker-task-description-html.php';
require_once __DIR__ . '/class-decker-task-description-migration-state.php';
require_once __DIR__ . '/class-decker-task-description-migration-task.php';
require_once __DIR__ . '/class-decker-task-description-migration-restore.php';

/** Only content and migration bookkeeping are written; never the task writer. */
class Decker_Task_Description_Migration {

	/**
	 * Migrate every task status in stable bounded batches.
	 *
	 * @param bool          $dry_run Read only: no backup, state, dates or revisions.
	 * @param int           $batch_size Maximum rows read at once.
	 * @param callable|null $progress Optional read-only CLI progress reporter.
	 * @return array Per-site report; task failures remain visible and resumable.
	 * @throws RuntimeException When exclusion or enumeration cannot be secured.
	 */
	public function run( bool $dry_run = true, int $batch_size = 100, ?callable $progress = null ): array {
		if ( $batch_size < 1 || $batch_size > 1000 ) {
			throw new RuntimeException( 'Batch size must be between 1 and 1000.' );
		}
		if ( ! $dry_run ) {
			Decker_Task_Description_Migration_State::acquire( self::lock_name() );
		}
		try {
			if ( ! $dry_run ) {
				Decker_Task_Description_Migration_State::disable( 'migration' );
			}
			$report = $this->batches( $dry_run, $batch_size, $progress );
			if ( ! $dry_run && ! $report['errors'] ) {
				$this->complete_site( $report );
			}
		} finally {
			if ( ! $dry_run ) {
				Decker_Task_Description_Migration_State::release( self::lock_name() );
			}
		}
		return $report;
	}

	/**
	 * Enumerate within the initial high-water mark, retaining bounded memory.
	 *
	 * @param bool          $dry_run Whether every task operation is read only.
	 * @param int           $batch_size Maximum rows per batch.
	 * @param callable|null $progress Read-only progress callback.
	 * @return array Per-site report.
	 */
	private function batches( bool $dry_run, int $batch_size, ?callable $progress ): array {
		$report = array(
			'site_id' => get_current_blog_id(),
			'converted' => 0,
			'unchanged' => 0,
			'complete' => 0,
			'errors' => array(),
		);
		list( $maximum, $expected_total ) = $this->bounds();
		$cursor = 0;
		$processed = 0;
		do {
			$rows = $this->batch( $cursor, $maximum, $batch_size );
			foreach ( $rows as $row ) {
				++$processed;
				$cursor = (int) $row->ID;
				$this->process_row( new WP_Post( $row ), $dry_run, $report );
			}
			$batch_count = count( $rows );
			if ( $progress && $batch_count ) {
				$progress( $processed, $expected_total, $cursor );
			}
		} while ( $batch_count === $batch_size );
		if ( $processed !== $expected_total ) {
			$report['errors'][] = array(
				'task_id' => 0,
				'reason' => 'Task set changed during enumeration.',
			);
		}
		return $report;
	}

	/**
	 * Keep individual conversion failures visible without discarding progress.
	 *
	 * @param WP_Post $post Current raw task.
	 * @param bool    $dry_run Whether to avoid journal writes.
	 * @param array   $report Site report.
	 */
	private function process_row( WP_Post $post, bool $dry_run, array &$report ): void {
		try {
			if ( ! $dry_run ) {
				Decker_Task_Description_Migration_State::assert_lock( self::lock_name() );
			}
			$result = Decker_Task_Description_Migration_Task::run( $post, $dry_run );
			++$report[ $result ];
		} catch ( Throwable $error ) {
			$id = (int) $post->ID;
			$report['errors'][] = array(
				'task_id' => $id,
				'reason' => $error->getMessage(),
			);
			if ( ! $dry_run ) {
				$this->record_error( $id, $error->getMessage(), $report );
			}
		}
	}

	/**
	 * Preserve the recovery phase when recording a task failure.
	 *
	 * @param int    $id Task ID.
	 * @param string $message Conversion failure.
	 * @param array  $report Site report.
	 */
	private function record_error( int $id, string $message, array &$report ): void {
		$state = get_option( self::state_key( $id ), array( 'phase' => 'pending' ) );
		$state['error'] = $message;
		try {
			Decker_Task_Description_Migration_State::save( $id, $state );
		} catch ( RuntimeException $journal_error ) {
			$report['errors'][] = array(
				'task_id' => $id,
				'reason' => 'Failure could not be recorded in the journal.',
			);
		}
	}

	/**
	 * Read the initial task set without status or visibility exclusions.
	 *
	 * @return array High-water mark and expected task count.
	 * @throws RuntimeException When either enumeration query fails.
	 */
	private function bounds(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A raw high-water mark avoids WP_Query status exclusions.
		$maximum = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'decker_task'" );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Task enumeration failed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Detect task deletion during the controlled window.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'decker_task'" );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Task count failed.' );
		}
		return array( $maximum, $total );
	}

	/**
	 * Read one stable bounded batch, including hidden and archived tasks.
	 *
	 * @param int $cursor Last processed ID.
	 * @param int $maximum Initial high-water mark.
	 * @param int $size Maximum rows.
	 * @return array Raw post rows.
	 * @throws RuntimeException When the batch cannot be read.
	 */
	private function batch( int $cursor, int $maximum, int $size ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Status-independent keyset pagination, with literal SQL and prepared values.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'decker_task' AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d", $cursor, $maximum, $size ) );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Task batch could not be read.' );
		}
		return $rows;
	}

	/**
	 * Publish the site content version only after complete backup coverage.
	 *
	 * @param array $report Site report.
	 * @throws RuntimeException When the completion version cannot be persisted.
	 */
	private function complete_site( array &$report ): void {
		$verification = $this->verify();
		if ( $verification['pending'] ) {
			$report['errors'][] = array(
				'task_id' => 0,
				'reason' => 'Verification found pending tasks.',
			);
			return;
		}
		update_option( Decker_Task_Description::VERSION_OPTION, 1, false );
		wp_cache_delete( Decker_Task_Description::VERSION_OPTION, 'options' );
		if ( 1 !== (int) get_option( Decker_Task_Description::VERSION_OPTION, 0 ) ) {
			throw new RuntimeException( 'Site completion version could not be persisted.' );
		}
	}

	/**
	 * Verify complete coverage without modifying any records.
	 *
	 * @return array Counts and pending IDs.
	 * @throws RuntimeException When coverage cannot be read.
	 */
	public function verify(): array {
		global $wpdb;
		$result = array(
			'total' => 0,
			'pending' => array(),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Deliberately includes every task status, without visibility filters.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'decker_task' ORDER BY ID" );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Task verification query failed.' );
		}
		foreach ( $ids as $id ) {
			++$result['total'];
			try {
				Decker_Task_Description_Backup::read( (int) $id );
				$state = get_option( self::state_key( (int) $id ), array() );
				if ( 'complete' !== ( $state['phase'] ?? '' ) || isset( $state['error'] ) ) {
					$result['pending'][] = (int) $id;
				}
			} catch ( RuntimeException $error ) {
				$result['pending'][] = (int) $id;
			}
		}
		return $result;
	}

	/**
	 * Restore exact backup bytes under the same site-local executor exclusion.
	 *
	 * @param int $id Task ID.
	 * @return bool Whether restoration succeeded.
	 */
	public function restore( int $id ): bool {
		Decker_Task_Description_Migration_State::acquire( self::lock_name() );
		try {
			return Decker_Task_Description_Migration_Restore::run( $id );
		} finally {
			Decker_Task_Description_Migration_State::release( self::lock_name() );
		}
	}

	/**
	 * Preserve the existing fingerprint API for verification consumers.
	 *
	 * @param int          $id Task ID.
	 * @param WP_Post|null $expected Predicted fields, without writing.
	 * @return string Stable task fingerprint.
	 */
	public static function fingerprint( int $id, ?WP_Post $expected = null ): string {
		return Decker_Task_Description_Migration_State::fingerprint( $id, $expected );
	}

	/**
	 * Preserve the journal's stored identifier and existing API.
	 *
	 * @param int $id Task ID.
	 * @return string Site-local journal option name.
	 */
	public static function state_key( int $id ): string {
		return Decker_Task_Description_Migration_State::key( $id );
	}

	/**
	 * Connection lock shared by migration and restoration, isolated per site.
	 *
	 * @return string MySQL lock name.
	 */
	private static function lock_name(): string {
		global $wpdb;
		return 'decker-description-' . md5( DB_NAME . $wpdb->prefix );
	}
}
