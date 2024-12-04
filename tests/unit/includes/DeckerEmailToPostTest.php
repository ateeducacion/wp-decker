<?php
/**
 * Class Test_Decker_Email_To_Post
 *
 * @package Decker
 */

class DeckerEmailToPostTest extends WP_UnitTestCase {
    private $user;
    private $board;
    private $shared_key;
    private $endpoint = '/wp-json/decker/v1/email-to-post';

    public function setUp(): void {
        parent::setUp();

        // Initialize REST API
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
        
        // Create instance of our controller class
        $this->controller = new Decker_Email_To_Post();
        
        // Create test user
        $this->user = $this->factory->user->create_and_get([
            'role' => 'administrator',
            'user_email' => 'test@example.com'
        ]);
        
        // Create test board
        $this->board = wp_insert_term('Test Board', 'decker_board');
        
        // Set shared key in options
        $this->shared_key = wp_generate_uuid4();
        update_option('decker_settings', ['shared_key' => $this->shared_key]);
    }

    public function tearDown(): void {
        // Clean up
        wp_delete_user($this->user->ID);
        wp_delete_term($this->board['term_id'], 'decker_board');
        delete_option('decker_settings');
        
        parent::tearDown();
    }

    public function test_endpoint_requires_authorization() {
        $request = new WP_REST_Request('POST', $this->endpoint);
        $response = rest_get_server()->dispatch($request);
        
        $this->assertEquals(403, $response->get_status());
    }

    public function test_endpoint_requires_valid_payload() {
        $request = new WP_REST_Request('POST', $this->endpoint);
        $request->add_header('Authorization', 'Bearer ' . $this->shared_key);
        $response = rest_get_server()->dispatch($request);
        
        $this->assertEquals(400, $response->get_status());
    }

    public function test_creates_task_from_valid_email() {
        // Set default board for user
        update_user_meta($this->user->ID, 'decker_default_board', $this->board['term_id']);

        $email_content = "From: test@example.com\r\n";
        $email_content .= "To: decker@example.com\r\n";
        $email_content .= "Subject: Test Task\r\n";
        $email_content .= "Content-Type: text/plain\r\n\r\n";
        $email_content .= "This is a test task";

        $request = new WP_REST_Request('POST', $this->endpoint);
        $request->add_header('Authorization', 'Bearer ' . $this->shared_key);
        $request->set_body_params([
            'rawEmail' => base64_encode($email_content),
            'metadata' => [
                'from' => 'test@example.com',
                'to' => 'decker@example.com',
                'subject' => 'Test Task',
                'cc' => [],
                'bcc' => []
            ]
        ]);

        $response = rest_get_server()->dispatch($request);
        
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('task_id', $data);
        
        // Verify task was created correctly
        $task = get_post($data['task_id']);
        $this->assertEquals('Test Task', $task->post_title);
        $this->assertEquals('This is a test task', trim($task->post_content));
        $this->assertEquals($this->user->ID, $task->post_author);
    }

    public function test_creates_task_with_attachment() {
        update_user_meta($this->user->ID, 'decker_default_board', $this->board['term_id']);

        // Create email with attachment
        $attachment_content = "This is a test file content";
        $email_content = "From: test@example.com\r\n";
        $email_content .= "To: decker@example.com\r\n";
        $email_content .= "Subject: Task with Attachment\r\n";
        $email_content .= "Content-Type: multipart/mixed; boundary=\"boundary\"\r\n\r\n";
        $email_content .= "--boundary\r\n";
        $email_content .= "Content-Type: text/plain\r\n\r\n";
        $email_content .= "Task with attachment\r\n";
        $email_content .= "--boundary\r\n";
        $email_content .= "Content-Type: text/plain; name=\"test.txt\"\r\n";
        $email_content .= "Content-Disposition: attachment; filename=\"test.txt\"\r\n\r\n";
        $email_content .= $attachment_content . "\r\n";
        $email_content .= "--boundary--";

        $request = new WP_REST_Request('POST', $this->endpoint);
        $request->add_header('Authorization', 'Bearer ' . $this->shared_key);
        $request->set_body_params([
            'rawEmail' => base64_encode($email_content),
            'metadata' => [
                'from' => 'test@example.com',
                'to' => 'decker@example.com',
                'subject' => 'Task with Attachment',
                'cc' => [],
                'bcc' => []
            ]
        ]);

        $response = rest_get_server()->dispatch($request);
        
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        
        // Verify attachment was uploaded
        $attachments = get_attached_media('', $data['task_id']);
        $this->assertCount(1, $attachments);
        
        $attachment = array_shift($attachments);
        $this->assertEquals('text/plain', $attachment->post_mime_type);
        $this->assertStringContainsString('test.txt', $attachment->post_title);
    }

    public function test_handles_user_without_default_board() {
        // Don't set default board for user
        
        $email_content = "From: test@example.com\r\n";
        $email_content .= "To: decker@example.com\r\n";
        $email_content .= "Subject: Test Task No Board\r\n";
        $email_content .= "Content-Type: text/plain\r\n\r\n";
        $email_content .= "This is a test task";

        $request = new WP_REST_Request('POST', $this->endpoint);
        $request->add_header('Authorization', 'Bearer ' . $this->shared_key);
        $request->set_body_params([
            'rawEmail' => base64_encode($email_content),
            'metadata' => [
                'from' => 'test@example.com',
                'to' => 'decker@example.com',
                'subject' => 'Test Task No Board',
                'cc' => [],
                'bcc' => []
            ]
        ]);

        $response = rest_get_server()->dispatch($request);
        
        $this->assertEquals(400, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('default board', $data['message']);
    }
}
