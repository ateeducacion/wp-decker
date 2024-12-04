<?php
/**
 * Class Test_Decker_Email_Parser
 *
 * @package Decker
 */

class DeckerEmailParserTest extends WP_UnitTestCase {

    /**
     * Test parsing a simple email from raw text
     */
    public function test_parse_simple_email() {
        $raw_email = "From: test@example.com\r\n";
        $raw_email .= "To: decker@example.com\r\n";
        $raw_email .= "Subject: Simple Test\r\n";
        $raw_email .= "Content-Type: text/plain\r\n\r\n";
        $raw_email .= "This is a simple test email";

        $parser = new Decker_Email_Parser($raw_email);

        $this->assertEquals('test@example.com', $parser->get_headers()['From']);
        $this->assertEquals('This is a simple test email', trim($parser->get_text_part()));
        $this->assertNull($parser->get_html_part());
        $this->assertEmpty($parser->get_attachments());
    }

    /**
     * Test parsing a basic task email from fixture
     */
    public function test_parse_task_email() {
        $email_content = $this->get_fixture_content('Test_Task.eml');
        $parser = new Decker_Email_Parser($email_content);

        $headers = $parser->get_headers();
        $this->assertEquals('Decker <test@example.com>', $headers['From']);
        $this->assertEquals('Test Task', $headers['Subject']);
        
        $this->assertEquals('This is a test task', trim($parser->get_text_part()));
        $this->assertEquals('<div dir="ltr">This is a test task</div>', trim($parser->get_html_part()));
        $this->assertEmpty($parser->get_attachments());
    }

    /**
     * Test parsing an email with attachment from fixture
     */
    public function test_parse_email_with_attachment() {
        $email_content = $this->get_fixture_content('Test_Attachment.eml');
        $parser = new Decker_Email_Parser($email_content);

        $attachments = $parser->get_attachments();
        $this->assertCount(1, $attachments);
        
        $attachment = $attachments[0];
        $this->assertEquals('test.txt', $attachment['filename']);
        $this->assertEquals('text/plain', $attachment['mimetype']);
        $this->assertEquals('Hello World!', base64_decode(trim($attachment['content'])));
    }

    /**
     * Test parsing a full email with complex content from fixture
     */
    public function test_parse_full_email() {
        $email_content = $this->get_fixture_content('Test_Full.eml');
        $parser = new Decker_Email_Parser($email_content);

        $headers = $parser->get_headers();
        $this->assertEquals('=?utf-8?Q?=C3=81REA_DE_TECNOLOG=C3=8DA?= EDUCATIVA <test@example.com>', $headers['From']);
        
        // Verify text content exists
        $this->assertNotEmpty($parser->get_text_part());
        
        // Verify HTML content exists
        $this->assertNotEmpty($parser->get_html_part());
        
        // This email should have no attachments
        $this->assertEmpty($parser->get_attachments());
    }

    /**
     * Helper method to retrieve fixture content
     */
    private function get_fixture_content(string $filename): string {
        $fixture_path = __DIR__ . '/../../fixtures/' . $filename;
        if (!file_exists($fixture_path)) {
            $this->fail("Fixture file {$filename} does not exist at path {$fixture_path}.");
        }
        return file_get_contents($fixture_path);
    }
}
