<?php
/**
 * Edge case tests for the email-to-post REST endpoint.
 *
 * Covers boundary/failure inputs that complement DeckerEmailToPostTest.
 *
 * @package Decker
 */

/**
 * Supplementary edge-case coverage for Decker_Email_To_Post.
 */
class EmailToPostEdgeCasesTest extends Decker_Test_Base {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Endpoint path.
	 *
	 * @var string
	 */
	private $endpoint = '/decker/v1/email-to-post';

	/**
	 * Shared key for ******
	 *
	 * @var string
	 */
	private $shared_key;

	/**
	 * Editor user whose email is registered in WordPress.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * A board the test user owns.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();

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
		$this->board_id = self::factory()->board->create(
			array(
				'name' => 'Edge Board',
				'slug' => 'edge-board',
			)
		);

		update_user_meta( $this->user_id, 'decker_default_board', $this->board_id );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Authorization edge cases
	// -----------------------------------------------------------------------

	/**
	 * A ****** that is all whitespace is rejected as invalid.
	 */
	public function test_authorization_rejected_for_whitespace_only_token() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Bearer    ' );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => base64_encode( $this->build_minimal_raw_email() ),
					'metadata' => $this->build_minimal_metadata(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A malformed Authorization header (not starting with "Bearer ") is
	 * rejected with 403.
	 */
	public function test_authorization_rejected_for_malformed_bearer_prefix() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Token ' . $this->shared_key );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => base64_encode( $this->build_minimal_raw_email() ),
					'metadata' => $this->build_minimal_metadata(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An incorrect token value is rejected with 403.
	 */
	public function test_authorization_rejected_for_wrong_token() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', '******' );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => base64_encode( $this->build_minimal_raw_email() ),
					'metadata' => $this->build_minimal_metadata(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Payload edge cases
	// -----------------------------------------------------------------------

	/**
	 * A non-base64 rawEmail body is rejected with 400.
	 */
	public function test_non_base64_raw_email_rejected() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => '@@@@not-valid-base64@@@@',
					'metadata' => $this->build_minimal_metadata(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * When no rawEmail and no metadata are present, the request is rejected
	 * with 400.
	 */
	public function test_empty_payload_rejected_with_400() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->set_body( wp_json_encode( array() ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * An email from a sender that is not registered as a WordPress user
	 * returns a 500 error (the handler cannot resolve the author).
	 */
	public function test_unregistered_sender_returns_error() {
		$raw = $this->build_raw_email(
			'From: nobody@nowhere.invalid',
			'To: edge-user@example.com',
			'Subject: Ghost Sender Task',
			'This is the body.'
		);

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => base64_encode( $raw ),
					'metadata' => array(
						'from'    => 'nobody@nowhere.invalid',
						'to'      => 'edge-user@example.com',
						'subject' => 'Ghost Sender Task',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		// 500 is the expected response when the sender is not a known user.
		$this->assertSame( 500, $response->get_status() );
	}

	/**
	 * When the subject becomes empty after stripping the board directive, the
	 * request is rejected.
	 */
	public function test_empty_subject_after_directive_is_rejected() {
		$raw = $this->build_raw_email(
			'From: edge-user@example.com',
			'To: inbox@decker.example.com',
			'Subject: [edge-board]',
			'Body text here.'
		);

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->set_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->set_body(
			wp_json_encode(
				array(
					'rawEmail' => base64_encode( $raw ),
					'metadata' => array(
						'from'    => 'edge-user@example.com',
						'to'      => 'inbox@decker.example.com',
						'subject' => '[edge-board]',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertContains( $response->get_status(), array( 400, 500 ) );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build a minimal raw email string.
	 *
	 * @return string
	 */
	private function build_minimal_raw_email(): string {
		return $this->build_raw_email(
			'From: edge-user@example.com',
			'To: inbox@decker.example.com',
			'Subject: Minimal Task',
			'Minimal body.'
		);
	}

	/**
	 * Build a minimal metadata array.
	 *
	 * @return array
	 */
	private function build_minimal_metadata(): array {
		return array(
			'from'    => 'edge-user@example.com',
			'to'      => 'inbox@decker.example.com',
			'subject' => 'Minimal Task',
			'cc'      => array(),
			'bcc'     => array(),
		);
	}

	/**
	 * Assemble a raw RFC-2822 email message from header lines and a body.
	 *
	 * @param string ...$parts Header lines followed by the body as the last argument.
	 * @return string
	 */
	private function build_raw_email( string ...$parts ): string {
		$body    = array_pop( $parts );
		$headers = implode( "\r\n", $parts );
		return $headers . "\r\nContent-Type: text/plain\r\n\r\n" . $body;
	}
}
