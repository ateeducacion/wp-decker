<?php
/**
 * Legacy baseline revision handling for Decker tasks.
 *
 * @package Decker
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates a lazy baseline revision before a legacy task is first updated.
 */
class Decker_Task_Revision_Manager {

	/**
	 * Whether this request is currently creating a baseline revision.
	 *
	 * @var bool
	 */
	private $creating_baseline = false;

	/**
	 * Register revision hooks.
	 */
	public function __construct() {
		add_action( 'pre_post_update', array( $this, 'save_legacy_baseline' ), 10, 2 );
	}

	/**
	 * Save the current task state before its first update after revision support.
	 *
	 * @param int   $post_id Post ID about to be updated.
	 * @param array $data    Unslashed post data that WordPress will write.
	 */
	public function save_legacy_baseline( $post_id, $data ) {
		unset( $data );

		$post_id = (int) $post_id;
		if ( ! $this->needs_baseline( $post_id ) ) {
			return;
		}

		$this->creating_baseline = true;
		$result                  = wp_save_post_revision( $post_id );
		$this->creating_baseline = false;

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'Decker could not save a baseline revision for task %1$d: %2$s',
					$post_id,
					$result->get_error_message()
				)
			);
		}
	}

	/**
	 * Determine whether a baseline revision must be created before this update.
	 *
	 * @param int $post_id Post ID about to be updated.
	 * @return bool True when the task needs a baseline revision.
	 */
	private function needs_baseline( int $post_id ): bool {
		if ( $this->creating_baseline || $post_id <= 0 ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		return $this->is_revisionable_task( get_post( $post_id ) ) && ! $this->has_regular_revision( $post_id );
	}

	/**
	 * Determine whether a post is a Decker task eligible for revisions.
	 *
	 * @param WP_Post|null $post Post about to be updated, if it exists.
	 * @return bool True when the post is a revisionable task.
	 */
	private function is_revisionable_task( $post ): bool {
		if ( ! $post || 'decker_task' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return false;
		}

		return post_type_supports( 'decker_task', 'revisions' ) && wp_revisions_enabled( $post );
	}

	/**
	 * Determine whether a task already has a regular, non-autosave revision.
	 *
	 * @param int $post_id Task post ID.
	 * @return bool True when a regular revision exists.
	 */
	private function has_regular_revision( int $post_id ): bool {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => -1,
			)
		);

		foreach ( $revisions as $revision ) {
			if ( ! wp_is_post_autosave( $revision ) ) {
				return true;
			}
		}

		return false;
	}
}
