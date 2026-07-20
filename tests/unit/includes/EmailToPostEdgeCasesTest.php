<?php
/**
 * Edge case tests for the email-to-post REST endpoint.
 *
 * @package Decker
 */

/**
 * Supplementary edge-case coverage for Decker_Email_To_Post.
 */
class EmailToPostEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Endpoint path.
	 *
	 * @var string
	 */
	private $endpoint = '/decker/v1/email-to-post';

	/**
	 * Shared authentication key.
	 *
	 * @var string
	 */
	private $shared_key;

	/**
	 * Registered sender user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up the REST endpoint and fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		$this->shared_key = wp_generate_uuid4();
		update_option( 'decker_settings', array( 'shared_key' => $this->shared_key ) );

		new Decker_Email_To_Post();
		do_action( 'rest_api_init' );

		$this->user_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'edge-user@example.com',
			)
		);

		wp_set_current_user( $this->user_id );
		$board_id = self::factory()->board->create(
			array(
				'name' => 'Edge Board',
				'slug' => 'edge-board',
			)
		);
		update_user_meta( $this->user_id, 'decker_default_board', $board_id );
	}

	/**
	 * Restore global state.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Reject a whitespace-only bearer token.
	 */
	public function test_authorization_rejected_for_whitespace_only_token() {
		$response = $this->dispatch( 'Bearer    ', $this->valid_payload() );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Reject an authorization scheme other than Bearer.
	 */
	public function test_authorization_rejected_for_malformed_bearer_prefix() {
		$response = $this->dispatch( 'Token ' . $this->shared_key, $this->valid_payload() );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Reject an incorrect bearer token.
	 */
	public function test_authorization_rejected_for_wrong_token() {
		$response = $this->dispatch( 'Bearer wrong-token', $this->valid_payload() );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Reject a non-base64 raw email.
	 */
	public function test_non_base64_raw_email_rejected() {
		$payload             = $this->valid_payload();
		$payload['rawEmail'] = '@@@@not-valid-base64@@@@';

		$response = $this->dispatch( 'Bearer ' . $this->shared_key, $payload );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Reject a payload without email data.
	 */
	public function test_empty_payload_rejected_with_400() {
		$response = $this->dispatch( 'Bearer ' . $this->shared_key, array() );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Reject a sender that is not associated with a WordPress user.
	 */
	public function test_unregistered_sender_returns_error() {
		$payload             = $this->valid_payload();
		$payload['metadata'] = array(
			'from'    => 'nobody@nowhere.invalid',
			'to'      => 'edge-user@example.com',
			'subject' => 'Ghost Sender Task',
			'cc'      => array(),
			'bcc'     => array(),
		);
		$payload['rawEmail'] = base64_encode(
			$this->build_raw_email(
				'From: nobody@nowhere.invalid',
				'To: edge-user@example.com',
				'Subject: Ghost Sender Task',
				'This is the body.'
			)
		);

		$response = $this->dispatch( 'Bearer ' . $this->shared_key, $payload );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_author', $data['code'] );
	}

	/**
	 * Reject a subject that is empty after removing a valid board directive.
	 */
	public function test_empty_subject_after_directive_is_rejected() {
		$payload = array(
			'rawEmail' => base64_encode(
				$this->build_raw_email(
					'From: edge-user@example.com',
					'To: inbox@decker.example.com',
					'Subject: [edge-board]',
					'Body text here.'
				)
			),
			'metadata' => array(
				'from'    => 'edge-user@example.com',
				'to'      => 'inbox@decker.example.com',
				'subject' => '[edge-board]',
				'cc'      => array(),
				'bcc'     => array(),
			),
		);

		$response = $this->dispatch( 'Bearer ' . $this->shared_key, $payload );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Dispatch an email-to-post request.
	 *
	 * @param string $authorization Authorization header value.
	 * @param array  $payload       Request payload.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $authorization, array $payload ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', $authorization );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $payload ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Build a valid request payload.
	 *
	 * @return array
	 */
	private function valid_payload(): array {
		return array(
			'rawEmail' => base64_encode(
				$this->build_raw_email(
					'From: edge-user@example.com',
					'To: inbox@decker.example.com',
					'Subject: Minimal Task',
					'Minimal body.'
				)
			),
			'metadata' => array(
				'from'    => 'edge-user@example.com',
				'to'      => 'inbox@decker.example.com',
				'subject' => 'Minimal Task',
				'cc'      => array(),
				'bcc'     => array(),
			),
		);
	}

	/**
	 * Build a minimal RFC 2822 message.
	 *
	 * @param string ...$parts Header lines followed by the message body.
	 * @return string
	 */
	private function build_raw_email( string ...$parts ): string {
		$body    = array_pop( $parts );
		$headers = implode( "\r\n", $parts );

		return $headers . "\r\nContent-Type: text/plain\r\n\r\n" . $body;
	}
}
