<?php
/**
 * Verified site-local migration state and content-write coordination.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Verified site-local migration state and content-write coordination. */
class Decker_Task_Description_Migration_State {

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
	 * Persist journal state and verify it before advancing.
	 *
	 * @param int   $id Task ID.
	 * @param array $state Journal.
	 * @throws RuntimeException When bookkeeping cannot be secured.
	 */
	public static function save( int $id, array $state ): void {
		update_option( self::key( $id ), $state, false );
		wp_cache_delete( self::key( $id ), 'options' );
		if ( get_option( self::key( $id ) ) !== $state ) {
			throw new RuntimeException( 'Description migration journal write failed.' );
		}
	}

	/**
	 * Return the mutable state key, separate from the immutable backup.
	 *
	 * @param int $id Task ID.
	 * @return string Option key.
	 */
	public static function key( int $id ): string {
		return '_decker_task_description_migration_v1_' . $id;
	}

	/**
	 * Keep task writes closed until the entire site is verified.
	 *
	 * @param string $operation Migration or restoration, for the failure report.
	 * @throws RuntimeException When readiness cannot be cleared.
	 */
	public static function disable( string $operation ): void {
		delete_option( Decker_Task_Description::VERSION_OPTION );
		wp_cache_delete( Decker_Task_Description::VERSION_OPTION, 'options' );
		if ( 1 === (int) get_option( Decker_Task_Description::VERSION_OPTION, 0 ) ) {
			throw new RuntimeException( esc_html( 'Task writes could not be disabled before ' . $operation . '.' ) );
		}
	}

	/**
	 * Rotate the existing task generation and verify its persisted value.
	 *
	 * @param int    $id Task ID.
	 * @param string $failure Operation-specific failure message.
	 * @throws RuntimeException When the new generation cannot be verified.
	 */
	public static function invalidate( int $id, string $failure ): void {
		$token = ( new Decker_Task_Locks() )->invalidate_sessions( $id );
		$lock = ( new Decker_Task_Lock_State() )->read( $id );
		if ( $token && ( $lock['token'] ?? '' ) !== $token ) {
			throw new RuntimeException( esc_html( $failure ) );
		}
	}

	/**
	 * Advance modification time so expected_modified observes the conversion.
	 *
	 * @param WP_Post $post Original task.
	 * @return string New GMT timestamp.
	 */
	public static function next_date( WP_Post $post ): string {
		return gmdate( 'Y-m-d H:i:s', max( time(), strtotime( $post->post_modified_gmt . ' UTC' ) + 1 ) );
	}

	/**
	 * Predict the content-only update without touching stored task data.
	 *
	 * @param WP_Post $post Original task.
	 * @param string  $content Target content.
	 * @param string  $gmt Planned modification date.
	 * @return WP_Post Expected fields after persistence.
	 */
	public static function expected_post( WP_Post $post, string $content, string $gmt ): WP_Post {
		$expected = clone $post;
		$expected->post_content = $content;
		$expected->post_modified_gmt = $gmt;
		$expected->post_modified = get_date_from_gmt( $gmt );
		return $expected;
	}

	/**
	 * Exclude concurrent migration and restoration processes on this site.
	 *
	 * @param string $key Site-local lock name.
	 * @throws RuntimeException When another connection owns the lock.
	 */
	public static function acquire( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL connection-owned exclusion has no WP API.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) ) {
			throw new RuntimeException( 'Another description migration is running.' );
		}
	}

	/**
	 * A database reconnect must not silently lose executor exclusion.
	 *
	 * @param string $key Site-local lock name.
	 * @throws RuntimeException When this connection no longer owns the lock.
	 */
	public static function assert_lock( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Verify connection ownership before each task.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $key ) ) ) {
			throw new RuntimeException( 'Migration connection lock was lost.' );
		}
	}

	/**
	 * Release only this connection's executor lock.
	 *
	 * @param string $key Site-local lock name.
	 */
	public static function release( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL connection-owned exclusion has no WP API.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}
