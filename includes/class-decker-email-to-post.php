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

        // Registrar el autoloader para MailMimeParser.
        $this->register_mailmimeparser_autoloader();

	}


	/**
     * Registra un autoloader PSR-4 simple para MailMimeParser.
     */
    private function register_mailmimeparser_autoloader() {
        spl_autoload_register(function ($class) {
            $prefix = 'ZBateson\\MailMimeParser\\';
            $base_dir = __DIR__ . '../admin/vendor/mail-mime-parser/src/';

            // Verificar si la clase utiliza el prefijo.
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                // No pertenece a MailMimeParser, saltar.
                return;
            }

            // Obtener el nombre relativo de la clase.
            $relative_class = substr($class, $len);

            // Reemplazar los separadores de namespace por directorios y agregar .php.
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            // Si el archivo existe, incluirlo.
            if (file_exists($file)) {
                require_once $file;
            }
        });
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

		    Decker_Utility_Functions::write_log( $shared_key , Decker_Utility_Functions::LOG_LEVEL_ERROR );
		    Decker_Utility_Functions::write_log( $this->shared_key , Decker_Utility_Functions::LOG_LEVEL_ERROR );


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





        // Instanciar MailMimeParser
        $mailParser = new MailMimeParser();

        // Parsear el correo electrónico
        try {
            $message = $mailParser->parse($email_data['body']);
        } catch ( Exception $e ) {
            Decker_Utility_Functions::write_log( 'Error al parsear el correo: ' . $e->getMessage(), Decker_Utility_Functions::LOG_LEVEL_ERROR );
            return new WP_Error( 'parse_error', 'No se pudo parsear el correo electrónico.', array( 'status' => 500 ) );
        }



        // Parsear el correo electrónico
        try {
            $message = $mailParser->parse($email_data['body']);
        } catch ( Exception $e ) {
            Decker_Utility_Functions::write_log( 'Error al parsear el correo: ' . $e->getMessage(), Decker_Utility_Functions::LOG_LEVEL_ERROR );
            return new WP_Error( 'parse_error', 'No se pudo parsear el correo electrónico.', array( 'status' => 500 ) );
        }


	 	// Get the author based on email
		$author = get_user_by( 'email', sanitize_email( $email_data['from'] ) );
		$owner = $author->ID;

		// Set task parameters
		$title = trim( sanitize_text_field( $email_data['subject'] ) );
		$description = sanitize_textarea_field( $email_data['body'] );

		$stack_title = 'to-do'; // Set stack title if needed

		// Retrieve the user's selected default board.
		$default_board = get_user_meta( $owner, 'decker_default_board', true );

		if ( empty( $default_board ) ||  !is_numeric( $default_board ) ) {
			Decker_Utility_Functions::write_log( 'Invalid user default board: "' . esc_html( $default_board ) . '".', Decker_Utility_Functions::LOG_LEVEL_ERROR );
			return new WP_Error( 'forbidden', 'Invalid user default board', array( 'status' => 403 ) );
		}	

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

		// Create or update the task using the Decker_Tasks function
		$task_id = Decker_Tasks::create_or_update_task(
		    0, // 0 indicates a new task
		    $title,
		    $description,
		    $stack_title,
		    $default_board,
		    false, // Placeholder for max_priority, adapt as necessary
		    $due_date,
		    $owner,
		    $assigned_users,
		    $label_ids,
		    new DateTime(), // Creation date as now or adapt as necessary
		    false,
		    0
		);

		// Optional handling of attachments if needed
		if ( !empty( $email_data['attachments'] ) ) {
		    foreach ( $email_data['attachments'] as $filename => $content ) {
		        $attach_id = $this->upload_attachment( $filename, $content );
		        if ( $attach_id ) {
		            add_post_meta( $task_id, '_email_attachment', $attach_id );
		        }
		    }
		}

		return $task_id;

	}
}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
