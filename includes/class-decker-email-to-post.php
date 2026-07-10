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
			return new WP_Error( 'rest_forbidden', __( 'Access denied', 'decker' ), array( 'status' => 403 ) );
		}

		if ( ! $this->validate_authorization( $auth_header ) ) {

			return new WP_Error( 'rest_forbidden', __( 'Access denied', 'decker' ), array( 'status' => 403 ) );
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
			   // Check if the string contains a "Name <email@example.com>" format.
		if ( preg_match( '/<([^>]+)>/', $email, $matches ) ) {
			return sanitize_email( $matches[1] );
		}

			   // If there are no brackets, assume it contains only the email.
		return sanitize_email( $email );
	}

	/**
	 * Gets the body of the email.
	 *
	 * @param Erseco\Message $message The Message instance.
	 * @return string The sanitized email body.
	 */
	public function get_body( Erseco\Message $message ): string {

		// Prefer HTML part.
		$html_part = $message->getHtmlPart();
		if ( $html_part ) {
			return wp_kses_post( $html_part->getContent() );
		}

		// Fall back to plain text part.
		$text_part = $message->getTextPart();
		if ( $text_part ) {
			return wp_kses_post( nl2br( esc_html( $text_part->getContent() ) ) );
		}

		// Fallback: first part.
		$parts = $message->getParts();
		if ( count( $parts ) > 0 ) {
			$content = $parts[0]->getContent();
			$content_type = $parts[0]->getContentType();

			if ( str_starts_with( strtolower( $content_type ), 'text/plain' ) ) {
				return wp_kses_post( nl2br( esc_html( $content ) ) );
			}
			return wp_kses_post( $content );
		}

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

			// Decode the base64-encoded email content.
			$raw_email = base64_decode( $payload['rawEmail'], true );
			if ( false === $raw_email ) {
				return new WP_Error( 'invalid_encoding', 'rawEmail must be base64 encoded', array( 'status' => 400 ) );
			}

			// Parse email.
			$message = $this->parse_email( $raw_email );

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
		require_once __DIR__ . '/../admin/vendor/mime-mail-parser/src/MimeMailParser.php';
		$message = new Erseco\Message( $raw_email );

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
				// Verify permissions and required data.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'permission_error', 'No tienes permisos para subir archivos.' );
		}

		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', 'ID de post inválido.' );
		}

		   // Extract only the MIME type without additional parameters.
		$type = explode( ';', $type )[0];

		   // Create a unique filename.
		$original_filename = sanitize_file_name( $filename );

		   // Reject attachments with no usable filename.
		if ( '' === $original_filename ) {
			return new WP_Error( 'invalid_filename', 'Nombre de archivo inválido.' );
		}

		   // Validate the attachment against WordPress's allowed types. Never trust the
		   // attacker-controlled extension or the e-mail Content-Type on their own.
		$filetype = wp_check_filetype_and_ext( $original_filename, $original_filename, get_allowed_mime_types() );

		$verified_ext  = $filetype['ext'];
		$verified_type = $filetype['type'];

		   // Explicit denylist of executable / script extensions, checked against the
		   // sanitized filename so double extensions cannot smuggle code through.
		$disallowed_extensions = array(
			'php',
			'php3',
			'php4',
			'php5',
			'php6',
			'php7',
			'php8',
			'phtml',
			'phps',
			'phar',
			'pht',
			'phtm',
			'cgi',
			'pl',
			'asp',
			'aspx',
			'jsp',
			'jspx',
			'sh',
			'bash',
			'exe',
			'com',
			'bat',
			'cmd',
			'msi',
			'scr',
			'dll',
			'jar',
			'py',
			'rb',
			'htaccess',
			'htm',
			'html',
			'shtml',
			'svg',
		);

		$lower_filename = strtolower( $original_filename );
		foreach ( $disallowed_extensions as $disallowed_extension ) {
			if ( str_ends_with( $lower_filename, '.' . $disallowed_extension ) ) {
				return new WP_Error( 'disallowed_file_type', 'Tipo de archivo no permitido.' );
			}
		}

		   // Reject when WordPress cannot resolve a verified extension/type from the allowlist.
		if ( empty( $verified_ext ) || empty( $verified_type ) ) {
			return new WP_Error( 'disallowed_file_type', 'Tipo de archivo no permitido.' );
		}

		   // Use the verified MIME type from the allowlist, not the e-mail Content-Type.
		$type = $verified_type;

		$extension  = $verified_ext;
		$upload_dir = wp_upload_dir();

		   // Generate a unique file name using the native WordPress function.
		$obfuscated_name = wp_unique_filename(
			$upload_dir['path'],
			sanitize_file_name( wp_generate_uuid4() . '.' . $extension )
		);

			   // Build the full file path.
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

		   // Prepare the attachment info array.
		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . $obfuscated_name,
			'post_mime_type' => $type,
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $original_filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,  // Set the post parent.
		);

			   // Insert the attachment into the database.
		$attachment_id = wp_insert_attachment( $attachment, $file_path, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

			   // Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

			   // Save the original name in the metadata.
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
		$options    = get_option( 'decker_settings', array() );
		$shared_key = isset( $options['shared_key'] ) ? sanitize_text_field( $options['shared_key'] ) : '';

		// Fail closed: if no shared key is configured, deny all requests.
		if ( '' === $shared_key ) {
			return false;
		}

		// Require a well-formed Bearer header with a non-empty token.
		if ( ! $auth_header || 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return false;
		}

		$token = substr( $auth_header, 7 );
		if ( '' === trim( $token ) ) {
			return false;
		}

		return hash_equals( $shared_key, $token );
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
		// Resolve the target board and the cleaned subject, supporting subject board directives.
		$resolved = $this->resolve_board_and_subject_from_email( $email_data['subject'], $author->ID );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		// Set task parameters.
		$due_date = new DateTime( '+3 days' );

		// Create task.
		$task_id = Decker_Tasks::create_or_update_task(
			0,
			$resolved['subject'],
			$email_data['body'],
			'to-do',
			$resolved['board_id'],
			false,
			$due_date,
			$author->ID,
			$author->ID,
			false,
			$assigned_users,
			array()
		);

		return $task_id;
	}

	/**
	 * Resolves the target board and the cleaned subject for a task created from email.
	 *
	 * Supports an optional board directive at the start of the subject:
	 *   [slug] Title         -> board with slug "slug" (shorthand).
	 *   [board:slug] Title   -> same, explicit qualifier.
	 *   [tablero:slug] Title -> same, Spanish qualifier.
	 *
	 * When the directive matches an existing board, that board is used and the directive is
	 * stripped from the title. When no directive is present, the sender's default board is
	 * used. An explicit "board:"/"tablero:" qualifier that references a missing board fails
	 * with 'invalid_board_slug'; a bare "[slug]" that matches no board is treated as a literal
	 * title and falls back to the default board, so legitimate bracketed subject prefixes
	 * (e.g. "[URGENT] ...") are never dropped.
	 *
	 * @param string $subject   The raw email subject.
	 * @param int    $author_id The sender WordPress user ID.
	 * @return array|WP_Error An array with 'board_id', 'subject' and 'source' on success,
	 *                        or a WP_Error on failure.
	 */
	private function resolve_board_and_subject_from_email( string $subject, int $author_id ) {
		$directive = $this->parse_board_directive_from_subject( $subject );

		$board_id = null;
		$title    = $directive['original'];
		$source   = 'default';

		if ( null !== $directive['slug'] ) {
			$board_id = $this->get_board_by_slug( $directive['slug'] );

			if ( null !== $board_id ) {
				// Directive matched a board: route there and strip it from the title.
				$title  = $directive['title'];
				$source = 'subject';
			} elseif ( $directive['qualified'] ) {
				// Explicit "board:"/"tablero:" directive with no matching board: fail loudly.
				return new WP_Error(
					'invalid_board_slug',
					'The board referenced in the subject does not exist',
					array( 'status' => 400 )
				);
			}
			// Bare "[slug]" with no matching board: keep $board_id null so the default
			// board is used below, preserving the original subject as the title.
		}

		if ( null === $board_id ) {
			$board_id = $this->get_default_board_for_user( $author_id );
			if ( is_wp_error( $board_id ) ) {
				return $board_id;
			}
		}

		if ( '' === $title ) {
			return new WP_Error(
				'missing_field',
				'The title is required',
				array( 'status' => 400 )
			);
		}

		return array(
			'board_id' => $board_id,
			'subject'  => $title,
			'source'   => $source,
		);
	}

	/**
	 * Parses an optional board directive from the start of an email subject.
	 *
	 * Recognizes "[slug]", "[board:slug]" and "[tablero:slug]" at the beginning of the
	 * subject. The slug is normalized with sanitize_title() so matching is case-insensitive.
	 *
	 * @param string $subject The raw email subject.
	 * @return array {
	 *     @type string|null $slug      The normalized board slug, or null when no usable directive is present.
	 *     @type bool        $qualified Whether an explicit "board:"/"tablero:" qualifier was used.
	 *     @type string      $title     The title with the directive stripped (used when the board matches).
	 *     @type string      $original  The trimmed subject with the directive preserved (fallback title).
	 * }
	 */
	private function parse_board_directive_from_subject( string $subject ): array {
		$trimmed = trim( $subject );

		$result = array(
			'slug'      => null,
			'qualified' => false,
			'title'     => $trimmed,
			'original'  => $trimmed,
		);

		// Match a leading "[ ... ]" directive followed by the remaining title.
		if ( ! preg_match( '/^\[\s*([^\]]+?)\s*\]\s*(.*)$/s', $trimmed, $matches ) ) {
			return $result;
		}

		$directive = $matches[1];
		$remainder = trim( $matches[2] );

		// Strip an optional "board:" or "tablero:" qualifier.
		$qualified = false;
		if ( preg_match( '/^(?:board|tablero)\s*:\s*(.+)$/i', $directive, $qualifier ) ) {
			$directive = $qualifier[1];
			$qualified = true;
		}

		$slug = sanitize_title( $directive );
		if ( '' === $slug ) {
			// Not a usable directive (e.g. "[ ]" or "[!!!]"); treat the subject as having none.
			return $result;
		}

		$result['slug']      = $slug;
		$result['qualified'] = $qualified;
		$result['title']     = $remainder;

		return $result;
	}

	/**
	 * Returns the sender's default board, preserving the historical error contract.
	 *
	 * @param int $user_id The sender WordPress user ID.
	 * @return int|WP_Error The board term ID, or a WP_Error when it is missing or invalid.
	 */
	private function get_default_board_for_user( int $user_id ) {
		$default_board = (int) get_user_meta( $user_id, 'decker_default_board', true );
		if ( $default_board <= 0 || ! term_exists( $default_board, 'decker_board' ) ) {
			return new WP_Error( 'invalid_board', 'Invalid default board' );
		}
		return $default_board;
	}

	/**
	 * Returns the term ID of a decker_board by slug.
	 *
	 * @param string $slug The board slug.
	 * @return int|null The board term ID, or null when no board matches.
	 */
	private function get_board_by_slug( string $slug ) {
		$term = get_term_by( 'slug', $slug, 'decker_board' );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		return (int) $term->term_id;
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
				$filename = $attachment->getFilename();
				$content  = $attachment->getContent();
				$mimetype = $attachment->getContentType();

				$result = $this->upload_attachment(
					$filename,
					$content,
					$mimetype,
					$task_id
				);

				if ( is_wp_error( $result ) ) {
					error_log(
						"Error uploading attachment {$filename}: " . $result->get_error_message()
					);
				}
			} catch ( Exception $e ) {
				error_log(
					"Exception processing attachment {$attachment->getFilename()}: " . $e->getMessage()
				);
			}
		}
	}
}


// Instantiate the class.
if ( class_exists( 'Decker_Email_To_Post' ) ) {
	new Decker_Email_To_Post();
}
