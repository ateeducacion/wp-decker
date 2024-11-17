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

		error_log("hola");
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


    // Función para extraer el email de una cadena que puede contener nombre y correo
    private function extract_email( $email ) {
        // Verificar si la cadena contiene un formato "Nombre <email@example.com>"
        if ( preg_match( '/<([^>]+)>/', $email, $matches ) ) {
            return sanitize_email( $matches[1] );
        }

        // Si no hay corchetes, asumir que contiene solo el email
        return sanitize_email( $email );
    }

	/**
	 * Callback to process the received email and create a post.
	 *
	 * @param WP_REST_Request $request The REST request data.
	 * @return WP_REST_Response|WP_Error
	 */
	public function process_email( WP_REST_Request $request ) {

		error_log("paso 1");


        // Validate authorization
        $auth_header = $request->get_header('authorization');
        if (!$this->validate_authorization($auth_header)) {
            return new WP_Error('forbidden', 'Access denied', array('status' => 403));
        }

		error_log("paso 2");

	    // Get and validate payload
        $payload = $request->get_json_params();
        if (!isset($payload['rawEmail']) || empty($payload['metadata'])) {
		    Decker_Utility_Functions::write_log('Invalid email payload', Decker_Utility_Functions::LOG_LEVEL_ERROR);        	
            return new WP_Error('invalid_payload', 'Invalid email payload', array('status' => 400));
        }



		error_log("paso 3");

	    // // Verificar si la solicitud tiene el encabezado 'Content-Type' igual a 'application/json'.
	    // $content_type = $request->get_header('content-type');
	    // if ( strpos( $content_type, 'application/json' ) === false ) {
	    //     return new WP_Error(
	    //         'invalid_content_type',
	    //         'This endpoint only accepts requests with Content-Type: application/json',
	    //         array( 'status' => 415 ) // 415 Unsupported Media Type
	    //     );
	    // }


	 	try {

			error_log("El body raw:");

            // Parse email
            $message = $this->parse_email($payload['rawEmail']);
            if (is_wp_error($message)) {
                return $message;
            }

            // Extract email content
            $email_data = array(
                'from' => $payload['metadata']['from'],
                'to' => $payload['metadata']['to'],
                'cc' => $payload['metadata']['cc'],
                'bcc' => $payload['metadata']['bcc'],
                'subject' => $payload['metadata']['subject'],
                'body' => $message->getBody(),
                'attachments' => $message->getAttachments(),
            );




            // error_log("El body tiene:");
            // error_log($email_data['body']);

            // Validate sender
            $author = $this->get_author($email_data['from']);
            if (is_wp_error($author)) {
                return $author;
            }

			$assigned_users = $this->get_assigned_users($email_data);
			if (empty($assigned_users)) {
			    $assigned_users[] = $author->ID;
			}

            error_log("creando tarea");

	        // Temporarily set current user
	        wp_set_current_user($author->ID);

            // Create task
            $task_id = $this->create_task($email_data, $author, $assigned_users);
            if (is_wp_error($task_id)) {
                return $task_id;
            }

            error_log("Creada tarea");

            // Handle attachments
            $attachments = $message->getAttachments();
            if (!empty($attachments)) {
            	error_log("Subiendo adjuntos");
                $this->upload_task_attachments($attachments, $task_id);
            }

	        // Reset user
	        wp_set_current_user(0);
	        

            return rest_ensure_response(array(
                'status' => 'success',
                'task_id' => $task_id
            ));

        } catch (Exception $e) {
            return new WP_Error('processing_error', $e->getMessage(), array('status' => 500));
        }

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
     * Parses raw email content with support for multipart and different encodings
     */
    private function parse_email($rawEmail) {

		// Parse raw email
		require_once __DIR__ . '/class-decker-email-parser.php';

	 	// Debug: Log the first part of the raw email
        // error_log("First 1000 chars of raw email: " . substr($rawEmail, 0, 1000));


		$message = new Decker_Email_Parser($rawEmail);

		return $message;

    }


	/**
	 * Processes and uploads attachments as WordPress media.
	 *
	 * @param string $filename Name of the file.
	 * @param string $content  File content.
	 * @param string $type MIME type of the file
	 * @param int $post_id Linked post.
	 * @return int Attachment ID.
	 */
	private function upload_attachment($filename, $content, $type, $post_id) {
	    // Verificar permisos y datos necesarios
	    if (!current_user_can('upload_files')) {
	        return new WP_Error('permission_error', 'No tienes permisos para subir archivos.');
	    }

	    if (!$post_id) {
	        return new WP_Error('invalid_post', 'ID de post inválido.');
	    }


	    // Extraer solo el tipo MIME sin parámetros adicionales
	    $type = explode(';', $type)[0];

	    // Crear un nombre de archivo único
	    $original_filename = sanitize_file_name($filename);
	    $extension = pathinfo($filename, PATHINFO_EXTENSION);
	    $upload_dir = wp_upload_dir();
	    
	    // Generar nombre único para el archivo usando la función nativa de WordPress
	    $obfuscated_name = wp_unique_filename(
	        $upload_dir['path'], 
	        wp_generate_uuid4() . '.' . $extension
	    );

	    // Construir la ruta completa del archivo
	    $file_path = $upload_dir['path'] . '/' . $obfuscated_name;

	    // Escribir el contenido directamente en el directorio de uploads
	    if (file_put_contents($file_path, $content) === false) {
	        return new WP_Error('file_write_error', 'Error al escribir el archivo.');
	    }

	    // Preparar el array de información del adjunto
	    $attachment = array(
	        'guid'           => $upload_dir['url'] . '/' . $obfuscated_name,
	        'post_mime_type' => $type,
	        'post_title'     => preg_replace('/\.[^.]+$/', '', $original_filename),
	        'post_content'   => '',
	        'post_status'    => 'inherit',
	        'post_parent'    => $post_id  // Establecer el post parent
	    );

	    // Insertar el adjunto en la base de datos
	    $attachment_id = wp_insert_attachment($attachment, $file_path, $post_id);

	    if (is_wp_error($attachment_id)) {
	        @unlink($file_path);
	        return $attachment_id;
	    }

	    // Generar metadatos del adjunto
	    require_once ABSPATH . 'wp-admin/includes/image.php';
	    $attachment_data = wp_generate_attachment_metadata($attachment_id, $file_path);
	    wp_update_attachment_metadata($attachment_id, $attachment_data);

	    // Guardar el nombre original en los metadatos
	    update_post_meta($attachment_id, '_original_filename', $original_filename);

	    return $attachment_id;
	}

    private function validate_authorization($auth_header) {
        return $auth_header && 
               strpos($auth_header, 'Bearer ') === 0 && 
               hash_equals($this->shared_key, substr($auth_header, 7));
    }

    private function get_author($email) {
        $author = get_user_by('email', $this->extract_email($email));
        if (!$author) {
            return new WP_Error('invalid_author', 'Sender not associated with any user');
        }
        return $author;
    }

    private function get_assigned_users(array $email_data) {

		$assigned_users = [];
	    
	    // Add users from 'TO', 'CC' and 'BCC' fields if they exist in WordPress
	    $to_addresses = !empty($email_data['to']) ? $email_data['to'] : [];
	    $cc_addresses = !empty($email_data['cc']) ? $email_data['cc'] : [];
	    $bcc_addresses = !empty($email_data['bcc']) ? $email_data['bcc'] : [];
	    $emails_to_check = array_merge( (array) $to_addresses, (array) $cc_addresses, (array) $bcc_addresses );

	    foreach ( $emails_to_check as $email ) {
	        $user = get_user_by( 'email', $this->extract_email( $email ) );
	        if ( $user ) {
	            $assigned_users[] = $user->ID;
	        }
	    }

	    // Ensure unique user IDs in assigned users list
	    $assigned_users = array_unique( $assigned_users );

	    return $assigned_users;

    }

    private function create_task($email_data, $author, $assigned_users) {
        // Get default board
        $default_board = (int) get_user_meta($author->ID, 'decker_default_board', true);
        if ($default_board <= 0 || !term_exists($default_board, 'decker_board')) {
            return new WP_Error('invalid_board', 'Invalid default board');
        }

        // Set task parameters
        $due_date = new DateTime('+3 days');
                
        // Create task
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
            [],
            new DateTime(),
            false,
            0
        );
        
        return $task_id;
    }

    private function upload_task_attachments($attachments, $task_id) {
        foreach ($attachments as $attachment) {
            try {
                $result = $this->upload_attachment(
                    $attachment['filename'],
                    $attachment['content'],
                    $attachment['mimetype'],
                    $task_id
                );
                if (is_wp_error($result)) {
                    Decker_Utility_Functions::write_log(
                        "Error uploading attachment {$attachment['filename']}: " . $result->get_error_message(),
                        Decker_Utility_Functions::LOG_LEVEL_ERROR
                    );
                }
            } catch (Exception $e) {
                Decker_Utility_Functions::write_log(
                    "Exception processing attachment {$attachment['filename']}: " . $e->getMessage(),
                    Decker_Utility_Functions::LOG_LEVEL_ERROR
                );
            }
        }
    }

}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
