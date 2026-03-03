<?php
/**
 * Class Test_Decker_Email_To_Post
 *
 * @package Decker
 */

class DeckerEmailToPostTest extends Decker_Test_Base {
	private $user_id;
	private $user2_id;
	private $board_id;
	private $shared_key;

	private $endpoint = '/decker/v1/email-to-post';

	public function setUp(): void {
		parent::setUp();

		// Initialize REST API
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();

		// Set shared key in options before instantiation
		$this->shared_key = wp_generate_uuid4();
		update_option( 'decker_settings', array( 'shared_key' => $this->shared_key ) );

		// Create instance of our controller class and register routes (it will have the shared_key setted)
		$this->controller = new Decker_Email_To_Post();

		// Trigger the rest_api_init action to register routes
		do_action( 'rest_api_init' );

		// Create test users
		$this->user_id = $this->factory->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'test@example.com',
			)
		);
		$this->user2_id = $this->factory->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'test2@example.com',
			)
		);

		// Create test board
		wp_set_current_user( $this->user_id );
		$this->board_id = self::factory()->board->create();
	}

	public function test_endpoint_requires_authorization() {
		$request  = new WP_REST_Request( 'POST', $this->endpoint );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'rest_forbidden', $data['code'] );
	}

	public function test_endpoint_requires_valid_payload() {
		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_payload', $data['code'] );
	}

	public function test_handles_user_without_default_board() {
		// Don't set default board for user

		$email_content  = "From: test@example.com\r\n";
		$email_content .= "To: decker@example.com\r\n";
		$email_content .= "Subject: Test Task No Board\r\n";
		$email_content .= "Content-Type: text/plain\r\n\r\n";
		$email_content .= 'This is a test task';

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'Test Task No Board',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 500, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data );
		$this->assertStringContainsString( 'invalid_board', $data['code'] );
	}


	public function test_creates_task_from_valid_email() {
		// Set default board for user
		update_user_meta( $this->user_id, 'decker_default_board', $this->board_id );

		// Load email content from fixture
		$email_content = $this->get_fixture_content( 'raw_email_from_gmail.eml' );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'Test Task',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'task_id', $data );

		// Verify task was created correctly
		$task = get_post( $data['task_id'] );

		$this->assertEquals( 'Test Task', $task->post_title );
		$this->assertStringContainsString( 'this is a mail from gmail', $task->post_content );
		$this->assertEquals( $this->user_id, $task->post_author );
	}


	public function test_creates_task_from_another_valid_email() {
		// Set default board for user
		update_user_meta( $this->user_id, 'decker_default_board', $this->board_id );

		// Load email content from fixture
		$email_content = $this->get_fixture_content( 'Test_Full.eml' );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'Test Full Task',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'task_id', $data );

		// Verify task was created correctly
		$task = get_post( $data['task_id'] );

		$this->assertEquals( 'Test Full Task', $task->post_title );
		$this->assertStringContainsString( 'Les comentamos la incidencia con la que nos encontramos con el Plan de Frutas', trim( $task->post_content ) );
		$this->assertEquals( $this->user_id, $task->post_author );
	}


	public function test_creates_task_with_attachment() {
		update_user_meta( $this->user_id, 'decker_default_board', $this->board_id );

		// Load email content from fixture
		$email_content = $this->get_fixture_content( 'Test_Attachment.eml' );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'Task with Attachment',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'task_id', $data );

		// Verify task was created correctly
		$task = get_post( $data['task_id'] );

		// var_dump( $task );

		$this->assertEquals( 'Task with Attachment', $task->post_title );

		// Verify attachment was uploaded
		$attachments = get_attached_media( '', $data['task_id'] );
		$this->assertCount( 1, $attachments );

		$attachment = array_shift( $attachments );
		$this->assertEquals( 'text/plain', $attachment->post_mime_type );
		$this->assertStringContainsString( 'test', $attachment->post_title );
	}

	public function test_rejects_non_base64_raw_email() {
		update_user_meta( $this->user_id, 'decker_default_board', $this->board_id );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => 'This is NOT base64 encoded!!! @@@~~~',
					'metadata' => array(
						'from'    => 'test@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'Test Task',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'invalid_encoding', $data['code'] );
	}

	public function test_creates_task_from_zimbra_email() {
		update_user_meta( $this->user2_id, 'decker_default_board', $this->board_id );

		$email_content = $this->get_fixture_content( 'raw_email_from_zimbra.eml' );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test2@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'test from zimbra',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'task_id', $data );

		$task = get_post( $data['task_id'] );
		$this->assertEquals( 'test from zimbra', $task->post_title );
		$this->assertNotEmpty( $task->post_content, 'Body should not be empty for Zimbra email' );
		$this->assertStringContainsString( 'this is a mail from zimbra', $task->post_content );
	}

	public function test_creates_task_from_zimbra_email_with_attachments() {
		update_user_meta( $this->user2_id, 'decker_default_board', $this->board_id );

		$email_content = $this->get_fixture_content( 'raw_email_from_zimbra_attachments.eml' );

		$request = new WP_REST_Request( 'POST', $this->endpoint );
		$request->add_header( 'Authorization', 'Bearer ' . $this->shared_key );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			json_encode(
				array(
					'rawEmail' => base64_encode( $email_content ),
					'metadata' => array(
						'from'    => 'test2@example.com',
						'to'      => 'decker@example.com',
						'subject' => 'test from zimbra with attachments',
						'cc'      => array(),
						'bcc'     => array(),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'task_id', $data );

		$task = get_post( $data['task_id'] );
		$this->assertEquals( 'test from zimbra with attachments', $task->post_title );
		$this->assertNotEmpty( $task->post_content, 'Body should not be empty for Zimbra email with attachments' );
		$this->assertStringContainsString( 'this is a mail from zimbra with attachments', $task->post_content );

		// Verify attachment was uploaded
		$attachments = get_attached_media( '', $data['task_id'] );
		$this->assertGreaterThanOrEqual( 1, count( $attachments ), 'Should have at least one attachment' );
	}

	/**
	 * Helper method to retrieve fixture content.
	 *
	 * @param string $filename Name of the fixture file.
	 * @return string File content.
	 */
	private function get_fixture_content( string $filename ): string {
		$fixture_path = __DIR__ . '/../../fixtures/' . $filename;

		if ( ! file_exists( $fixture_path ) ) {
			$this->fail( "Fixture file {$filename} does not exist at path {$fixture_path}." );
		}

		return file_get_contents( $fixture_path );
	}

	public function tearDown(): void {
		// Clean up

		wp_set_current_user( $this->user_id );
		wp_delete_term( $this->board_id, 'decker_board' );

		delete_user_meta( $this->user_id, 'decker_default_board' );
		delete_user_meta( $this->user2_id, 'decker_default_board' );
		wp_delete_user( $this->user_id );
		wp_delete_user( $this->user2_id );

		delete_option( 'decker_settings' );

		parent::tearDown();
	}
}
