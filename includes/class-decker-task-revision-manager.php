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
		if ( $this->creating_baseline || $post_id <= 0 ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'decker_task' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! post_type_supports( 'decker_task', 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
			return;
		}

		if ( $this->has_regular_revision( $post_id ) ) {
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
