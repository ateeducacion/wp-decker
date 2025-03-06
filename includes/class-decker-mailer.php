<?php
/**
 * The email handling functionality of the plugin.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * Handle all email operations for Decker.
 *
 * This class defines all code necessary to send formatted HTML emails.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Decker_Mailer {

	/**
	 * Send an HTML email with the Decker template.
	 *
	 * @param string $to The recipient email address.
	 * @param string $subject The email subject.
	 * @param string $content The email content/body.
	 * @param array  $headers Additional headers for the email.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_email( $to, $subject, $content, $headers = array() ) {

		// Add a prefix to the subject line.
		$subject = '[Decker] ' . $subject;

		// Build the HTML email template.
		$message = $this->get_email_template( $content );

		// Configure headers for HTML content.
		$default_headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'MIME-Version: 1.0',
		);

		// Merge default headers with any additional headers provided.
		$headers = array_merge( $default_headers, $headers );

		// Send the email using WordPress's wp_mail function.
		$sent = wp_mail( $to, $subject, $message, $headers );

		return $sent;
	}

	/**
	 * Get the HTML template for emails.
	 *
	 * This method generates the complete HTML structure for the email.
	 * It includes a white frame, a blue top border, and the provided content.
	 *
	 * @param string $content The main content to be inserted in the template.
	 * @return string The complete HTML email.
	 */
	private function get_email_template( $content ) {
		$body = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Decker Email</title>
            <style>
                /* Basic style reset */
                body, html {
                    margin: 0;
                    padding: 0;
                    width: 100%;
                    height: 100%;
                }
                /* Main email container styles */
                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    padding: 20px;
                    border-radius: 5px;
                    box-shadow: 0 0 10px rgba(0,0,0,0.1);
                }
                /* Blue top border */
                .email-header {
                    border-top: 5px solid #0073aa; /* Blue color */
                    padding: 10px 0;
                    margin-bottom: 20px;
                }
                /* Main content styles */
                .email-content {
                    color: #333333;
                    line-height: 1.6;
                    font-family: Arial, sans-serif;
                }
                /* Footer styles */
                .email-footer {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #eeeeee;
                    color: #666666;
                    font-size: 12px;
                    text-align: center;
                }
                /* Responsive design adjustments */
                @media only screen and (max-width: 600px) {
                    .email-container {
                        padding: 15px;
                    }
                }
            </style>
        </head>
        <body style="background-color: #f4f4f4;">
            <div class="email-container">
                <div class="email-header">
                    <!-- Optional header content -->
                </div>
                
                <div class="email-content">
                    ' . wp_kses_post( $content ) . '
                </div>
                
                <div class="email-footer">
                    <p>' . esc_html__( 'This email was automatically sent by Decker', 'decker' ) . '</p>
                </div>
            </div>
        </body>
        </html>';

		return $body;
	}
}
