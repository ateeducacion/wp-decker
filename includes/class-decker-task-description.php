<?php
/**
 * Permanent task-only description policy.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Applies the post HTML policy without changing other entities. */
class Decker_Task_Description {
	/** Content version, independent of the plugin release version. */
	const VERSION_OPTION = 'decker_task_description_content_version';
	/**
	 * Internal content-only restoration, never a request parameter.
	 *
	 * @var int
	 */
	private static $writing_id = 0;

	/** Register native write boundaries and successful revision invalidation. */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'initialize_version' ), 1 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'reject_invalid' ), 20, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'filter_post' ), 20, 2 );
		add_action( 'wp_restore_post_revision', array( __CLASS__, 'revision_restored' ) );
	}

	/**
	 * Prepare unslashed user content before any narrower filtering.
	 *
	 * @param string $html Description.
	 * @return string|WP_Error Safe classic HTML or a visible error.
	 */
	public static function sanitize_task_description( string $html ) {
		try {
			return wp_kses( Decker_Task_Description_Converter::convert( $html ), Decker::get_allowed_tags() );
		} catch ( UnexpectedValueException $error ) {
			return new WP_Error( 'decker_description_review', __( 'The legacy task description requires review before saving.', 'decker' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Abort an ambiguous native write before WordPress changes the parent.
	 *
	 * @param bool  $empty Core rejection decision.
	 * @param array $postarr Slashed post fields.
	 * @return bool Whether to reject.
	 */
	public static function reject_invalid( $empty, $postarr ): bool {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( 'decker_task' !== ( $postarr['post_type'] ?? '' ) || ( 0 < self::$writing_id && $id === self::$writing_id ) ) {
			return $empty;
		}
		if ( ! self::is_ready() ) {
			return true;
		}
		// Covers the first write after switch_to_blog(), without another init action.
		self::initialize_version();
		return $empty || is_wp_error( self::sanitize_task_description( wp_unslash( $postarr['post_content'] ?? '' ) ) );
	}

	/** Only an empty/new site can skip the backup of pre-existing tasks. */
	public static function initialize_version(): void {
		if ( false === get_option( self::VERSION_OPTION, false ) && self::is_ready() ) {
			update_option( self::VERSION_OPTION, 1, false );
		}
	}

	/**
	 * Preserve an upgrade route even after the temporary executor is retired.
	 *
	 * @return bool Whether description writes may proceed.
	 */
	public static function is_ready(): bool {
		if ( 1 === (int) get_option( self::VERSION_OPTION, 0 ) ) {
			return true;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Missing version must consider archived/private/hidden tasks too.
		$existing = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'decker_task' LIMIT 1" );
		return ! $wpdb->last_error && ! $existing;
	}

	/**
	 * Apply the same task policy to REST, wp-admin and other native callers.
	 *
	 * @param array $data Slashed processed fields.
	 * @param array $postarr Slashed original fields.
	 * @return array Filtered fields.
	 */
	public static function filter_post( $data, $postarr ): array {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( 'decker_task' === $data['post_type'] && ! ( 0 < self::$writing_id && $id === self::$writing_id ) ) {
			$content = self::sanitize_task_description( wp_unslash( $data['post_content'] ) );
			if ( ! is_wp_error( $content ) ) {
				$data['post_content'] = wp_slash( $content );
			}
		}
		return $data;
	}

	/**
	 * Write only content with verified caller-owned data and monotonic dates.
	 *
	 * @param WP_Post $post Current task.
	 * @param string  $html Already validated conversion, or verified raw backup.
	 * @param string  $gmt Planned GMT modification timestamp.
	 * @return int|WP_Error WordPress result.
	 */
	public static function write_content( WP_Post $post, string $html, string $gmt ) {
		$id = (int) $post->ID;
		$fields = static function ( $data, $postarr ) use ( $id, $post, $html, $gmt ) {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $id && 'decker_task' === $data['post_type'] ) {
				// Core may otherwise fill draft creation dates or transition old future posts.
				foreach ( $data as $field => $value ) {
					if ( ! in_array( $field, array( 'post_content', 'post_modified', 'post_modified_gmt' ), true ) && property_exists( $post, $field ) ) {
						$data[ $field ] = wp_slash( $post->$field );
					}
				}
				$data['post_content'] = wp_slash( $html );
				$data['post_modified_gmt'] = $gmt;
				$data['post_modified'] = get_date_from_gmt( $gmt );
			}
			return $data;
		};
		$keep = static function ( $number, $revision_post ) use ( $id ) {
			return (int) $revision_post->ID === $id && 0 !== $number ? -1 : $number;
		};
		$slug = static function ( $override, $candidate, $post_id ) use ( $id, $post ) {
			return (int) $post_id === $id ? $post->post_name : $override;
		};
		self::$writing_id = $id;
		add_filter( 'wp_insert_post_data', $fields, PHP_INT_MAX, 2 );
		add_filter( 'wp_revisions_to_keep', $keep, PHP_INT_MAX, 2 );
		// Core can fill an empty slug after wp_insert_post_data has run.
		add_filter( 'pre_wp_unique_post_slug', $slug, PHP_INT_MAX, 3 );
		try {
			return wp_update_post(
				wp_slash(
					array(
						'ID' => $id,
						'post_content' => $html,
					)
				),
				true
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $fields, PHP_INT_MAX );
			remove_filter( 'wp_revisions_to_keep', $keep, PHP_INT_MAX );
			remove_filter( 'pre_wp_unique_post_slug', $slug, PHP_INT_MAX );
			self::$writing_id = 0;
		}
	}

	/**
	 * Native revision restores supersede open full-form editors too.
	 *
	 * @param int $id Restored parent ID.
	 */
	public static function revision_restored( $id ): void {
		if ( 'decker_task' === get_post_type( $id ) ) {
			( new Decker_Task_Locks() )->invalidate_sessions( (int) $id );
		}
	}
}
