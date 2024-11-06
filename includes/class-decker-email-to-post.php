<?php
/**
 * Clase para manejar la creación de posts desde emails en el plugin Decker.
 */
class Decker_Email_To_Post {

	/**
	 * Clave compartida para asegurar el endpoint.
	 */
	const SHARED_KEY = 'YOUR_SHARED_KEY';

	/**
	 * Inicializa la clase registrando el endpoint.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Registra el endpoint REST para procesar el email.
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
	 * Callback para procesar el email recibido y crear un post.
	 *
	 * @param WP_REST_Request $request Los datos de la solicitud REST.
	 * @return WP_REST_Response
	 */
	public function process_email( $request ) {
		$shared_key = $request->get_param( 'shared_key' );
		if ( $shared_key !== self::SHARED_KEY ) {
			return new WP_Error( 'forbidden', 'Clave de acceso incorrecta', array( 'status' => 403 ) );
		}

		$email_data = $request->get_json_params();

		if ( ! $this->validate_sender( $email_data['from'] ) ) {
			return new WP_Error( 'forbidden', 'Remitente no autorizado', array( 'status' => 403 ) );
		}

		$post_id = $this->create_post_from_email( $email_data );

		return rest_ensure_response(
			array(
				'status' => 'success',
				'post_id' => $post_id,
			)
		);
	}

	/**
	 * Verifica si el remitente es un usuario registrado en WordPress.
	 *
	 * @param string $email Dirección de email del remitente.
	 * @return bool True si el remitente es válido, False en caso contrario.
	 */
	private function validate_sender( $email ) {
		return get_user_by( 'email', $email ) !== false;
	}

	/**
	 * Procesa los archivos adjuntos y los sube como medios en WordPress.
	 *
	 * @param string $filename Nombre del archivo.
	 * @param string $content  Contenido del archivo.
	 * @return int ID del adjunto.
	 */
	private function upload_attachment( $filename, $content ) {
		$upload_dir = wp_upload_dir();
		$path = $upload_dir['path'] . '/' . $filename;

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
	 * Crea un post en WordPress a partir de los datos de un email.
	 *
	 * @param array $email_data Datos del email.
	 * @return int ID del post creado.
	 */
	private function create_post_from_email( $email_data ) {
		$author = get_user_by( 'email', $email_data['from'] );
		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $email_data['subject'] ),
				'post_content' => sanitize_textarea_field( $email_data['body'] ),
				'post_status'  => 'publish',
				'post_author'  => $author->ID,
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
