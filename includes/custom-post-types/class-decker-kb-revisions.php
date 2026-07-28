<?php
/**
 * Revision history for Knowledge Base articles.
 *
 * Records who last touched an article and points at the WordPress revision
 * screen for it. This is reporting about an article's history rather than part
 * of managing the article itself, so it lives apart from Decker_Kb.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Kb_Revisions
 */
class Decker_Kb_Revisions {

	/**
	 * Register the hooks that keep the last editor up to date.
	 */
	public function __construct() {
		add_action( 'save_post_decker_kb', array( $this, 'track_last_editor' ), 10, 2 );
	}

	/**
	 * Track the last user who edited a KB article.
	 *
	 * Stores the current user ID as post meta so the UI can display
	 * the last editor instead of the original post author.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function track_last_editor( $post_id, $post ) {
		// Skip autosaves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$current_user_id = get_current_user_id();
		if ( $current_user_id && current_user_can( 'edit_post', $post_id ) ) {
			update_post_meta( $post_id, '_last_editor', $current_user_id );
		}
	}

	/**
	 * Get the last editor user ID for a KB article.
	 *
	 * Falls back to the post author if no last editor is recorded.
	 *
	 * @param int $post_id Post ID.
	 * @return int User ID of the last editor.
	 */
	public static function get_last_editor( $post_id ) {
		$last_editor = get_post_meta( $post_id, '_last_editor', true );
		if ( $last_editor ) {
			return intval( $last_editor );
		}
		$post = get_post( $post_id );
		return $post ? intval( $post->post_author ) : 0;
	}

	/**
	 * Get the latest revision ID for a KB article.
	 *
	 * @param int $post_id Post ID.
	 * @return int Revision ID or 0 when no revisions exist.
	 */
	public static function get_latest_revision_id( $post_id ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
			)
		);

		if ( empty( $revisions ) ) {
			return 0;
		}

		return self::extract_latest_revision_id_from_list( $revisions );
	}

	/**
	 * Get the WordPress revision history URL for a KB article.
	 *
	 * The WordPress revision screen includes the diff UI and restore actions.
	 *
	 * @param int $post_id Post ID.
	 * @return string Admin URL or empty string if no revision exists.
	 */
	public static function get_revision_admin_url( $post_id ) {
		return self::build_revision_admin_url(
			self::get_latest_revision_id( $post_id )
		);
	}

	/**
	 * Extract the latest revision ID from a revision list.
	 *
	 * @param array $revisions Revision list.
	 * @return int
	 */
	private static function extract_latest_revision_id_from_list( $revisions ) {
		if ( empty( $revisions ) ) {
			return 0;
		}

		$revision_list = array_values( $revisions );

		return isset( $revision_list[0]->ID ) ? intval( $revision_list[0]->ID ) : 0;
	}

	/**
	 * Build a revision admin URL from a revision ID.
	 *
	 * Public so a caller that already looked the revision up can reuse it
	 * instead of paying for a second query through get_revision_admin_url().
	 *
	 * @param int $revision_id Revision ID.
	 * @return string
	 */
	public static function build_revision_admin_url( $revision_id ) {
		if ( ! $revision_id ) {
			return '';
		}

		return admin_url(
			sprintf(
				'revision.php?revision=%d',
				intval( $revision_id )
			)
		);
	}
}
