<?php
/**
 * Class responsible for AI-powered text improvement.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Decker AI integration.
 *
 * Registers a REST API endpoint that proxies text-improvement requests
 * to an OpenAI-compatible API. The provider can be swapped by overriding
 * the call_provider() method in a sub-class or by replacing the
 * improve_text() implementation.
 *
 * @since 1.0.0
 */
class Decker_AI {

	/**
	 * Allowed rewrite modes.
	 *
	 * @var string[]
	 */
	const VALID_MODES = array(
		'improve',
		'shorten',
		'clarify',
		'professionalize',
		'proofread',
	);

	/**
	 * Constructor — registers REST hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'decker/v1',
			'/ai/improve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_improve_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'text' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					),
					'mode' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => function ( $value ) {
							return in_array( $value, self::VALID_MODES, true );
						},
					),
				),
			)
		);
	}

	/**
	 * Verify the requesting user has at least the 'read' capability.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to use the AI feature.', 'decker' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use the AI feature.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle the REST request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_improve_request( WP_REST_Request $request ) {
		$text = $request->get_param( 'text' );
		$mode = $request->get_param( 'mode' );

		if ( empty( trim( wp_strip_all_tags( $text ) ) ) ) {
			return new WP_Error(
				'empty_text',
				__( 'No text provided for improvement.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->improve_text( $text, $mode );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'improved_text' => $result,
			)
		);
	}

	/**
	 * Improve text using the configured AI provider.
	 *
	 * @param string $text Content to improve (may contain HTML).
	 * @param string $mode One of the VALID_MODES values.
	 * @return string|WP_Error Improved content or error.
	 */
	protected function improve_text( $text, $mode ) {
		$settings = get_option( 'decker_settings', array() );
		$api_key  = isset( $settings['openai_api_key'] ) ? trim( $settings['openai_api_key'] ) : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				__( 'No AI API key configured. Please add one in Decker settings.', 'decker' ),
				array( 'status' => 503 )
			);
		}

		$model  = isset( $settings['openai_model'] ) && ! empty( $settings['openai_model'] )
			? sanitize_text_field( $settings['openai_model'] )
			: 'gpt-4.1-nano';

		$prompt = $this->build_prompt( $mode, $text );

		return $this->call_provider( $api_key, $model, $prompt );
	}

	/**
	 * Build the system + user prompt for the given rewrite mode.
	 *
	 * @param string $mode Rewrite mode key.
	 * @param string $text Original content.
	 * @return string Full prompt string.
	 */
	protected function build_prompt( $mode, $text ) {
		$suffix = 'Return only the improved text as HTML, preserving valid HTML formatting '
			. 'tags such as <strong>, <em>, <ul>, <ol>, <li>, <p>, <a>. '
			. 'Do not include explanations, markdown code fences, or any text '
			. 'outside the HTML content itself.';

		$prefixes = array(
			'improve'         => 'Improve the writing of the following task description. Make it clearer, more fluent, and better structured.',
			'shorten'         => 'Shorten the following task description while keeping its key meaning.',
			'clarify'         => 'Rewrite the following task description to make it clearer and easier to understand.',
			'professionalize' => 'Rewrite the following task description in a professional tone.',
			'proofread'       => 'Fix all grammar, spelling, and punctuation errors in the following task description.',
		);

		$prefix = isset( $prefixes[ $mode ] ) ? $prefixes[ $mode ] : $prefixes['improve'];

		return $prefix . ' ' . $suffix . "\n\n" . $text;
	}

	/**
	 * Send the prompt to the OpenAI Chat Completions API.
	 *
	 * This method is intentionally separate so the provider can be swapped
	 * without changing the rest of the class logic.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $model   Model identifier (e.g. "gpt-4o-mini").
	 * @param string $prompt  Full prompt to send.
	 * @return string|WP_Error Improved content, or WP_Error on failure.
	 */
	protected function call_provider( $api_key, $model, $prompt ) {
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'max_tokens'  => 2000,
						'temperature' => 0.7,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'provider_request_failed',
				/* translators: %s: error message */
				sprintf( __( 'AI request failed: %s', 'decker' ), $response->get_error_message() ),
				array( 'status' => 503 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( 200 !== $status ) {
			$api_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown error from AI provider.', 'decker' );

			return new WP_Error(
				'provider_api_error',
				/* translators: %s: error message from API */
				sprintf( __( 'AI API error: %s', 'decker' ), $api_message ),
				array( 'status' => $status )
			);
		}

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'provider_empty_response',
				__( 'The AI provider returned an empty response.', 'decker' ),
				array( 'status' => 502 )
			);
		}

		return $this->sanitize_response( $data['choices'][0]['message']['content'] );
	}

	/**
	 * Strip common AI-generated artefacts (markdown fences, leading/trailing whitespace).
	 *
	 * @param string $content Raw content returned by the provider.
	 * @return string Cleaned content.
	 */
	protected function sanitize_response( $content ) {
		$content = trim( $content );
		// Remove optional language label on opening fence.
		$content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
		// Remove closing fence.
		$content = preg_replace( '/\s*```\s*$/i', '', $content );
		return trim( $content );
	}
}
