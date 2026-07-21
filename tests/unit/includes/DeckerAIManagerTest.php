<?php
/**
 * Tests for the AI manager and Gemini API provider.
 *
 * @package Decker
 */

/**
 * Unit tests for server-side AI behavior.
 */
class DeckerAIManagerTest extends Decker_Test_Base {

	/**
	 * AI manager instance.
	 *
	 * @var Decker_AI_Manager
	 */
	private $manager;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Last request body captured from the Gemini HTTP call.
	 *
	 * @var string
	 */
	private $captured_body = '';

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->manager   = new Decker_AI_Manager();
		$this->editor_id = self::factory()->user->create(
			array(
				'role' => 'editor',
			)
		);

		do_action( 'rest_api_init' );
		wp_set_current_user( $this->editor_id );
		update_option(
			'decker_settings',
			array(
				'ai_enabled'           => '1',
				'ai_provider'          => Decker_AI_Manager::PROVIDER_GEMINI_API,
				'ai_api_key'           => 'test-api-key',
				'ai_model'             => Decker_AI_Manager::DEFAULT_GEMINI_MODEL,
				'minimum_user_profile' => 'editor',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test permission checks reject users below the minimum role.
	 */
	public function test_permissions_check_rejects_users_without_required_role() {
		$subscriber_id = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode'         => 'improve_description',
					'task_context' => array(
						'content_text' => 'Original task description.',
						'content_html' => '<p>Original task description.</p>',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
		$this->assertSame(
			'You do not have permission to use AI improvements.',
			$response->get_data()['message']
		);
	}

	/**
	 * Test the server endpoint returns a clear error when the API key is missing.
	 */
	public function test_improve_description_returns_error_when_api_key_is_missing() {
		update_option(
			'decker_settings',
			array(
				'ai_enabled'           => '1',
				'ai_provider'          => Decker_AI_Manager::PROVIDER_GEMINI_API,
				'ai_api_key'           => '',
				'ai_model'             => Decker_AI_Manager::DEFAULT_GEMINI_MODEL,
				'minimum_user_profile' => 'editor',
			)
		);

		$request  = $this->get_improve_request();
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame(
			'The Gemini API provider is selected, but no API key has been saved in Decker settings.',
			$response->get_data()['message']
		);
	}

	/**
	 * Test successful Gemini API responses are parsed and returned.
	 */
	public function test_improve_description_parses_successful_gemini_response() {
		add_filter( 'pre_http_request', array( $this, 'mock_successful_gemini_response' ), 10, 3 );

		$request  = $this->get_improve_request();
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		remove_filter( 'pre_http_request', array( $this, 'mock_successful_gemini_response' ), 10 );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( '<p>Improved task description.</p>', $data['improved_text'] );
	}

	/**
	 * Test remote API errors are mapped to safe user-facing messages.
	 */
	public function test_improve_description_handles_remote_api_errors() {
		add_filter( 'pre_http_request', array( $this, 'mock_rate_limited_gemini_response' ), 10, 3 );

		$request  = $this->get_improve_request();
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'mock_rate_limited_gemini_response' ), 10 );

		$this->assertEquals( 429, $response->get_status() );
		$this->assertSame(
			'The Gemini API rate limit was reached. Please wait a moment and try again.',
			$response->get_data()['message']
		);
	}

	/**
	 * Build a standard AI improve REST request.
	 *
	 * @return WP_REST_Request
	 */
	private function get_improve_request() {
		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode'         => 'improve_description',
					'task_context' => array(
						'title'        => 'Original title',
						'content_text' => 'Original task description.',
						'content_html' => '<p>Original task description.</p>',
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Mock a successful Gemini API response.
	 *
	 * @param false|array|WP_Error $preempt Whether to preempt the request.
	 * @param array                $args Request arguments.
	 * @param string               $url Request URL.
	 * @return array
	 */
	public function mock_successful_gemini_response( $preempt, $_args, $_url ) {
		return array(
			'response' => array(
				'code' => 200,
			),
			'body'     => wp_json_encode(
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array(
									array(
										'text' => '<p>Improved task description.</p>',
									),
								),
							),
						),
					),
				)
			),
		);
	}

	/**
	 * Mock a rate-limited Gemini API response.
	 *
	 * @param false|array|WP_Error $preempt Whether to preempt the request.
	 * @param array                $args Request arguments.
	 * @param string               $url Request URL.
	 * @return array
	 */
	public function mock_rate_limited_gemini_response( $preempt, $_args, $_url ) {
		return array(
			'response' => array(
				'code' => 429,
			),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'message' => 'Quota exceeded.',
					),
				)
			),
		);
	}

	/**
	 * Capture the outgoing request body, then return a successful response.
	 *
	 * @param false|array|WP_Error $preempt Whether to preempt the request.
	 * @param array                $args Request arguments.
	 * @param string               $url Request URL.
	 * @return array
	 */
	public function capture_request_body( $preempt, $args, $_url ) {
		$this->captured_body = isset( $args['body'] ) ? (string) $args['body'] : '';

		return $this->mock_successful_gemini_response( $preempt, $args, $_url );
	}

	/**
	 * Build an improve request with an arbitrary task context.
	 *
	 * @param array  $task_context Task context payload.
	 * @param string $mode Rewrite mode key.
	 * @return WP_REST_Request
	 */
	private function get_improve_request_with_context( array $task_context, $mode = 'improve_description' ) {
		$request = new WP_REST_Request( 'POST', '/decker/v1/ai/improve' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			wp_json_encode(
				array(
					'mode'         => $mode,
					'task_context' => $task_context,
				)
			)
		);

		return $request;
	}

	/**
	 * Decode the captured request body and extract the generated prompt text.
	 *
	 * @return string
	 */
	private function get_captured_prompt() {
		$decoded = json_decode( $this->captured_body, true );

		return $decoded['contents'][0]['parts'][0]['text'];
	}

	/**
	 * Lock the full set of "Label: value" context lines sent to Gemini.
	 */
	public function test_improve_description_sends_formatted_context_lines_to_gemini() {
		add_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10, 3 );

		$request  = $this->get_improve_request_with_context(
			array(
				'title'        => 'Original title',
				'board'        => 'QA Board',
				'stack'        => 'to-do',
				'content_text' => 'Original task description.',
				'content_html' => '<p>Original task description.</p>',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10 );

		$this->assertEquals( 200, $response->get_status() );

		$prompt = $this->get_captured_prompt();

		$this->assertStringContainsString( 'Title: Original title', $prompt );
		$this->assertStringContainsString( 'Board: QA Board', $prompt );
		$this->assertStringContainsString( 'Stack: to-do', $prompt );
		$this->assertStringContainsString( 'Labels: —', $prompt );
		$this->assertStringContainsString( 'Assign to: —', $prompt );
		$this->assertStringContainsString( 'Due Date: —', $prompt );
		$this->assertStringContainsString( 'Maximum Priority: —', $prompt );
		$this->assertStringContainsString( 'For today: —', $prompt );
		$this->assertStringContainsString( 'Responsable: —', $prompt );
	}

	/**
	 * Lock the wp_strip_all_tags fallback that derives content_text from content_html.
	 */
	public function test_improve_description_derives_content_text_from_html() {
		add_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10, 3 );

		$request  = $this->get_improve_request_with_context(
			array(
				'content_html' => '<p>Hello fallback</p>',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10 );

		$this->assertEquals( 200, $response->get_status() );

		$prompt = $this->get_captured_prompt();
		$this->assertStringContainsString( '<p>Hello fallback</p>', $prompt );
	}

	/**
	 * Lock the empty-content rejection.
	 */
	public function test_improve_description_rejects_empty_content_with_400() {
		$request  = $this->get_improve_request_with_context(
			array(
				'content_text' => '',
				'content_html' => '',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertSame(
			'Please add some text before using AI improvement.',
			$response->get_data()['message']
		);
	}

	/**
	 * Lock the PHP-truthiness quirk where a '0' string renders as an em dash.
	 */
	public function test_improve_description_renders_zero_string_fields_as_em_dash() {
		add_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10, 3 );

		$request  = $this->get_improve_request_with_context(
			array(
				'title'        => '0',
				'labels'       => '0',
				'content_text' => 'Original task description.',
				'content_html' => '<p>Original task description.</p>',
			)
		);
		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10 );

		$this->assertEquals( 200, $response->get_status() );

		$prompt = $this->get_captured_prompt();
		$this->assertStringContainsString( 'Title: —', $prompt );
		$this->assertStringNotContainsString( 'Title: 0', $prompt );
	}

	/**
	 * Lock the mode prefix selection and its improve_description fallback.
	 */
	public function test_improve_description_uses_mode_prefix_with_fallback() {
		add_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10, 3 );

		$summarize  = $this->get_improve_request_with_context(
			array(
				'content_text' => 'Original task description.',
				'content_html' => '<p>Original task description.</p>',
			),
			'summarize'
		);
		rest_get_server()->dispatch( $summarize );
		$summarize_prompt = $this->get_captured_prompt();

		$unknown = $this->get_improve_request_with_context(
			array(
				'content_text' => 'Original task description.',
				'content_html' => '<p>Original task description.</p>',
			),
			'totally_unknown'
		);
		rest_get_server()->dispatch( $unknown );
		$unknown_prompt = $this->get_captured_prompt();

		remove_filter( 'pre_http_request', array( $this, 'capture_request_body' ), 10 );

		$this->assertStringContainsString( 'Summarize the task description into 2-3 sentences', $summarize_prompt );
		$this->assertStringContainsString( 'Rewrite the following task description', $unknown_prompt );
	}
}
