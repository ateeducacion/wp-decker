<?php
/**
 * Test cases for Decker_Mailer class.
 */
class MailerTest extends WP_UnitTestCase {

	/**
	 * Instance of Decker_Mailer
	 *
	 * @var Decker_Mailer
	 */
	public $mailer;

	/**
	 * Captured email content
	 *
	 * @var array
	 */
	private $captured_mail = array();

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Initialize the mailer instance.
		$this->mailer = new Decker_Mailer();

               // Replace the wp_mail function with our test function.
		add_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 10, 2 );
	}

	/**
	 * Tear down the test environment.
	 */
	public function tear_down(): void {
		// Remove the wp_mail filter
		remove_filter( 'pre_wp_mail', array( $this, 'intercept_mail' ), 10 );
		$this->captured_mail = array();
		parent::tear_down();
	}

	/**
	 * Intercept wp_mail calls and capture the mail parameters.
	 *
	 * @param null|bool $pre Whether to short-circuit wp_mail()
	 * @param array     $args The wp_mail arguments
	 * @return bool Always returns false to prevent actual email sending
	 */
	public function intercept_mail( $pre, $args ) {
		$this->captured_mail = $args;
               return false; // Prevents the real email from being sent.
	}

	/**
	 * Test email sending and verify captured HTML content.
	 */
	public function test_mail_sending() {
		$to      = 'recipient@example.com';
		$subject = 'Test Subject';
		$content = '<p>This is a test email.</p>';

		// Send the email.
		$result = $this->mailer->send_email( $to, $subject, $content );

		// Verify wp_mail was called and returned true
		$this->assertFalse( $result, 'wp_mail should return false.' );

		// Verify the captured email content.
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );
		$this->assertEquals( $to, $this->captured_mail['to'], 'Recipient email does not match.' );
		$this->assertEquals( '[Decker] ' . $subject, $this->captured_mail['subject'], 'Email subject does not match.' );
		$this->assertStringContainsString( $content, $this->captured_mail['message'], 'Email content does not match.' );

		// Check the template structure.
		$html = $this->captured_mail['message'];
		$this->assertStringContainsString( '<!DOCTYPE html>', $html, 'HTML structure is incorrect.' );
		$this->assertStringContainsString( '<meta charset="UTF-8">', $html, 'Meta charset is missing.' );
	}

	/**
	 * Test email with special characters.
	 */
	public function test_mail_with_special_characters() {
		$to      = 'test@example.com';
		$subject = 'Special Chars Test áéíóú';
		$content = '<p>Content with special chars: áéíóú ñÑ</p>';

		// Send the email.
		$result = $this->mailer->send_email( $to, $subject, $content );

		// Verify wp_mail was called.
		$this->assertFalse( $result, 'wp_mail should return false.' );
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );

		// Check email content.
		$this->assertStringContainsString( 'áéíóú', $this->captured_mail['message'], 'Special characters are missing.' );
		$this->assertStringContainsString( 'ñÑ', $this->captured_mail['message'], 'Special characters are missing.' );
	}

	/**
	 * Test email template structure.
	 */
	public function test_email_template_structure() {
		$to      = 'test@example.com';
		$subject = 'Template Test';
		$content = '<p>Test Content</p>';

		// Send the email
		$result = $this->mailer->send_email( $to, $subject, $content );

		// Verify wp_mail was called
		$this->assertFalse( $result, 'wp_mail should return false.' );
		$this->assertNotEmpty( $this->captured_mail, 'No email was captured.' );

		// Check HTML template structure
		$html = $this->captured_mail['message'];
		$this->assertStringContainsString( '<!DOCTYPE html>', $html, 'HTML structure is incorrect.' );
		$this->assertStringContainsString( '<meta name="viewport"', $html, 'Viewport meta tag is missing.' );
	}
}
