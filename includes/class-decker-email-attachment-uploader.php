<?php
/**
 * Stores e-mail attachments as task media.
 *
 * Attachments arrive from an unauthenticated mail relay, so every field is
 * attacker-controlled. This class owns the whole pipeline: the extension
 * denylist and allowlist resolution, the UUID rename on disk, and the media
 * registration that links the file to its task.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Email_Attachment_Uploader
 */
class Decker_Email_Attachment_Uploader {

	/**
	 * Executable and script extensions that are never accepted as attachments.
	 *
	 * Checked against the sanitized filename so a trailing script extension
	 * cannot smuggle code through behind a harmless-looking one.
	 *
	 * @var array<int, string>
	 */
	private const DISALLOWED_EXTENSIONS = array(
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

	/**
	 * Processes and uploads attachments as WordPress media.
	 *
	 * The MIME type announced by the e-mail is deliberately not accepted: it is
	 * attacker-controlled, so the type is resolved from the filename extension
	 * against the WordPress allowlist instead, and the file is stored under a
	 * generated name.
	 *
	 * Note that the file contents are never inspected. The type is derived from
	 * the extension alone, so a file whose bytes do not match its extension is
	 * still accepted; what protects the site is the denylist, the allowlist and
	 * the rename, not content sniffing.
	 *
	 * @param string $filename Name of the file.
	 * @param string $content  File content.
	 * @param int    $post_id  Linked post.
	 * @return int|WP_Error Attachment ID, or an error describing the refusal.
	 */
	private function upload_attachment( $filename, $content, $post_id ) {
		// Verify permissions and required data.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'permission_error', 'No tienes permisos para subir archivos.' );
		}

		if ( ! $post_id ) {
			return new WP_Error( 'invalid_post', 'ID de post inválido.' );
		}

		$original_filename = sanitize_file_name( $filename );

		// Reject attachments with no usable filename.
		if ( '' === $original_filename ) {
			return new WP_Error( 'invalid_filename', 'Nombre de archivo inválido.' );
		}

		$allowed = $this->resolve_allowed_attachment_type( $original_filename );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$file = $this->write_attachment_file( $content, $allowed['ext'] );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return $this->register_attachment( $file, $allowed['type'], $original_filename, $post_id );
	}

	/**
	 * Resolve the extension and MIME type WordPress is willing to accept.
	 *
	 * The name is checked against an explicit denylist and then mapped against
	 * the allowlist WordPress derives from get_allowed_mime_types().
	 *
	 * This resolves the type from the filename extension only; it does not
	 * verify it against the file contents. wp_check_filetype_and_ext() performs
	 * content sniffing solely when its first argument is a path to an existing
	 * file, and the attachment has not been written to disk at this point, so
	 * the call returns the extension-to-MIME mapping and nothing more.
	 *
	 * @param string $original_filename Sanitized attachment name.
	 * @return array{ext:string,type:string}|WP_Error Allowed extension and type, or the refusal.
	 */
	private function resolve_allowed_attachment_type( $original_filename ) {
		$lower_filename = strtolower( $original_filename );

		foreach ( self::DISALLOWED_EXTENSIONS as $disallowed_extension ) {
			if ( str_ends_with( $lower_filename, '.' . $disallowed_extension ) ) {
				return new WP_Error( 'disallowed_file_type', 'Tipo de archivo no permitido.' );
			}
		}

		$filetype = wp_check_filetype_and_ext( $original_filename, $original_filename, get_allowed_mime_types() );

		// Reject when WordPress cannot map the extension onto the allowlist.
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_Error( 'disallowed_file_type', 'Tipo de archivo no permitido.' );
		}

		return array(
			'ext'  => $filetype['ext'],
			'type' => $filetype['type'],
		);
	}

	/**
	 * Write the attachment body under a generated name inside the uploads folder.
	 *
	 * @param string $content   Raw file content.
	 * @param string $extension Allowed file extension.
	 * @return array{path:string,url:string}|WP_Error Stored file location, or the write error.
	 */
	private function write_attachment_file( $content, $extension ) {
		$upload_dir = wp_upload_dir();

		// Generate a unique file name using the native WordPress function.
		$obfuscated_name = wp_unique_filename(
			$upload_dir['path'],
			sanitize_file_name( wp_generate_uuid4() . '.' . $extension )
		);

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

		return array(
			'path' => $file_path,
			'url'  => $upload_dir['url'] . '/' . $obfuscated_name,
		);
	}

	/**
	 * Register a written file as a media attachment of the task.
	 *
	 * @param array{path:string,url:string} $file              Stored file location.
	 * @param string                        $type              MIME type resolved from the extension.
	 * @param string                        $original_filename Name the sender used.
	 * @param int                           $post_id           Task the attachment belongs to.
	 * @return int|WP_Error Attachment ID, or the insertion error.
	 */
	private function register_attachment( $file, $type, $original_filename, $post_id ) {
		$attachment = array(
			'guid'           => $file['url'],
			'post_mime_type' => $type,
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $original_filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,  // Set the post parent.
		);

		$attachment_id = wp_insert_attachment( $attachment, $file['path'], $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file['path'] );
			return $attachment_id;
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $file['path'] );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		// Save the original name in the metadata.
		update_post_meta( $attachment_id, '_original_filename', $original_filename );

		return $attachment_id;
	}

	/**
	 * Uploads attachments for a task.
	 *
	 * @param array $attachments An array of attachments, each containing 'filename', 'content', and 'mimetype'.
	 * @param int   $task_id     The ID of the task to associate the attachments with.
	 */
	public function upload_task_attachments( $attachments, $task_id ) {
		foreach ( $attachments as $attachment ) {
			try {
				$filename = $attachment->getFilename();
				$content  = $attachment->getContent();

				$result = $this->upload_attachment(
					$filename,
					$content,
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
