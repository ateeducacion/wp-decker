<?php
/**
 * Class responsible for AI-powered text improvement.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Resolve provider-specific AI settings.
 *
 * @since 1.0.0
 */
class Decker_AI_Provider_Settings {

	/**
	 * Valid providers.
	 *
	 * @var string[]
	 */
	private $valid_providers = array(
		'openai',
		'openrouter',
		'gemini',
	);

	/**
	 * Get the configured server-side AI provider.
	 *
	 * Falls back to legacy settings when the new option is not saved yet.
	 *
	 * @param array $settings Decker settings.
	 * @return string Provider slug.
	 */
	public function get_ai_provider( $settings ) {
		if ( ! empty( $settings['ai_provider'] ) ) {
			return sanitize_key( $settings['ai_provider'] );
		}

		return $this->infer_provider_from_legacy_url(
			isset( $settings['openai_api_url'] ) ? $settings['openai_api_url'] : ''
		);
	}

	/**
	 * Get the configured API key with backward compatibility for legacy options.
	 *
	 * @param array $settings Decker settings.
	 * @return string API key.
	 */
	public function get_api_key( $settings ) {
		$api_key = '';

		if ( ! empty( $settings['ai_api_key'] ) ) {
			$api_key = $settings['ai_api_key'];
		} elseif ( ! empty( $settings['openai_api_key'] ) ) {
			$api_key = $settings['openai_api_key'];
		}

		return trim( sanitize_text_field( $api_key ) );
	}

	/**
	 * Get provider-specific connection details.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $settings Decker settings.
	 * @return array<string, mixed> Provider configuration.
	 */
	public function get_provider_config( $provider, $settings ) {
		$configs = array(
			'openai'     => array(
				'endpoint'      => 'https://api.openai.com/v1/chat/completions',
				'default_model' => 'gpt-5-mini',
				'headers'       => array(),
			),
			'openrouter' => array(
				'endpoint'      => 'https://openrouter.ai/api/v1/chat/completions',
				'default_model' => 'openai/gpt-5-mini',
				'headers'       => array(
					'HTTP-Referer' => home_url( '/' ),
					'X-Title'      => get_bloginfo( 'name' ),
				),
			),
			'gemini'     => array(
				'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
				'default_model' => 'gemini-2.0-flash',
				'headers'       => array(),
			),
		);

		if ( ! isset( $configs[ $provider ] ) ) {
			return array();
		}

		if ( empty( $settings['ai_provider'] ) ) {
			$configs[ $provider ]['endpoint'] = $this->get_legacy_provider_endpoint(
				isset( $settings['openai_api_url'] ) ? $settings['openai_api_url'] : '',
				$configs[ $provider ]['endpoint']
			);
		}

		return $configs[ $provider ];
	}

	/**
	 * Get the configured model with backward compatibility for legacy options.
	 *
	 * @param array $settings        Decker settings.
	 * @param array $provider_config Provider configuration.
	 * @return string Model identifier.
	 */
	public function get_model( $settings, $provider_config ) {
		$model = '';

		if ( ! empty( $settings['ai_model'] ) ) {
			$model = $settings['ai_model'];
		} elseif ( ! empty( $settings['openai_model'] ) ) {
			$model = $settings['openai_model'];
		}

		if ( empty( $model ) ) {
			$model = isset( $provider_config['default_model'] )
				? $provider_config['default_model']
				: 'gpt-5-mini';
		}

		return sanitize_text_field( $model );
	}

	/**
	 * Validate a provider URL and ensure it is HTTPS.
	 *
	 * @param string $url Provider endpoint URL.
	 * @return string Validated URL or empty string.
	 */
	public function validate_provider_url( $url ) {
		$url = esc_url_raw( $url, array( 'https' ) );

		if ( empty( $url ) ) {
			return '';
		}

		$parsed_url = wp_parse_url( $url );

		if (
			! wp_http_validate_url( $url ) ||
			empty( $parsed_url['scheme'] ) ||
			'https' !== $parsed_url['scheme'] ||
			empty( $parsed_url['host'] ) ||
			! empty( $parsed_url['user'] ) ||
			! empty( $parsed_url['pass'] )
		) {
			return '';
		}

		return $url;
	}

	/**
	 * Get the valid providers.
	 *
	 * @return string[]
	 */
	public function get_valid_providers() {
		return $this->valid_providers;
	}

