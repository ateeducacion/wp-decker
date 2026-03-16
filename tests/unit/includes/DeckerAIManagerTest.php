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
}
