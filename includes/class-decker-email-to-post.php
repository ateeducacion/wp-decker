<?php
/**
 * Class to handle the creation of posts from emails in the Decker plugin.
 */
class Decker_Email_To_Post {

	/**
	 * Shared key for securing the endpoint.
	 *
	 * @var string
	 */
	private string $shared_key;

	/**
	 * Initializes the class and registers the endpoint.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );

		// Retrieve options and set the shared key.
		$options = get_option( 'decker_settings', array() );
		$this->shared_key = isset( $options['shared_key'] ) ? sanitize_text_field( $options['shared_key'] ) : '';
	}

	/**
	 * Registers the REST API endpoint to process the email.
	 */
	public function register_endpoint() {
		register_rest_route(
			'decker/v1',
			'/email-to-post',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'process_email' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Callback to process the received email and create a post.
	 *
	 * @param WP_REST_Request $request The REST request data.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_email( WP_REST_Request $request ) {
		$shared_key = $request->get_param( 'shared_key' );
		if ( $shared_key !== $this->shared_key ) {
			return new WP_Error( 'forbidden', 'Invalid access key', array( 'status' => 403 ) );
		}

		$email_data = $request->get_json_params();

		if ( ! $this->validate_sender( $email_data['from'] ) ) {
			return new WP_Error( 'forbidden', 'Unauthorized sender', array( 'status' => 403 ) );
		}

		$post_id = $this->create_post_from_email( $email_data );

		return rest_ensure_response(
			array(
				'status'  => 'success',
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Validates if the sender is a registered WordPress user.
	 *
	 * @param string $email The sender's email address.
	 * @return bool True if the sender is valid, false otherwise.
	 */
	private function validate_sender( $email ) {
		return get_user_by( 'email', $email ) !== false;
	}

	/**
	 * Processes and uploads attachments as WordPress media.
	 *
	 * @param string $filename Name of the file.
	 * @param string $content  File content.
	 * @return int Attachment ID.
	 */
	private function upload_attachment( $filename, $content ) {
		$upload_dir = wp_upload_dir();
		$path = $upload_dir['path'] . '/' . sanitize_file_name( $filename );

		file_put_contents( $path, $content );

		$filetype = wp_check_filetype( $filename );
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . basename( $filename ),
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $path );
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $path );
		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	/**
	 * Creates a WordPress post from email data.
	 *
	 * @param array $email_data The email data.
	 * @return int ID of the created post.
	 */
	private function create_post_from_email( $email_data ) {
		$author = get_user_by( 'email', sanitize_email( $email_data['from'] ) );
		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $email_data['subject'] ),
				'post_content' => sanitize_textarea_field( $email_data['body'] ),
				'post_status'  => 'publish',
				'post_author'  => $author ? $author->ID : 0,
			)
		);

		if ( ! empty( $email_data['attachments'] ) ) {
			foreach ( $email_data['attachments'] as $filename => $content ) {
				$attach_id = $this->upload_attachment( $filename, $content );
				if ( $attach_id ) {
					add_post_meta( $post_id, '_email_attachment', $attach_id );
				}
			}
		}

		return $post_id;
	}
}
