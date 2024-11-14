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

	    // // Verificar si la solicitud tiene el encabezado 'Content-Type' igual a 'application/json'.
	    // $content_type = $request->get_header('content-type');
	    // if ( strpos( $content_type, 'application/json' ) === false ) {
	    //     return new WP_Error(
	    //         'invalid_content_type',
	    //         'This endpoint only accepts requests with Content-Type: application/json',
	    //         array( 'status' => 415 ) // 415 Unsupported Media Type
	    //     );
	    // }

	    $content_type = $request->get_header('content-type');

	    if ( strpos( $content_type, 'application/json' ) !== false ) {


			$email_data = $request->get_json_params();

			if ( ! $this->validate_sender( $email_data['from'] ) ) {
				return new WP_Error( 'forbidden', 'Unauthorized sender', array( 'status' => 403 ) );
			}

			$post_id = $this->process_email_data( $email_data );

    	} elseif ( strpos( $content_type, 'multipart/form-data' ) !== false ) {


		 	// Manejar solicitud multipart/form-data
	        $email_data = array(
	            'from' => sanitize_text_field( $request->get_param('from') ),
	            'to' => (array) $request->get_param('to'),
	            'subject' => sanitize_text_field( $request->get_param('subject') ),
	            'body' => sanitize_textarea_field( $request->get_param('body') ),
	            'headers' => $request->get_param('headers'),
	        );

	        // Manejo de archivos adjuntos
	        $files = $request->get_file_params();
	        if ( ! empty( $files['attachment'] ) ) {
	            $uploaded_file = $files['attachment'];
	            $email_data['attachments'] = array(
	                'filename' => sanitize_file_name( $uploaded_file['name'] ),
	                'content' => file_get_contents( $uploaded_file['tmp_name'] ),
	                'mimetype' => mime_content_type( $uploaded_file['tmp_name'] ),
	            );
	        }

	        // Validar y procesar datos del email
			$post_id = $this->process_email_data( $email_data );

	  	} else {
    	
    		return new WP_Error( 'unsupported_media_type', 'This endpoint only accepts application/json or multipart/form-data requests', array( 'status' => 415 ) );
		
		}

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
	 * Creates a temporary file from the given content.
	 *
	 * @param string $content The content to write to the temporary file.
	 * @return string The path to the created temporary file.
	 * @throws Exception If the temporary file cannot be created.
	 */
	private function create_temp_file( $content ) {
	    // Create a temporary file
	    $temp_file = tempnam(sys_get_temp_dir(), 'decker_temp_');

	    if ($temp_file === false) {
	        throw new Exception('Unable to create a temporary file.');
	    }

	    // Write the content to the temporary file
	    if (file_put_contents($temp_file, $content) === false) {
	        // Remove the temp file if writing fails
	        unlink($temp_file);
	        throw new Exception('Unable to write to the temporary file.');
	    }

	    // Return the path to the temporary file
	    return $temp_file;
	}



	/**
	 * Processes and uploads attachments as WordPress media.
	 *
	 * @param string $filename Name of the file.
	 * @param string $content  File content.
	 * @param int $post_id Linked post.
	 * @return int Attachment ID.
	 */
	private function upload_attachment( $filename, $content, $post_id ) {



	    try {
	        // Crear el archivo temporal y obtener su ruta
	        $tmp_file_path = $this->create_temp_file( $content );
	    } catch ( Exception $e ) {
	        return new WP_Error( 'temp_file_error', 'Error al crear archivo temporal: ' . $e->getMessage() );
	    }


	    // Determinar el tipo MIME utilizando la ruta del archivo temporal
	    $mime_type = mime_content_type( $tmp_file_path );

	    if ( !$mime_type ) {
	        // Eliminar el archivo temporal si no se puede determinar el tipo MIME
	        unlink( $tmp_file_path );
	        return new WP_Error( 'mime_type_error', 'No se pudo determinar el tipo MIME del archivo adjunto.' );
	    }



        // Generar un nonce para la verificación
        $_POST['nonce'] = wp_create_nonce('upload_attachment_nonce');

        // Simular una petición AJAX
        $_POST['task_id'] = $post_id;
        $_FILES['attachment'] = array(
            'name' => $filename,
		     'type'     => $mime_type,
        		'tmp_name' => $tmp_file_path,
            'error' => 0,
            'size' => strlen($content),
        );

        // Llamar a la función upload_task_attachment
        $decker_tasks = new Decker_Tasks();
        ob_start(); // Iniciar el buffer de salida para capturar la respuesta
        $decker_tasks->upload_task_attachment();
        $response = ob_get_clean(); // Obtener la respuesta y limpiar el buffer

        // Decodificar la respuesta JSON para obtener el attachment_id
        $response_data = json_decode($response, true);
        if (isset($response_data['success']) && $response_data['success']) {
            $attachment_id = $response_data['attachment_id'];
            return $attachment_id;
        } else {
            // Manejar el error si es necesario
            return new WP_Error('upload_error', 'Error al subir el adjunto.');
        }
      

		// $upload_dir = wp_upload_dir();
		// $path = $upload_dir['path'] . '/' . sanitize_file_name( $filename );

		// file_put_contents( $path, $content );

		// $filetype = wp_check_filetype( $filename );
		// $attachment = array(
		// 	'guid'           => $upload_dir['url'] . '/' . basename( $filename ),
		// 	'post_mime_type' => $filetype['type'],
		// 	'post_title'     => sanitize_file_name( $filename ),
		// 	'post_content'   => '',
		// 	'post_status'    => 'inherit',
		// );

		// $attach_id = wp_insert_attachment( $attachment, $path );
		// require_once ABSPATH . 'wp-admin/includes/image.php';
		// $attach_data = wp_generate_attachment_metadata( $attach_id, $path );
		// wp_update_attachment_metadata( $attach_id, $attach_data );

		// return $attach_id;
	}

	/**
	 * Creates a WordPress post from email data.
	 *
	 * @param array $email_data The email data.
	 * @return int ID of the created post.
	 */
	private function process_email_data( $email_data ) {
	

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

		// Optional handling of attachments if needed
		if ( ! empty( $email_data['attachments'] ) ) {
		    foreach ( $email_data['attachments'] as $filename => $content ) {
		        $this->upload_attachment( $filename, $content, $task_id );

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
		    $this->upload_attachment( $filename, $content, $task_id );
		}

		return $task_id;

	}
}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
