<?php
/**
 * Temporary resumable description executor.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Only content and migration bookkeeping are written; never the task writer. */
class Decker_Task_Description_Migration {
	/**
	 * Migrate every task status in stable bounded batches.
	 *
	 * @param bool          $dry_run Read only: no backup, state, dates or revisions.
	 * @param int           $batch_size Maximum rows read at once.
	 * @param callable|null $progress Optional read-only CLI progress reporter.
	 * @return array Per-site report; task failures remain visible and resumable.
	 * @throws RuntimeException When another executor owns this site's lock.
	 */
	public function run( bool $dry_run = true, int $batch_size = 100, ?callable $progress = null ): array {
		global $wpdb;
		if ( $batch_size < 1 || $batch_size > 1000 ) {
			throw new RuntimeException( 'Batch size must be between 1 and 1000.' );
		}
		$report = array(
			'site_id' => get_current_blog_id(),
			'converted' => 0,
			'unchanged' => 0,
			'complete' => 0,
			'errors' => array(),
		);
		$key = self::lock_name();
		if ( ! $dry_run ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL connection-owned executor exclusion has no WP API.
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
				throw new RuntimeException( 'Another description migration is running.' );
			}
		}
		try {
			if ( ! $dry_run ) {
				delete_option( Decker_Task_Description::VERSION_OPTION );
				wp_cache_delete( Decker_Task_Description::VERSION_OPTION, 'options' );
				if ( 1 === (int) get_option( Decker_Task_Description::VERSION_OPTION, 0 ) ) {
					throw new RuntimeException( 'Task writes could not be disabled before migration.' );
				}
			}
			$cursor = 0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A raw status-independent high-water mark avoids WP_Query exclusions.
			$maximum = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'decker_task'" );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Task enumeration failed.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Detect task deletion during the controlled window.
			$expected_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'decker_task'" );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Task count failed.' );
			}
			$processed = 0;
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- All statuses and hidden tasks, with stable keyset pagination.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_type = 'decker_task' AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d", $cursor, $maximum, $batch_size ) );
				if ( $wpdb->last_error || ! is_array( $rows ) ) {
					throw new RuntimeException( 'Task batch could not be read.' );
				}
				foreach ( $rows as $row ) {
					++$processed;
					$cursor = (int) $row->ID;
					try {
						if ( ! $dry_run ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A reconnect must not silently lose the executor lock.
							if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $key ) ) ) {
								throw new RuntimeException( 'Migration connection lock was lost.' );
							}
						}
						$result = $this->task( new WP_Post( $row ), $dry_run );
						++$report[ $result ];
					} catch ( Throwable $error ) {
						$report['errors'][] = array(
							'task_id' => $cursor,
							'reason' => $error->getMessage(),
						);
						if ( ! $dry_run ) {
							$state = get_option( self::state_key( $cursor ), array( 'phase' => 'pending' ) );
							$state['error'] = $error->getMessage();
							try {
								$this->save_state( $cursor, $state );
							} catch ( RuntimeException $journal_error ) {
								$report['errors'][] = array(
									'task_id' => $cursor,
									'reason' => 'Failure could not be recorded in the journal.',
								);
							}
						}
					}
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
			if ( ! $dry_run && ! $report['errors'] ) {
				$verification = $this->verify();
				if ( $verification['pending'] ) {
					$report['errors'][] = array(
						'task_id' => 0,
						'reason' => 'Verification found pending tasks.',
					);
				} else {
					update_option( Decker_Task_Description::VERSION_OPTION, 1, false );
					wp_cache_delete( Decker_Task_Description::VERSION_OPTION, 'options' );
					if ( 1 !== (int) get_option( Decker_Task_Description::VERSION_OPTION, 0 ) ) {
						throw new RuntimeException( 'Site completion version could not be persisted.' );
					}
				}
			}
		} finally {
			if ( ! $dry_run ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only this connection's executor lock.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			}
		}
		return $report;
	}

	/**
	 * Reconcile one task, including a crash between commit and journal update.
	 *
	 * @param WP_Post $post Raw task.
	 * @param bool    $dry_run Whether to write anything.
	 * @return string Report category.
	 * @throws RuntimeException When conversion or persistence fails.
	 */
	private function task( WP_Post $post, bool $dry_run ): string {
		$id = (int) $post->ID;
		$hash = hash( 'sha256', $post->post_content );
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
		$before = $dry_run ? '' : self::fingerprint( $id );
		$state = get_option( self::state_key( $id ), array() );
		if ( 'restoring' === ( $state['phase'] ?? '' ) ) {
			throw new RuntimeException( 'Restoration is pending; resume restore-descriptions first.' );
		}
		if ( ! $dry_run && 'complete' === ( $state['phase'] ?? '' ) ) {
			Decker_Task_Description_Backup::read( $id );
			if ( Decker_Task_Description_Converter::convert( $post->post_content ) !== $post->post_content ) {
				throw new RuntimeException( 'Legacy content reappeared after completion; review required.' );
			}
			if ( isset( $state['error'] ) ) {
				unset( $state['error'] );
				$this->save_state( $id, $state );
			}
			return 'complete';
		}
		if ( ! $dry_run ) {
			$backup = Decker_Task_Description_Backup::create( $post );
		}
		if ( ! $dry_run && isset( $state['output_hash'] ) && $hash === $state['output_hash'] && $state['gmt'] === $post->post_modified_gmt ) {
			if ( isset( $state['after'] ) && $state['after'] !== $before ) {
				throw new RuntimeException( 'Task data changed after content commit; review required.' );
			}
			$this->finish( $post, $state );
			return 'converted';
		}
		if ( ! $dry_run && ( $backup['sha256'] !== $hash || ( isset( $state['input_hash'] ) && $state['input_hash'] !== $hash ) ) ) {
			throw new RuntimeException( 'Content changed since the immutable backup; review required.' );
		}
		if ( ! $dry_run && isset( $state['before'] ) && $state['before'] !== $before ) {
			throw new RuntimeException( 'Task changed since migration preparation; review required.' );
		}
		$converted = Decker_Task_Description_Converter::convert( $post->post_content );
		$content = Decker_Task_Description::sanitize_task_description( $converted );
		if ( is_wp_error( $content ) ) {
			throw new RuntimeException( 'Description conversion requires review.' );
		}
		if ( ! self::same_safe_html( $converted, $content ) ) {
			throw new RuntimeException( 'Historical HTML would lose unsupported markup; review required.' );
		}
		if ( $dry_run ) {
			return $content === $post->post_content ? 'unchanged' : 'converted';
		}
		if ( $content === $post->post_content ) {
			$this->save_state(
				$id,
				array(
					'phase' => 'complete',
					'fingerprint' => self::fingerprint( $id ),
				)
			);
			return 'unchanged';
		}
		$gmt = gmdate( 'Y-m-d H:i:s', max( time(), strtotime( $post->post_modified_gmt . ' UTC' ) + 1 ) );
		$expected = clone $current;
		$expected->post_content = $content;
		$expected->post_modified_gmt = $gmt;
		$expected->post_modified = get_date_from_gmt( $gmt );
		$state = array(
			'phase' => 'prepared',
			'input_hash' => $hash,
			'output_hash' => hash( 'sha256', $content ),
			'gmt' => $gmt,
			'before' => $before,
			'after' => self::fingerprint( $id, $expected ),
		);
		$this->save_state( $id, $state );
		clean_post_cache( $id );
		if ( self::fingerprint( $id ) !== $state['before'] ) {
			throw new RuntimeException( 'Task changed before content write.' );
		}
		$result = Decker_Task_Description::write_content( $post, $content, $gmt );
		if ( is_wp_error( $result ) || ! $result ) {
			throw new RuntimeException( 'Description write failed.' );
		}
		clean_post_cache( $id );
		$written = get_post( $id );
		if ( hash( 'sha256', $written->post_content ) !== $state['output_hash'] || $gmt !== $written->post_modified_gmt || self::fingerprint( $id ) !== $state['after'] ) {
			throw new RuntimeException( 'Description write verification failed.' );
		}
		$this->finish( $written, $state );
		return 'converted';
	}

	/**
	 * Invalidate old forms before completing a committed conversion.
	 *
	 * @param WP_Post $post Written task.
	 * @param array   $state Prepared journal.
	 * @throws RuntimeException When invalidation cannot be verified.
	 */
	private function finish( WP_Post $post, array $state ): void {
		$id = (int) $post->ID;
		unset( $state['error'] );
		$state['phase'] = 'written';
		$this->save_state( $id, $state );
		$token = ( new Decker_Task_Locks() )->invalidate_sessions( $id );
		$lock = ( new Decker_Task_Lock_State() )->read( $id );
		if ( $token && ( $lock['token'] ?? '' ) !== $token ) {
			throw new RuntimeException( 'Session invalidation failed.' );
		}
		$state['phase'] = 'complete';
		$state['fingerprint'] = self::fingerprint( $id );
		$this->save_state( $id, $state );
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
	 * Restore the original bytes only when no later task edit is detected.
	 *
	 * @param int $id Task ID.
	 * @return bool Whether exact restoration succeeded.
	 * @throws RuntimeException When restoration would overwrite an edit.
	 */
	public function restore( int $id ): bool {
		global $wpdb;
		$key = self::lock_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Same connection-owned exclusion as the migration executor.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			throw new RuntimeException( 'Another description migration is running.' );
		}
		try {
			return $this->restore_task( $id );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release this connection's executor lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Restore under the executor lock.
	 *
	 * @param int $id Task ID.
	 * @return bool Whether restoration succeeded.
	 * @throws RuntimeException When restoration is unsafe.
	 */
	private function restore_task( int $id ): bool {
		$backup = Decker_Task_Description_Backup::read( $id );
		clean_post_cache( $id );
		$state = get_option( self::state_key( $id ), array() );
		$fingerprint = self::fingerprint( $id );
		if ( 'restored' === ( $state['phase'] ?? '' ) && ( $state['fingerprint'] ?? '' ) === $fingerprint ) {
			return true;
		}
		if ( 'restoring' !== ( $state['phase'] ?? '' ) && ( 'complete' !== ( $state['phase'] ?? '' ) || ( $state['fingerprint'] ?? '' ) !== $fingerprint ) ) {
			throw new RuntimeException( 'Task was edited after migration; automatic restore refused.' );
		}
		$post = get_post( $id );
		if ( 'restoring' !== $state['phase'] ) {
			$gmt = gmdate( 'Y-m-d H:i:s', max( time(), strtotime( $post->post_modified_gmt . ' UTC' ) + 1 ) );
			$expected = clone $post;
			$expected->post_content = $backup['content'];
			$expected->post_modified_gmt = $gmt;
			$expected->post_modified = get_date_from_gmt( $gmt );
			$state = array(
				'phase' => 'restoring',
				'before' => $fingerprint,
				'after' => self::fingerprint( $id, $expected ),
				'gmt' => $gmt,
			);
			$this->save_state( $id, $state );
		}
		if ( ! in_array( $fingerprint, array( $state['before'], $state['after'] ), true ) ) {
			throw new RuntimeException( 'Task changed during restoration; review required.' );
		}
		delete_option( Decker_Task_Description::VERSION_OPTION );
		wp_cache_delete( Decker_Task_Description::VERSION_OPTION, 'options' );
		if ( 1 === (int) get_option( Decker_Task_Description::VERSION_OPTION, 0 ) ) {
			throw new RuntimeException( 'Task writes could not be disabled before restoration.' );
		}
		if ( $fingerprint === $state['before'] ) {
			$result = Decker_Task_Description::write_content( $post, $backup['content'], $state['gmt'] );
			if ( is_wp_error( $result ) || ! $result ) {
				throw new RuntimeException( 'Exact description restore failed.' );
			}
		}
		clean_post_cache( $id );
		if ( get_post( $id )->post_content !== $backup['content'] || self::fingerprint( $id ) !== $state['after'] ) {
			throw new RuntimeException( 'Exact description restore failed.' );
		}
		$token = ( new Decker_Task_Locks() )->invalidate_sessions( $id );
		$lock = ( new Decker_Task_Lock_State() )->read( $id );
		if ( $token && ( $lock['token'] ?? '' ) !== $token ) {
			throw new RuntimeException( 'Restored session invalidation failed.' );
		}
		$this->save_state(
			$id,
			array(
				'phase' => 'restored',
				'fingerprint' => self::fingerprint( $id ),
			)
		);
		return true;
	}

	/**
	 * Detect any subsequent task field/meta/taxonomy change, including title only.
	 *
	 * @param int          $id Task ID.
	 * @param WP_Post|null $expected Optional predicted post fields, without writing.
	 * @return string Stable task fingerprint.
	 * @throws RuntimeException When task metadata or terms cannot be read.
	 */
	public static function fingerprint( int $id, ?WP_Post $expected = null ): string {
		global $wpdb;
		$post = $expected ?? get_post( $id );
		$meta = get_post_meta( $id );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Task metadata could not be read.' );
		}
		// Acquiring/refreshing a lock is not an edit to the task's data.
		unset( $meta['_edit_lock'], $meta['_edit_last'], $meta[ Decker_Task_Locks::STATE_META ] );
		ksort( $meta );
		$terms = wp_get_object_terms( $id, array( 'decker_board', 'decker_label' ), array( 'fields' => 'tt_ids' ) );
		if ( is_wp_error( $terms ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Task taxonomy terms could not be read.' );
		}
		sort( $terms );
		return hash( 'sha256', serialize( array( $post ? $post->to_array() : null, $meta, $terms ) ) );
	}

	/**
	 * Ignore only harmless serialization of attribute order/entities/style endings.
	 *
	 * @param string $before Converted HTML.
	 * @param string $after KSES result.
	 * @return bool Whether KSES retained the historical structure and attributes.
	 */
	private static function same_safe_html( string $before, string $after ): bool {
		if ( $before === $after ) {
			return true;
		}
		$canonical = static function ( $html ) {
			$dom = new DOMDocument( '1.0', 'UTF-8' );
			$previous = libxml_use_internal_errors( true );
			try {
				$loaded = $dom->loadHTML( '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
			}
			if ( ! $loaded || ! $dom->getElementsByTagName( 'body' )->length ) {
				return false;
			}
			foreach ( ( new DOMXPath( $dom ) )->query( '//*[@style]' ) as $node ) {
				$node->setAttribute( 'style', self::canonical_style( $node->getAttribute( 'style' ) ) );
			}
			foreach ( ( new DOMXPath( $dom ) )->query( '//*[@href or @src]' ) as $node ) {
				foreach ( array( 'href', 'src' ) as $attribute ) {
					if ( $node->hasAttribute( $attribute ) ) {
						$node->setAttribute( $attribute, trim( $node->getAttribute( $attribute ) ) );
					}
				}
			}
			return $dom->getElementsByTagName( 'body' )->item( 0 )->C14N();
		};
		$original = $canonical( $before );
		return is_string( $original ) && $original === $canonical( $after );
	}

	/**
	 * Ignore declaration separator whitespace, never spaces inside CSS values.
	 *
	 * @param string $css Inline CSS.
	 * @return string Canonical declaration sequence for preservation checks only.
	 */
	private static function canonical_style( string $css ): string {
		$parts = array();
		$part = '';
		$quote = '';
		$depth = 0;
		$escaped = false;
		foreach ( str_split( $css ) as $character ) {
			if ( $escaped ) {
				$escaped = false;
			} elseif ( '\\' === $character ) {
				$escaped = true;
			} elseif ( $quote ) {
				if ( $character === $quote ) {
					$quote = '';
				}
			} elseif ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			} elseif ( ';' === $character && 0 === $depth ) {
				if ( '' !== trim( $part ) ) {
					$parts[] = trim( $part );
				}
				$part = '';
				continue;
			}
			$part .= $character;
		}
		if ( $quote || $depth || $escaped ) {
			return $css;
		}
		if ( '' !== trim( $part ) ) {
			$parts[] = trim( $part );
		}
		return implode( ';', $parts );
	}

	/**
	 * Persist journal state and verify it before advancing.
	 *
	 * @param int   $id Task ID.
	 * @param array $state Journal.
	 * @throws RuntimeException When bookkeeping cannot be secured.
	 */
	private function save_state( int $id, array $state ): void {
		update_option( self::state_key( $id ), $state, false );
		wp_cache_delete( self::state_key( $id ), 'options' );
		if ( get_option( self::state_key( $id ) ) !== $state ) {
			throw new RuntimeException( 'Description migration journal write failed.' );
		}
	}

	/**
	 * Return the mutable state key, separate from the immutable backup.
	 *
	 * @param int $id Task ID.
	 * @return string Option key.
	 */
	public static function state_key( int $id ): string {
		return '_decker_task_description_migration_v1_' . $id;
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
