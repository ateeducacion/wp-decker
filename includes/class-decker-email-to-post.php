<?php
/**
 * File class-decker-email-to-post
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class to handle the creation of posts from emails in the Decker plugin.
 */
class Decker_Email_To_Post {

	/**
	 * Initializes the class and registers the endpoint.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Registers the REST API endpoint to process the email.
	 */
	public function register_endpoint() {
		register_rest_route(
			'decker/v1',
			'/email-to-post',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_email' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Check if the request has valid authorization.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool Whether the request has valid authorization.
	 */
	public function check_permission( $request ) {
		$auth_header = $request->get_header( 'authorization' );

		if ( ! $auth_header ) {
			return new WP_Error( 'rest_forbidden', __( 'Access denied' ), array( 'status' => 403 ) );
		}

		if ( ! $this->validate_authorization( $auth_header ) ) {

			return new WP_Error( 'rest_forbidden', __( 'Access denied' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Extract email from a string that can contain name and e-mail.
	 *
	 * @param string $email Email with name.
	 * @return string.
	 */
	private function extract_email( $email ) {
		// Verificar si la cadena contiene un formato "Nombre <email@example.com>".
		if ( preg_match( '/<([^>]+)>/', $email, $matches ) ) {
			return sanitize_email( $matches[1] );
		}

		// Si no hay corchetes, asumir que contiene solo el email.
		return sanitize_email( $email );
	}

	/**
	 * Gets the body of the email.
	 *
	 * @param Erseco\Message $message The Message instance.
	 * @return string The sanitized email body.
	 */
	public function get_body( Erseco\Message $message ): string {

		// print_r($message);

	    // Attempt to get the parts of the message.
	    $parts = $message->getParts();

	    if (count($parts) > 0) {
	        $content = $parts[0]->getContent();
	        $contentType = $parts[0]->getContentType();

	        if (str_starts_with(strtolower($contentType), 'text/plain;')) {
	            // Convert plain text to HTML for better readability.
	            return wp_kses_post(nl2br(esc_html($content)));
	        } else {
	            // Sanitize and return the HTML content.
	            return wp_kses_post($content);
	        }


	    } else {


	    	error_log("ERRORAZOOOOOOO");

	    }

	    // If no parts are available, return an empty string.
	    return '';

	}

	/**
	 * Callback to process the received email and create a post.
	 *
	 * @param WP_REST_Request $request The REST request data.
	 * @return WP_REST_Response|WP_Error.
	 */
	public function process_email( WP_REST_Request $request ) {

		// Get and validate payload.
		$payload = $request->get_json_params();
		if ( ! isset( $payload['rawEmail'] ) || empty( $payload['metadata'] ) ) {
			return new WP_Error( 'invalid_payload', 'Invalid email payload', array( 'status' => 400 ) );
		}

		try {

			// Parse email.

			require_once __DIR__ . '/../admin/vendor/mime-mail-parser/src/MimeMailParser.php';
			// $message = new Erseco\Message( $payload['rawEmail'] );


			$message = Erseco\Message::fromString($payload['rawEmail']);
			print_r($message->getParts());

			error_log( '------------dfsdfIIIIIIIIIIIIIIIIIII------' );

			if ( is_wp_error( $message ) ) {
				return $message;
			}

			// Extract email content.
			$email_data = array(
				'from'        => $payload['metadata']['from'],
				'to'          => $payload['metadata']['to'],
				'cc'          => $payload['metadata']['cc'],
				'bcc'         => $payload['metadata']['bcc'],
				'subject'     => $payload['metadata']['subject'],
				'body'        => $this->get_body( $message ),
				'attachments' => $message->getAttachments(),
			);

			// Validate sender.
			$author = $this->get_author( $email_data['from'] );
			if ( is_wp_error( $author ) ) {
				return $author;
			}

			$assigned_users = $this->get_assigned_users( $email_data );
			if ( empty( $assigned_users ) ) {
				$assigned_users[] = $author->ID;
			}

			// Temporarily set current user.
			wp_set_current_user( $author->ID );

			// Create task.
			$task_id = $this->create_task( $email_data, $author, $assigned_users );
			if ( is_wp_error( $task_id ) ) {
				return $task_id;
			}

			// Handle attachments.
			$attachments = $message->getAttachments();
			if ( ! empty( $attachments ) ) {
				$this->upload_task_attachments( $attachments, $task_id );
			}

			// Reset user.
			wp_set_current_user( 0 );

			return rest_ensure_response(
				array(
					'status'  => 'success',
					'task_id' => $task_id,
				)
			);

		} catch ( Exception $e ) {

			error_log( $e->getMessage() );

			return new WP_Error( 'processing_error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Validates if the sender is a registered WordPress user.
	 *
	 * @param string $email The sender's email address.
	 * @return bool True if the sender is valid, false otherwise.
	 */
	private function validate_sender( $email ) {
		return false !== get_user_by( 'email', $email );
	}

	/**
	 * Parses raw email content with support for multipart and different encodings.
	 *
	 * @param string $raw_email The raw e-mail data.
	 * @return Message.
	 */
	private function parse_email( $raw_email ) {

		// Parse raw email.


		return $message;
	}

	/**
	 * Processes and uploads attachments as WordPress media.
	 *
	 * @param string $filename Name of the file.
	 * @param string $content  File content.
	 * @param string $type MIME type of the file.
	 * @param int    $post_id Linked post.
	 * @return int Attachment ID.
	 */
	private function upload_attachment( $filename, $content, $type, $post_id ) {
		// Verificar permisos y datos necesarios.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'permission_error', 'No tienes permisos para subir archivos.' );
		}

		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', 'ID de post inválido.' );
		}

		// Extraer solo el tipo MIME sin parámetros adicionales.
		$type = explode( ';', $type )[0];

		// Crear un nombre de archivo único.
		$original_filename = sanitize_file_name( $filename );
		$extension         = pathinfo( $filename, PATHINFO_EXTENSION );
		$upload_dir        = wp_upload_dir();

		// Generar nombre único para el archivo usando la función nativa de WordPress.
		$obfuscated_name = wp_unique_filename(
			$upload_dir['path'],
			wp_generate_uuid4() . '.' . $extension
		);

		// Construir la ruta completa del archivo.
		$file_path = $upload_dir['path'] . '/' . $obfuscated_name;

		// Initialize WordPress Filesystem.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Write content using WP_Filesystem.
		if ( ! $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'file_write_error', 'Error al escribir el archivo.' );
		}

		// Preparar el array de información del adjunto.
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . $obfuscated_name,
			'post_mime_type' => $type,
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $original_filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,  // Establecer el post parent.
		);

		// Insertar el adjunto en la base de datos.
		$attachment_id = wp_insert_attachment( $attachment, $file_path, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

		// Generar metadatos del adjunto.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		// Guardar el nombre original en los metadatos.
		update_post_meta( $attachment_id, '_original_filename', $original_filename );

		return $attachment_id;
	}

	/**
	 * Validates the authorization header for API access.
	 *
	 * @param string $auth_header The authorization header to validate.
	 * @return bool True if the authorization is valid, false otherwise.
	 */
	private function validate_authorization( $auth_header ) {

		// Retrieve options and set the shared key.
		$options = get_option( 'decker_settings', array() );
		$shared_key = isset( $options['shared_key'] ) ? sanitize_text_field( $options['shared_key'] ) : 'error';

		return $auth_header && 0 === strpos( $auth_header, 'Bearer ' ) && hash_equals( $shared_key, substr( $auth_header, 7 ) );
	}

	/**
	 * Retrieves the author associated with the given email address.
	 *
	 * @param string $email The email address to look up.
	 * @return WP_User|WP_Error The user object if found, or a WP_Error if not.
	 */
	private function get_author( $email ) {
		$author = get_user_by( 'email', $this->extract_email( $email ) );
		if ( ! $author ) {
			return new WP_Error( 'invalid_author', 'Sender not associated with any user' );
		}
		return $author;
	}

	/**
	 * Retrieves the list of assigned users based on email data.
	 *
	 * @param array $email_data An associative array containing 'to', 'cc', and 'bcc' fields.
	 * @return array An array of user IDs for the assigned users.
	 */
	private function get_assigned_users( array $email_data ) {

		$assigned_users = array();

		// Add users from 'TO', 'CC' and 'BCC' fields if they exist in WordPress.
		$to_addresses    = ! empty( $email_data['to'] ) ? $email_data['to'] : array();
		$cc_addresses    = ! empty( $email_data['cc'] ) ? $email_data['cc'] : array();
		$bcc_addresses   = ! empty( $email_data['bcc'] ) ? $email_data['bcc'] : array();
		$emails_to_check = array_merge( (array) $to_addresses, (array) $cc_addresses, (array) $bcc_addresses );

		foreach ( $emails_to_check as $email ) {
			$user = get_user_by( 'email', $this->extract_email( $email ) );
			if ( $user ) {
				$assigned_users[] = $user->ID;
			}
		}

		// Ensure unique user IDs in assigned users list.
		$assigned_users = array_unique( $assigned_users );

		return $assigned_users;
	}

	/**
	 * Creates a new task based on the provided email data.
	 *
	 * @param array   $email_data     An associative array containing task details.
	 * @param WP_User $author         The author of the task.
	 * @param array   $assigned_users An array of user IDs to assign the task to.
	 * @return int|WP_Error The task ID if successful, or a WP_Error on failure.
	 */
	private function create_task( $email_data, $author, $assigned_users ) {
		// Get default board.
		$default_board = (int) get_user_meta( $author->ID, 'decker_default_board', true );
		if ( $default_board <= 0 || ! term_exists( $default_board, 'decker_board' ) ) {
			return new WP_Error( 'invalid_board', 'Invalid default board' );
		}

		// Set task parameters.
		$due_date = new DateTime( '+3 days' );

		// Create task.
		$task_id = Decker_Tasks::create_or_update_task(
			0,
			$email_data['subject'],
			$email_data['body'],
			'to-do',
			$default_board,
			false,
			$due_date,
			$author->ID,
			$assigned_users,
			array(),
			new DateTime(),
			false,
			0
		);

		return $task_id;
	}

	/**
	 * Uploads attachments for a task.
	 *
	 * @param array $attachments An array of attachments, each containing 'filename', 'content', and 'mimetype'.
	 * @param int   $task_id     The ID of the task to associate the attachments with.
	 */
	private function upload_task_attachments( $attachments, $task_id ) {
		foreach ( $attachments as $attachment ) {
			try {
				$result = $this->upload_attachment(
					$attachment['filename'],
					$attachment['content'],
					$attachment['mimetype'],
					$task_id
				);
				if ( is_wp_error( $result ) ) {
					error_log(
						"Error uploading attachment {$attachment['filename']}: " . $result->get_error_message()
					);
				}
			} catch ( Exception $e ) {
				error_log(
					"Exception processing attachment {$attachment['filename']}: " . $e->getMessage()
				);
			}
		}
	}
}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
