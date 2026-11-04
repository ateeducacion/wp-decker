<?php
/**
 * Immutable site-local backups, deliberately absent from REST.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Backups survive post deletion and removal of the temporary executor. */
class Decker_Task_Description_Backup {
	/**
	 * Return a verified original, or fail without touching the task.
	 *
	 * @param int $id Task ID.
	 * @return array Backup.
	 * @throws RuntimeException When a backup is missing or corrupted.
	 */
	public static function read( int $id ): array {
		// Verify persisted storage rather than trusting add_option's cached value.
		wp_cache_delete( self::key( $id ), 'options' );
		$backup = get_option( self::key( $id ), false );
		if ( ! is_array( $backup ) ) {
			throw new RuntimeException( 'Missing or invalid description backup.' );
		}
		self::validate_header( $backup, $id );
		$content = base64_decode( $backup['content_base64'], true );
		if ( false === $content || strlen( $content ) !== $backup['bytes'] || ! hash_equals( $backup['sha256'], hash( 'sha256', $content ) ) ) {
			throw new RuntimeException( 'Description backup verification failed.' );
		}
		$backup['content'] = $content;
		return $backup;
	}

	/**
	 * Validate site/task ownership and field types before decoding any content.
	 *
	 * @param array $backup Stored backup.
	 * @param int   $id Expected task ID.
	 * @throws RuntimeException When identity or storage types do not match.
	 */
	private static function validate_header( array $backup, int $id ): void {
		if ( 1 !== ( $backup['version'] ?? null ) || ( $backup['site_id'] ?? null ) !== get_current_blog_id() || ( $backup['task_id'] ?? null ) !== $id || ! is_string( $backup['content_base64'] ?? null ) || ! is_int( $backup['bytes'] ?? null ) || ! is_string( $backup['sha256'] ?? null ) ) {
			throw new RuntimeException( 'Missing or invalid description backup.' );
		}
	}

	/**
	 * Store once, then verify the read-back, including empty descriptions.
	 *
	 * @param WP_Post $post Raw task.
	 * @return array Verified backup.
	 * @throws RuntimeException When the original cannot be secured.
	 */
	public static function create( WP_Post $post ): array {
		$id = (int) $post->ID;
		if ( false === get_option( self::key( $id ), false ) ) {
			$backup = array(
				'version' => 1,
				'site_id' => get_current_blog_id(),
				'task_id' => $id,
				'content_base64' => base64_encode( $post->post_content ),
				'sha256' => hash( 'sha256', $post->post_content ),
				'bytes' => strlen( $post->post_content ),
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'post_modified_gmt' => $post->post_modified_gmt,
			);
			if ( ! add_option( self::key( $id ), $backup, '', false ) ) {
				throw new RuntimeException( 'Description backup could not be written.' );
			}
		}
		return self::read( $id );
	}

	/**
	 * Stable storage protocol, retained after executor retirement.
	 *
	 * @param int $id Task ID.
	 * @return string Non-autoloaded option name.
	 */
	public static function key( int $id ): string {
		return '_decker_task_description_backup_v1_' . $id;
	}
}
