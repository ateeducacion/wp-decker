<?php
/**
 * AI manager.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates AI provider configuration and server-side requests.
 */
class Decker_AI_Manager {

	/**
	 * Browser Gemini Nano provider slug.
	 *
	 * @var string
	 */
	const PROVIDER_BROWSER_GEMINI_NANO = 'browser_gemini_nano';

	/**
	 * Gemini API provider slug.
	 *
	 * @var string
	 */
	const PROVIDER_GEMINI_API = 'gemini_api';

	/**
	 * Default Gemini model.
	 *
	 * @var string
	 */
	const DEFAULT_GEMINI_MODEL = 'gemini-2.5-flash';

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'decker/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/ai/improve';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register AI REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'improve_description' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Check whether the current request may use AI improvements.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'decker_ai_auth_required',
				__( 'You must be logged in to use AI improvements.', 'decker' ),
				array( 'status' => 401 )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'decker_ai_invalid_nonce',
				__( 'Your session has expired. Please reload the page and try again.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Decker::current_user_has_at_least_minimum_role() ) {
			return new WP_Error(
				'decker_ai_forbidden',
				__( 'You do not have permission to use AI improvements.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Improve a task description with the selected provider.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function improve_description( WP_REST_Request $request ) {
		$options = get_option( 'decker_settings', array() );

		if ( empty( $options['ai_enabled'] ) || '1' !== $options['ai_enabled'] ) {
			return new WP_Error(
				'decker_ai_disabled',
				__( 'AI improvements are disabled in Decker settings.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		if ( self::PROVIDER_GEMINI_API !== self::get_selected_provider( $options ) ) {
			return new WP_Error(
				'decker_ai_wrong_provider',
				__(
					'The configured AI provider uses the browser directly and does not need the server endpoint.',
					'decker'
				),
				array( 'status' => 400 )
			);
		}

		$api_key = self::get_api_key( $options );
		if ( '' === $api_key ) {
			return new WP_Error(
				'decker_ai_missing_api_key',
				__(
					'The Gemini API provider is selected, but no API key has been saved in Decker settings.',
					'decker'
				),
				array( 'status' => 400 )
			);
		}

		$task_context = $this->sanitize_task_context(
			$request->get_param( 'task_context' )
		);

		if ( '' === trim( $task_context['content_text'] ) ) {
			return new WP_Error(
				'decker_ai_empty_content',
				__( 'Please add some text before using AI improvement.', 'decker' ),
				array( 'status' => 400 )
			);
		}

		$provider = new Decker_AI_Gemini_API_Provider(
			$api_key,
			self::get_model( $options )
		);
		$improved = $provider->improve_description(
			$this->build_prompt(
				sanitize_key( $request->get_param( 'mode' ) ),
				$task_context,
				$options
			)
		);

		if ( is_wp_error( $improved ) ) {
			return $improved;
		}

		return rest_ensure_response(
			array(
				'improved_text' => $improved,
			)
		);
	}

	/**
	 * Return the supported provider list.
	 *
	 * @return array
	 */
	public static function get_supported_providers() {
		return array(
			self::PROVIDER_BROWSER_GEMINI_NANO,
			self::PROVIDER_GEMINI_API,
		);
	}

	/**
	 * Get the currently selected provider.
	 *
	 * @param array|null $options Optional settings array.
	 * @return string
	 */
	public static function get_selected_provider( $options = null ) {
		$options  = is_array( $options ) ? $options : get_option( 'decker_settings', array() );
		$provider = isset( $options['ai_provider'] )
			? sanitize_key( $options['ai_provider'] )
			: self::PROVIDER_BROWSER_GEMINI_NANO;

		if ( ! in_array( $provider, self::get_supported_providers(), true ) ) {
			return self::PROVIDER_BROWSER_GEMINI_NANO;
		}

		return $provider;
	}

	/**
	 * Get the saved Gemini API key.
	 *
	 * @param array|null $options Optional settings array.
	 * @return string
	 */
	public static function get_api_key( $options = null ) {
		$options = is_array( $options ) ? $options : get_option( 'decker_settings', array() );

		if ( empty( $options['ai_api_key'] ) ) {
			return '';
		}

		return self::sanitize_api_key( $options['ai_api_key'] );
	}

	/**
	 * Sanitize a Gemini API key for storage and use.
	 *
	 * @param string $api_key Raw API key.
	 * @return string
	 */
	public static function sanitize_api_key( $api_key ) {
		return (string) preg_replace( '/\s+/', '', sanitize_text_field( $api_key ) );
	}

	/**
	 * Get the configured Gemini model.
	 *
	 * @param array|null $options Optional settings array.
	 * @return string
	 */
	public static function get_model( $options = null ) {
		$options = is_array( $options ) ? $options : get_option( 'decker_settings', array() );
		$model   = isset( $options['ai_model'] )
			? sanitize_text_field( $options['ai_model'] )
			: '';

		return '' !== $model ? $model : self::DEFAULT_GEMINI_MODEL;
	}

	/**
	 * Get the shared AI prompt configuration.
	 *
	 * @param array|null $options Optional settings array.
	 * @return array
	 */
	public static function get_prompt_config( $options = null ) {
		$options = is_array( $options ) ? $options : get_option( 'decker_settings', array() );

		return array(
			'prompt_template' => ! empty( $options['ai_prompt'] )
				? sanitize_textarea_field( $options['ai_prompt'] )
				: Decker::get_default_ai_prompt_template(),
			'improve_description' => __(
				'Rewrite the following task description to make it clearer, better structured, and more actionable. Preserve the original meaning, language, and important details. Do not add invented information. Return only the improved description.',
				'decker'
			),
			'make_actionable' => __(
				'Rewrite the task description as concrete, actionable steps. Each step should start with a verb and make clear WHO does WHAT. Use the assigned users in the task context only when helpful. Return only the final task description content.',
				'decker'
			),
			'generate_checklist' => __(
				'Convert the task description into a structured checklist. Group related items only when it improves clarity. Each item should be a single, verifiable action. Return only the final checklist content for the task description.',
				'decker'
			),
			'summarize'       => __(
				'Summarize the task description into 2-3 sentences maximum. Capture what needs to be done and the expected outcome. Return only the final short task description.',
				'decker'
			),
			'language_instruction' => sprintf(
				/* translators: %s: locale code such as es_ES. */
				__( 'Write the result in the language configured in WordPress (%s).', 'decker' ),
				get_user_locale()
			),
			'response_format' => __(
				'Return only the final task description as HTML, preserving valid HTML formatting tags such as <strong>, <em>, <ul>, <ol>, <li>, <p>, <a>. Do not include explanations, markdown code fences, or any text outside the HTML description itself.',
				'decker'
			),
			'context_title'   => __( 'Title', 'decker' ),
			'context_board'   => __( 'Board', 'decker' ),
			'context_responsible' => __( 'Responsable', 'decker' ),
			'context_assignees' => __( 'Assign to', 'decker' ),
			'context_stack'   => __( 'Stack', 'decker' ),
			'context_due_date' => __( 'Due Date', 'decker' ),
			'context_labels'  => __( 'Labels', 'decker' ),
			'context_max_priority' => __( 'Maximum Priority', 'decker' ),
			'context_today'   => __( 'For today', 'decker' ),
		);
	}

	/**
	 * Get the AI REST endpoint URL.
	 *
	 * @return string
	 */
	public static function get_rest_endpoint_url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Build the prompt sent to the selected provider.
	 *
	 * @param string $mode Rewrite mode key.
	 * @param array  $task_context Sanitized task context.
	 * @param array  $options Plugin options.
	 * @return string
	 */
	private function build_prompt( $mode, $task_context, $options ) {
		$prompts  = self::get_prompt_config( $options );
		$prefixes = array(
			'improve_description' => $prompts['improve_description'],
			'make_actionable'     => $prompts['make_actionable'],
			'generate_checklist'  => $prompts['generate_checklist'],
			'summarize'           => $prompts['summarize'],
		);
		$prefix   = isset( $prefixes[ $mode ] )
			? $prefixes[ $mode ]
			: $prefixes['improve_description'];

		return $this->apply_prompt_template(
			$prompts['prompt_template'],
			array(
				'mode_instruction'     => $prefix,
				'task_context'         => $this->format_task_context( $task_context, $prompts ),
				'content_html'         => $task_context['content_html'],
				'language_instruction' => $prompts['language_instruction'],
				'response_format'      => $prompts['response_format'],
			)
		);
	}

	/**
	 * Sanitize the incoming task context payload.
	 *
	 * @param mixed $task_context Raw task context.
	 * @return array
	 */
	private function sanitize_task_context( $task_context ) {
		$task_context = is_array( $task_context ) ? $task_context : array();

		$sanitized = array(
			'title'        => isset( $task_context['title'] ) ? sanitize_text_field( $task_context['title'] ) : '',
			'board'        => isset( $task_context['board'] ) ? sanitize_text_field( $task_context['board'] ) : '',
			'responsible'  => isset( $task_context['responsible'] ) ? sanitize_text_field( $task_context['responsible'] ) : '',
			'assignees'    => isset( $task_context['assignees'] ) ? sanitize_text_field( $task_context['assignees'] ) : '',
			'stack'        => isset( $task_context['stack'] ) ? sanitize_text_field( $task_context['stack'] ) : '',
			'due_date'     => isset( $task_context['due_date'] ) ? sanitize_text_field( $task_context['due_date'] ) : '',
			'labels'       => isset( $task_context['labels'] ) ? sanitize_text_field( $task_context['labels'] ) : '',
			'max_priority' => isset( $task_context['max_priority'] ) ? sanitize_text_field( $task_context['max_priority'] ) : '',
			'today'        => isset( $task_context['today'] ) ? sanitize_text_field( $task_context['today'] ) : '',
			'content_html' => isset( $task_context['content_html'] ) ? wp_kses_post( $task_context['content_html'] ) : '',
			'content_text' => isset( $task_context['content_text'] ) ? sanitize_textarea_field( $task_context['content_text'] ) : '',
		);

		if ( '' === $sanitized['content_text'] ) {
			$sanitized['content_text'] = wp_strip_all_tags( $sanitized['content_html'] );
		}

		if ( '' === $sanitized['content_html'] && '' !== $sanitized['content_text'] ) {
			$sanitized['content_html'] = '<p>' . esc_html( $sanitized['content_text'] ) . '</p>';
		}

		return $sanitized;
	}

	/**
	 * Format the task context block for the prompt.
	 *
	 * @param array $task_context Sanitized task context.
	 * @param array $prompts Prompt labels.
	 * @return string
	 */
	private function format_task_context( $task_context, $prompts ) {
		$empty_value = '—';

		return implode(
			"\n",
			array(
				$prompts['context_title'] . ': ' . ( $task_context['title'] ? $task_context['title'] : $empty_value ),
				$prompts['context_board'] . ': ' . ( $task_context['board'] ? $task_context['board'] : $empty_value ),
				$prompts['context_responsible'] . ': ' . ( $task_context['responsible'] ? $task_context['responsible'] : $empty_value ),
				$prompts['context_assignees'] . ': ' . ( $task_context['assignees'] ? $task_context['assignees'] : $empty_value ),
				$prompts['context_stack'] . ': ' . ( $task_context['stack'] ? $task_context['stack'] : $empty_value ),
				$prompts['context_due_date'] . ': ' . ( $task_context['due_date'] ? $task_context['due_date'] : $empty_value ),
				$prompts['context_labels'] . ': ' . ( $task_context['labels'] ? $task_context['labels'] : $empty_value ),
				$prompts['context_max_priority'] . ': ' . ( $task_context['max_priority'] ? $task_context['max_priority'] : $empty_value ),
				$prompts['context_today'] . ': ' . ( $task_context['today'] ? $task_context['today'] : $empty_value ),
			)
		);
	}

	/**
	 * Replace prompt placeholders with runtime values.
	 *
	 * @param string $template Prompt template.
	 * @param array  $replacements Placeholder replacements.
	 * @return string
	 */
	private function apply_prompt_template( $template, $replacements ) {
		foreach ( $replacements as $key => $value ) {
			$template = preg_replace_callback(
				'/\{\{\s*' . preg_quote( $key, '/' ) . '\s*\}\}/',
				function () use ( $value ) {
					return (string) $value;
				},
				$template
			);
		}

		return (string) $template;
	}
}
