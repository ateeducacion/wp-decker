<?php
/**
 * Admin save_post path for tasks.
 *
 * Owns the decker_task meta-saving path triggered by the classic editor's
 * save_post hook: the nonce/permission/lock guard, and writing the detail
 * fields, taxonomies, assigned users and user-date relations meta. Kept
 * apart from the AJAX save path (Decker_Task_Ajax_Save) and the shared
 * post type registration in Decker_Tasks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Meta_Saver
 */
class Decker_Task_Meta_Saver {

	/**
	 * The task post type coordinator.
	 *
	 * @var Decker_Tasks
	 */
	private $tasks;

	/**
	 * Hook the save_post handler.
	 *
	 * @param Decker_Tasks $tasks The task post type coordinator.
	 */
	public function __construct( Decker_Tasks $tasks ) {
		$this->tasks = $tasks;

		add_action( 'save_post', array( $this, 'save_meta' ), 10, 3 );
	}

	/**
	 * Save the custom meta fields.
	 *
	 * @param int     $post_id The current post ID.
	 * @param WP_Post $post The current post.
	 * @param bool    $update If we are updating.
	 */
	public function save_meta( $post_id, $post, $update ) {

		// Bail out early when the request must not modify task meta.
		if ( ! $this->can_save_task_meta( $post_id ) ) {
			return $post_id;
		}

		// Enforce the edit lock so a stale admin session cannot overwrite newer
		// changes after another user has taken over editing.
		if ( is_wp_error( $this->tasks->get_task_locks()->assert_user_can_save( $post_id, get_current_user_id() ) ) ) {
			return $post_id;
		}

		// The order of these calls is load-bearing: writing the 'stack' meta and the
		// 'decker_board' term trigger reorder hooks mid-save, so details must run
		// before taxonomies, and both before users and relations.
		$this->save_task_detail_fields( $post_id );
		$this->save_task_taxonomies( $post_id );
		$this->save_task_assigned_users( $post_id );
		$this->save_task_user_date_relations( $post_id );
	}

	/**
	 * Determine whether the current request is allowed to save task meta.
	 *
	 * @param int $post_id The current post ID.
	 * @return bool True when the meta may be saved, false otherwise.
	 */
	private function can_save_task_meta( int $post_id ): bool {
		// Check if nonce is set and verified.
		if ( ! isset( $_POST['decker_task_nonce'] ) ) {
			return false; // Exit if the nonce is not set.
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['decker_task_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'save_decker_task' ) ) {
			return false; // Exit if the nonce verification fails.
		}

		// Check autosave and post type.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( ! isset( $_POST['post_type'] ) || 'decker_task' !== $_POST['post_type'] ) {
			return false;
		}

		// Check the user's permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// Prevent changes if the task is archived.
		if ( 'archived' === get_post_status( $post_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Save the task detail meta fields (duedate, max_priority, stack, nextcloud card).
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_detail_fields( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['duedate'] ) ) {
			$duedate = sanitize_text_field( wp_unslash( $_POST['duedate'] ) );
			update_post_meta( $post_id, 'duedate', $duedate );
		}
		$max_priority = isset( $_POST['max_priority'] ) ? '1' : '';
		update_post_meta( $post_id, 'max_priority', $max_priority );
		if ( isset( $_POST['stack'] ) ) {
			$stack = sanitize_text_field( wp_unslash( $_POST['stack'] ) );
			update_post_meta( $post_id, 'stack', $stack );
		}
		if ( isset( $_POST['id_nextcloud_card'] ) ) {
			update_post_meta( $post_id, 'id_nextcloud_card', sanitize_text_field( wp_unslash( $_POST['id_nextcloud_card'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save the task taxonomies (labels first, then board), both as slugs.
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_taxonomies( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['decker_labels'] ) ) {
			$labels      = array_map( 'sanitize_text_field', wp_unslash( $_POST['decker_labels'] ) );
			$label_slugs = array();
			foreach ( $labels as $label_id ) {
				$term = get_term( $label_id, 'decker_label' );
				if ( $term && ! is_wp_error( $term ) ) {
					$label_slugs[] = $term->slug;
				}
			}
			wp_set_post_terms( $post_id, $label_slugs, 'decker_label' );
		}
		if ( isset( $_POST['decker_board'] ) ) {
			$board_id   = sanitize_text_field( wp_unslash( $_POST['decker_board'] ) );
			$board_term = get_term( $board_id, 'decker_board' );
			if ( $board_term && ! is_wp_error( $board_term ) ) {
				wp_set_post_terms( $post_id, array( $board_term->slug ), 'decker_board' );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Save the assigned users meta when posted.
	 *
	 * The nonce has already been verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_assigned_users( int $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['assigned_users'] ) ) {
			$assigned_users = array_map( 'intval', wp_unslash( $_POST['assigned_users'] ) );
			update_post_meta( $post_id, 'assigned_users', $assigned_users );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Decode and unconditionally save the user-date relations meta.
	 *
	 * The relations meta is always written (an empty array when the field is
	 * absent) so previous relations are cleared. The nonce has already been
	 * verified by can_save_task_meta().
	 *
	 * @param int $post_id The current post ID.
	 */
	private function save_task_user_date_relations( int $post_id ) {
		// Save user date relations.
		$relations = array();

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['user_date_relations'] ) ) {
			// Remove slashes added by WordPress.
			$relations_json = sanitize_text_field( wp_unslash( $_POST['user_date_relations'] ) );

			// Decode the JSON using PHP's json_decode function.
			$decoded_relations = json_decode( $relations_json, true );

			// Verify that the decoding returned a valid array.
			if ( is_array( $decoded_relations ) ) {
				$relations = $decoded_relations;
			} else {
				// Handle JSON decoding errors if necessary.
				// You can log the error or display a message.
				error_log( 'JSON decoding failed: ' . json_last_error_msg() );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_post_meta( $post_id, '_user_date_relations', $relations );
	}
}