	/**
	 * Infer the provider from a legacy API URL.
	 *
	 * @param string $legacy_url Legacy provider URL.
	 * @return string Provider slug.
	 */
	private function infer_provider_from_legacy_url( $legacy_url ) {
		if ( empty( $legacy_url ) ) {
			return 'openai';
		}

		$legacy_url = strtolower( (string) $legacy_url );

		if ( false !== strpos( $legacy_url, 'openrouter.ai' ) ) {
			return 'openrouter';
		}

		if (
			false !== strpos( $legacy_url, 'generativelanguage.googleapis.com' ) ||
			false !== strpos( $legacy_url, 'googleapis.com' )
		) {
			return 'gemini';
		}

		return 'openai';
	}

	/**
	 * Get the legacy provider endpoint when available.
	 *
	 * @param string $legacy_url        Legacy configured URL.
	 * @param string $default_endpoint  Default endpoint for the provider.
	 * @return string Endpoint URL.
	 */
	private function get_legacy_provider_endpoint( $legacy_url, $default_endpoint ) {
		$legacy_endpoint = $this->validate_provider_url( $legacy_url );

		return ! empty( $legacy_endpoint ) ? $legacy_endpoint : $default_endpoint;
	}
}

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
		'improve_writing',
		'make_shorter',
		'make_clearer',
		'fix_grammar',
		'make_actionable',
		'checklist',
		'acceptance_criteria',
		'summarize',
	);

	/**
	 * Supported server-side AI providers.
	 *
	 * @var string[]
	 */
	const VALID_PROVIDERS = array(
		'openai',
		'openrouter',
		'gemini',
	);

	/**
	 * Provider settings resolver.
	 *
	 * @var Decker_AI_Provider_Settings|null
	 */
	private $provider_settings;

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
		$provider = $this->get_ai_provider( $settings );

		if ( ! in_array( $provider, $this->get_provider_settings()->get_valid_providers(), true ) ) {
			return new WP_Error(
				'invalid_ai_provider',
				__(
					'The configured AI provider is invalid. Please review the Decker AI settings.',
					'decker'
				),
				array( 'status' => 503 )
			);
		}

		$api_key         = $this->get_api_key( $settings );
		$provider_config = $this->get_provider_config( $provider, $settings );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'no_api_key',
				__( 'No AI API key configured. Please add one in Decker settings.', 'decker' ),
				array( 'status' => 503 )
			);
		}

		$model  = $this->get_model( $settings, $provider_config );

		$prompt = $this->build_prompt( $mode, $text );

		return $this->call_provider( $provider, $api_key, $model, $prompt, $provider_config );
	}

	/**
	 * Get the configured server-side AI provider.
	 *
	 * Falls back to legacy settings when the new option is not saved yet.
	 *
	 * @param array $settings Decker settings.
	 * @return string Provider slug.
	 */
	protected function get_ai_provider( $settings ) {
		return $this->get_provider_settings()->get_ai_provider( $settings );
	}

	/**
	 * Get the configured API key with backward compatibility for legacy options.
	 *
	 * @param array $settings Decker settings.
	 * @return string API key.
	 */
	protected function get_api_key( $settings ) {
		return $this->get_provider_settings()->get_api_key( $settings );
	}

	/**
	 * Get provider-specific connection details.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $settings Decker settings.
	 * @return array<string, mixed> Provider configuration.
	 */
	protected function get_provider_config( $provider, $settings ) {
		return $this->get_provider_settings()->get_provider_config( $provider, $settings );
	}

	/**
	 * Get the configured model with backward compatibility for legacy options.
	 *
	 * @param array $settings        Decker settings.
	 * @param array $provider_config Provider configuration.
	 * @return string Model identifier.
	 */
	protected function get_model( $settings, $provider_config ) {
		return $this->get_provider_settings()->get_model( $settings, $provider_config );
	}

	/**
	 * Validate a provider URL and ensure it is HTTPS.
	 *
	 * @param string $url Provider endpoint URL.
	 * @return string Validated URL or empty string.
	 */
	protected function validate_provider_url( $url ) {
		return $this->get_provider_settings()->validate_provider_url( $url );
	}

	/**
	 * Get the provider settings resolver.
	 *
	 * @return Decker_AI_Provider_Settings Provider settings resolver.
	 */
	protected function get_provider_settings() {
		if ( ! $this->provider_settings instanceof Decker_AI_Provider_Settings ) {
			$this->provider_settings = new Decker_AI_Provider_Settings();
		}

		return $this->provider_settings;
	}

	/**
	 * Build the system + user prompt for the given rewrite mode.
	 *
	 * @param string $mode Rewrite mode key.
	 * @param string $text Original content.
	 * @return string Full prompt string.
	 */
	protected function build_prompt( $mode, $text ) {
		$locale = $this->get_prompt_locale();

		$language_instruction = sprintf(
			/* translators: %s: locale code such as es_ES. */
			__( 'Write the result in the language configured in WordPress (%s).', 'decker' ),
			$locale
		);

		$suffix = __(
			'Return only the improved text as HTML, preserving valid HTML formatting tags such as <strong>, <em>, <ul>, <ol>, <li>, <p>, <a>. Do not include explanations, markdown code fences, or any text outside the HTML content itself.',
			'decker'
		);

		$prefixes = array(
			'improve_writing'     => __(
				'Improve the following task description so it is concise, clear, and easy for the team to execute.',
				'decker'
			),
			'make_shorter'        => __(
				'Shorten the following task description while keeping the key actions, constraints, and expected outcome.',
				'decker'
			),
			'make_clearer'        => __(
				'Rewrite the following task description so it is clearer, more specific, and easier to understand.',
				'decker'
			),
			'fix_grammar'         => __(
				'Fix grammar, spelling, and punctuation in the following task description without changing its meaning.',
				'decker'
			),
			'make_actionable'     => __(
				'Rewrite the following task description as an actionable task with concrete next steps and clear ownership language.',
				'decker'
			),
			'checklist'           => __(
				'Convert the following task description into a concise checklist that can be pasted directly into the task.',
				'decker'
			),
			'acceptance_criteria' => __(
				'Extract concise acceptance criteria from the following task description.',
				'decker'
			),
			'summarize'           => __(
				'Summarize the following task description into a short, useful summary.',
				'decker'
			),
		);

		$prefix = isset( $prefixes[ $mode ] ) ? $prefixes[ $mode ] : $prefixes['improve_writing'];

		return $prefix . ' ' . $language_instruction . ' ' . $suffix . "\n\n" . $text;
	}

	/**
	 * Get the locale used in AI prompts.
	 *
	 * @return string Locale code.
	 */
	protected function get_prompt_locale() {
		if ( is_user_logged_in() ) {
			return get_user_locale();
		}

		return determine_locale();
	}

	/**
	 * Send the prompt to the configured provider.
	 *
	 * @param string $provider        Provider slug.
	 * @param string $api_key         Provider API key.
	 * @param string $model           Model identifier.
	 * @param string $prompt          Full prompt to send.
	 * @param array  $provider_config Provider configuration.
	 * @return string|WP_Error Improved content, or WP_Error on failure.
	 */
	protected function call_provider( $provider, $api_key, $model, $prompt, $provider_config ) {
		$response = wp_remote_post(
			$provider_config['endpoint'],
			$this->build_provider_request_args( $provider_config, $api_key, $model, $prompt )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'provider_request_failed',
				/* translators: %s: error message */
				sprintf( __( 'AI request failed: %s', 'decker' ), $response->get_error_message() ),
				array( 'status' => 503 )
			);
		}

		return $this->parse_provider_response( $response, $provider );
	}

	/**
	 * Build the HTTP request arguments for the selected provider.
	 *
	 * @param array  $provider_config Provider configuration.
	 * @param string $api_key         Provider API key.
	 * @param string $model           Model identifier.
	 * @param string $prompt          Prompt content.
	 * @return array<string, mixed> Request arguments for wp_remote_post().
	 */
	protected function build_provider_request_args( $provider_config, $api_key, $model, $prompt ) {
		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			isset( $provider_config['headers'] ) ? $provider_config['headers'] : array()
		);

		return array(
			'timeout' => 60,
			'headers' => $headers,
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
		);
	}

	/**
	 * Parse the provider HTTP response.
	 *
	 * @param array|WP_Error $response HTTP response from wp_remote_post().
	 * @param string         $provider Provider slug.
	 * @return string|WP_Error Sanitized improved content or a WP_Error.
	 */
	protected function parse_provider_response( $response, $provider ) {
		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( 200 !== $status ) {
			$api_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: __( 'Unknown error from AI provider.', 'decker' );

			return new WP_Error(
				'provider_api_error',
				/* translators: 1: provider name, 2: error message from API */
				sprintf( __( 'AI API error (%1$s): %2$s', 'decker' ), $provider, $api_message ),
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
