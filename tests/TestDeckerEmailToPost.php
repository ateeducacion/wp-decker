<?php
use PHPUnit\Framework\TestCase;
use WP_Mock as M;

class TestDeckerEmailToPost extends TestCase {

	protected function setUp(): void {
		M::setUp();
	}

	protected function tearDown(): void {
		M::tearDown();
	}

	public function testProcessEmail() {
		// Mock WordPress functions
		M::userFunction('get_option', [
			'args' => ['decker_settings', []],
			'return' => ['shared_key' => 'RANDOM_VALUE_FOR_TEST'],
		]);

		M::userFunction('get_user_by', [
			'args' => ['email', 'user1@example.com'],
			'return' => (object) ['ID' => 1],
		]);

		M::userFunction('rest_ensure_response', [
			'return' => function($response) {
				return $response;
			},
		]);

		// Create an instance of the class
		$email_to_post = new Decker_Email_To_Post();

		// Simulate a REST request
		$request = new WP_REST_Request('POST', '/decker/v1/email-to-post');
		$request->set_param('shared_key', 'RANDOM_VALUE_FOR_TEST');
		$request->set_json_params([
			'from' => 'user1@example.com',
			'to' => ['user2@example.com', 'user1@example.com'],
			'subject' => 'Test Email Subject',
			'body' => 'This is a test email body',
			'headers' => [
				'subject' => 'Test Email Subject',
				'content-type' => 'text/plain',
			],
		]);

		// Call the method and assert the response
		$response = $email_to_post->process_email($request);
		$this->assertEquals('success', $response['status']);
		$this->assertIsInt($response['post_id']);
	}
}
