<?php
/**
 * Tests for the Gemini provider's HTTP round-trip.
 *
 * Response parsing is covered by DeckerAIGeminiApiProviderTest; these cover
 * the request that is sent and how HTTP failures are translated into
 * user-facing errors. Every request is short-circuited with pre_http_request
 * so no network call leaves the test process.
 *
 * @package Decker
 */

class DeckerAIGeminiRequestTest extends Decker_Test_Base {

	/**
	 * The last request arguments captured by the HTTP short-circuit.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * The URL of the last captured request.
	 *
	 * @var string
	 */
	private $captured_url = '';

	/**
	 * Remove the HTTP short-circuit after each test.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Short-circuit wp_remote_post with a canned response.
	 *
	 * @param int    $status HTTP status code to return.
	 * @param string $body   Response body to return.
	 * @return void
	 */
	private function stub_http( int $status, string $body ): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( $status, $body ) {
				$this->captured     = $args;
				$this->captured_url = $url;

				return array(
					'headers'  => array(),
					'body'     => $body,
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a well-formed Gemini success body.
	 *
	 * @param string $text The candidate text.
	 * @return string The encoded body.
	 */
	private function success_body( string $text ): string {
		return wp_json_encode(
			array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array( array( 'text' => $text ) ),
						),
					),
				),
			)
		);
	}

	/**
	 * With no API key the provider fails before making any request.
	 */
	public function test_missing_api_key_fails_without_a_request() {
		$this->stub_http( 200, $this->success_body( 'never reached' ) );

		$provider = new Decker_AI_Gemini_API_Provider( '   ', 'gemini-test' );
		$result   = $provider->improve_description( 'Improve this' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_missing_api_key', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->captured, 'No HTTP request must be issued without a key.' );
	}

	/**
	 * A successful call sends the key as a header and returns the candidate text.
	 */
	public function test_successful_call_sends_the_key_as_a_header() {
		$this->stub_http( 200, $this->success_body( 'A better description' ) );

		$provider = new Decker_AI_Gemini_API_Provider( 'secret-key', 'gemini-2.5-flash' );
		$result   = $provider->improve_description( 'Improve this' );

		$this->assertSame( 'A better description', $result );

		// The credential travels in the header, never in the URL.
		$this->assertSame( 'secret-key', $this->captured['headers']['X-Goog-Api-Key'] );
		$this->assertStringContainsString( 'gemini-2.5-flash:generateContent', $this->captured_url );
		$this->assertStringNotContainsString( 'secret-key', $this->captured_url );

		// The prompt is sent in the Gemini contents/parts structure.
		$body = json_decode( $this->captured['body'], true );
		$this->assertSame( 'Improve this', $body['contents'][0]['parts'][0]['text'] );
	}

	/**
	 * The model name is URL-encoded into the endpoint.
	 */
	public function test_model_name_is_url_encoded_into_the_endpoint() {
		$this->stub_http( 200, $this->success_body( 'ok' ) );

		$provider = new Decker_AI_Gemini_API_Provider( 'key', 'weird model/name' );
		$provider->improve_description( 'Improve this' );

		$this->assertStringContainsString( 'weird%20model%2Fname:generateContent', $this->captured_url );
	}

	/**
	 * A transport-level failure is reported as a request failure, not leaked raw.
	 */
	public function test_transport_error_is_reported_as_a_request_failure() {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			}
		);

		$provider = new Decker_AI_Gemini_API_Provider( 'key', 'gemini-test' );
		$result   = $provider->improve_description( 'Improve this' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_request_failed', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
		$this->assertStringNotContainsString( 'cURL', $result->get_error_message() );
	}

	/**
	 * HTTP status codes map to the documented error codes and statuses.
	 *
	 * @dataProvider provide_error_statuses
	 *
	 * @param int    $status         The HTTP status returned by the API.
	 * @param string $expected_code  The expected WP_Error code.
	 * @param int    $expected_status The expected error data status.
	 */
	public function test_http_status_maps_to_error_code( int $status, string $expected_code, int $expected_status ) {
		$this->stub_http(
			$status,
			wp_json_encode( array( 'error' => array( 'message' => 'upstream detail' ) ) )
		);

		$provider = new Decker_AI_Gemini_API_Provider( 'key', 'gemini-test' );
		$result   = $provider->improve_description( 'Improve this' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( $expected_status, $data['status'] );

		// The upstream detail is preserved for logging but kept out of the message.
		$this->assertSame( 'upstream detail', $data['provider'] );
		$this->assertStringNotContainsString( 'upstream detail', $result->get_error_message() );
	}

	/**
	 * Status codes and their expected error mapping.
	 *
	 * @return array<string, array{0:int,1:string,2:int}>
	 */
	public function provide_error_statuses(): array {
		return array(
			'unauthorized'      => array( 401, 'decker_ai_invalid_api_key', 502 ),
			'forbidden'         => array( 403, 'decker_ai_invalid_api_key', 502 ),
			'rate limited'      => array( 429, 'decker_ai_rate_limited', 429 ),
			'request timeout'   => array( 408, 'decker_ai_timeout', 504 ),
			'gateway timeout'   => array( 504, 'decker_ai_timeout', 504 ),
			'server error'      => array( 500, 'decker_ai_bad_response', 502 ),
			'bad request'       => array( 400, 'decker_ai_bad_response', 502 ),
		);
	}

	/**
	 * An error body that is not JSON leaves the provider detail empty.
	 */
	public function test_non_json_error_body_yields_no_provider_detail() {
		$this->stub_http( 500, '<html>Gateway blew up</html>' );

		$provider = new Decker_AI_Gemini_API_Provider( 'key', 'gemini-test' );
		$result   = $provider->improve_description( 'Improve this' );

		$this->assertSame( 'decker_ai_bad_response', $result->get_error_code() );
		$this->assertSame( '', $result->get_error_data()['provider'] );
	}
}
