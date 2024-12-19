<?php
/**
 * Class Test_Decker_Mailer
 *
 * @package Decker
 */

/**
 * Test cases for Decker_Mailer class.
 */
class Test_Decker_Mailer extends WP_UnitTestCase {

    /**
     * Instance of Decker_Mailer
     *
     * @var Decker_Mailer
     */
    private $mailer;

    /**
     * Set up test environment.
     */
    public function set_up() {
        parent::set_up();
        $this->mailer = new Decker_Mailer();
    }

    /**
     * Test email sending with basic content
     */
    public function test_send_email_basic() {
        // Reset email tracking
        reset_phpmailer_instance();

        // Send test email
        $to = 'test@example.com';
        $subject = 'Test Subject';
        $content = 'Test Content';
        
        $result = $this->mailer->send_email($to, $subject, $content);
        
        // Get the PHPMailer instance
        $mailer = tests_retrieve_phpmailer_instance();
        
        // Assert email was sent successfully
        $this->assertTrue($result);
        
        // Assert recipient
        $this->assertEquals($to, $mailer->get_recipient('to')->address);
        
        // Assert subject has [Decker] prefix
        $this->assertEquals('[Decker] ' . $subject, $mailer->Subject);
        
        // Assert content type is HTML
        $this->assertStringContainsString('text/html', $mailer->ContentType);
        
        // Assert email contains our content
        $this->assertStringContainsString($content, $mailer->Body);
        
        // Assert email contains footer text
        $this->assertStringContainsString('Este correo ha sido enviado automáticamente por Decker', $mailer->Body);
    }

    /**
     * Test email sending with HTML content
     */
    public function test_send_email_html_content() {
        reset_phpmailer_instance();

        $to = 'test@example.com';
        $subject = 'HTML Test';
        $content = '<h1>Test Header</h1><p>Test paragraph</p>';
        
        $result = $this->mailer->send_email($to, $subject, $content);
        
        $mailer = tests_retrieve_phpmailer_instance();
        
        $this->assertTrue($result);
        $this->assertStringContainsString('<h1>Test Header</h1>', $mailer->Body);
        $this->assertStringContainsString('<p>Test paragraph</p>', $mailer->Body);
    }

    /**
     * Test email sending with special characters
     */
    public function test_send_email_special_chars() {
        reset_phpmailer_instance();

        $to = 'test@example.com';
        $subject = 'Special Chars Test áéíóú';
        $content = 'Content with special chars: áéíóú ñÑ';
        
        $result = $this->mailer->send_email($to, $subject, $content);
        
        $mailer = tests_retrieve_phpmailer_instance();
        
        $this->assertTrue($result);
        $this->assertStringContainsString('áéíóú', $mailer->Body);
        $this->assertStringContainsString('ñÑ', $mailer->Body);
    }

    /**
     * Test email template structure
     */
    public function test_email_template_structure() {
        reset_phpmailer_instance();

        $to = 'test@example.com';
        $subject = 'Template Test';
        $content = 'Test Content';
        
        $this->mailer->send_email($to, $subject, $content);
        
        $mailer = tests_retrieve_phpmailer_instance();
        
        // Check basic HTML structure
        $this->assertStringContainsString('<!DOCTYPE html>', $mailer->Body);
        $this->assertStringContainsString('<meta charset="UTF-8">', $mailer->Body);
        $this->assertStringContainsString('<meta name="viewport"', $mailer->Body);
        
        // Check template elements
        $this->assertStringContainsString('decker-logo.png', $mailer->Body);
        $this->assertStringContainsString('style="max-width: 600px;', $mailer->Body);
    }
}
