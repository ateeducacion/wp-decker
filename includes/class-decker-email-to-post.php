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

		    // Decker_Utility_Functions::write_log( $shared_key , Decker_Utility_Functions::LOG_LEVEL_ERROR );
		    // Decker_Utility_Functions::write_log( $this->shared_key , Decker_Utility_Functions::LOG_LEVEL_ERROR );

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
	

        // Verificar si 'body' está presente
        if ( empty( $email_data['body'] ) ) {
            return new WP_Error( 'invalid_email', 'El contenido del correo está vacío.', array( 'status' => 400 ) );
        }

	    // Requiere la clase del Mail Parser.
	    require_once __DIR__ . '/../admin/vendor/mail-parser/src/MessagePart.php';
	    require_once __DIR__ . '/../admin/vendor/mail-parser/src/Message.php';

	    // Instanciar y parsear el correo electrónico.
	    try {
	        $message = Opcodes\MailParser\Message::fromString($email_data['body']);
	    } catch (Exception $e) {
	        Decker_Utility_Functions::write_log('Error al parsear el correo: ' . $e->getMessage(), Decker_Utility_Functions::LOG_LEVEL_ERROR);
	        return new WP_Error('parse_error', 'No se pudo parsear el correo electrónico.', array('status' => 500));
	    }

	 	// Get the author based on email
		$author = get_user_by( 'email', sanitize_email( $email_data['from'] ) );

		// Set task parameters
		$title = trim( sanitize_text_field( $email_data['subject'] ) );

		// Obtener el contenido HTML si está disponible, o el texto plano si no lo está.
		$body_html = $message->getHtmlPart()?->getContent() ?? '';
		$body_text = $message->getTextPart()?->getContent() ?? '';
		$body = !empty($body_html) ? $body_html : (!empty($body_text) ? $body_text : $email_data['body']);

		$stack_title = 'to-do'; // Set stack title if needed

		// Retrieve the user's selected default board.
		$default_board = (int) get_user_meta( $author->ID, 'decker_default_board', true );

		if ($default_board <= 0) {
			Decker_Utility_Functions::write_log( 'Invalid user default board: "' . esc_html( $default_board ) . '".', Decker_Utility_Functions::LOG_LEVEL_ERROR );
			return new WP_Error( 'forbidden', 'Invalid user default board', array( 'status' => 403 ) );
		}	

		// Decker_Utility_Functions::write_log( "------", Decker_Utility_Functions::LOG_LEVEL_ERROR );
		// Decker_Utility_Functions::write_log( $default_board, Decker_Utility_Functions::LOG_LEVEL_ERROR );


		$label_ids = []; // Set based on your logic to categorize tasks
		$assigned_users = [];
	    
	    // Add users from 'TO', 'CC' and 'BCC' fields if they exist in WordPress
	    $to_addresses = !empty($email_data['to']) ? $email_data['to'] : [];
	    $cc_addresses = !empty($email_data['cc']) ? $email_data['cc'] : [];
	    $bcc_addresses = !empty($email_data['bcc']) ? $email_data['bcc'] : [];
	    $emails_to_check = array_merge( (array) $to_addresses, (array) $cc_addresses, (array) $bcc_addresses );

	    foreach ( $emails_to_check as $email ) {
	        $user = get_user_by( 'email', sanitize_email( $email ) );
	        if ( $user ) {
	            $assigned_users[] = $user->ID;
	        }
	    }

	    // Ensure unique user IDs in assigned users list
	    $assigned_users = array_unique( $assigned_users );


	    // Set due date to 3 days from now
	    $due_date = new DateTime();
	    $due_date->modify('+3 days');

	    // Temporarily set the current user to the mail sent user, because the WP Rest user (0) doesn't have the required capabilities
		wp_set_current_user( $author->ID );

		// Create or update the task using the Decker_Tasks function
		$task_id = Decker_Tasks::create_or_update_task(
		    0, // 0 indicates a new task
		    $title,
		    $body,
		    $stack_title,
		    $default_board,
		    false, // Placeholder for max_priority, adapt as necessary
		    $due_date,
		    $author->ID,
		    $assigned_users,
		    $label_ids,
		    new DateTime(), // Creation date as now or adapt as necessary
		    false,
		    0
		);

	    // Reset the user context
	    wp_set_current_user( 0 );


	    $attachment_ids = [];

		// Optional handling of attachments if needed
		if ( ! empty( $email_data['attachments'] ) ) {
		    foreach ( $email_data['attachments'] as $filename => $content ) {
		        $attach_id = $this->upload_attachment( $filename, $content, $task_id );
		        if ( $attach_id ) {
		            $attachment_ids[] = $attach_id;
		        }
		    }
		}

		// Obtener los adjuntos del mensaje parseado
		$attachments = $message->getAttachments(); // Devuelve un array de MessagePart que representan los adjuntos.

		foreach ( $attachments as $attachment ) {
		    // Obtener el nombre del archivo adjunto
		    $filename = $attachment->getFilename();

		    // Obtener el contenido del archivo adjunto
		    $content = $attachment->getContent();

		    // Subir el adjunto a la biblioteca multimedia
		    $attach_id = $this->upload_attachment( $filename, $content, $task_id );
		    if ( $attach_id ) {
		        $attachment_ids[] = $attach_id;
		    }
		}

		// Si hay IDs de adjuntos, actualizar el meta 'attachments'
		if ( ! empty( $attachment_ids ) ) {
		    // Obtener adjuntos existentes (si los hay)
		    $existing_attachments = get_post_meta( $task_id, 'attachments', true );
		    $existing_attachments = is_array( $existing_attachments ) ? $existing_attachments : [];

		    // Combinar y asegurar que los IDs sean únicos
		    $all_attachments = array_unique( array_merge( $existing_attachments, $attachment_ids ) );

		    // Actualizar el meta 'attachments'
		    update_post_meta( $task_id, 'attachments', $all_attachments );
		}

		return $task_id;

	}
}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
