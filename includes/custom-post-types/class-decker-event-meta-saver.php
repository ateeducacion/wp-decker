<?php
/**
 * Persists event meta from a form submission.
 *
 * Owns the save_post hook for events and the whole pipeline behind it: the
 * all-day flag, the date normalization rules for all-day and timed events,
 * the optional text fields and the assigned users. Decker_Events keeps thin
 * public delegators so existing callers and tests keep their entry point.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Event_Meta_Saver
 */
class Decker_Event_Meta_Saver {

	/**
	 * Hook the admin-form save.
	 */
	public function __construct() {
		add_action( 'save_post_decker_event', array( $this, 'save_event_meta' ), 10, 3 );
	}

	/**
	 * Process and save meta data from a data array.
	 * This is the core logic, decoupled from $_POST for testability.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $data    The data to save (e.g., from $_POST).
	 */
	public function process_and_save_meta( $post_id, $data ) {
		Decker_Event_Store::web_meta( $post_id, wp_unslash( $data ) );
	}

	/**
	 * Save event meta data from admin form submission.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated or not.
	 */
	public function save_event_meta( $post_id, $post, $update ) {
		// Check nonce, user permissions, and autosave.
		if ( ! isset( $_POST['decker_event_meta_box_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['decker_event_meta_box_nonce'] ), 'decker_event_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call the main logic function with the $_POST data.
		try {
			$this->process_and_save_meta( $post_id, $_POST );
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'Invalid calendar dates.', 'decker' ), '', array( 'response' => 400 ) );
		}
	}
}
