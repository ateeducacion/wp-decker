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
	 * @param string $to      The recipient email address.
	 * @param string $subject The email subject.
	 * @param string $content The email content/body.
	 * @param array  $headers Additional headers for the email.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_email( $to, $subject, $content, $headers = array() ) {
		$options = get_option( 'decker_settings', array() );

		// Honor an explicit global opt-out while preserving the historical default.
		if (
			array_key_exists( 'allow_email_notifications', $options ) &&
			! $options['allow_email_notifications']
		) {
			return false;
		}

		$subject = '[Decker] ' . $subject;
		$message = $this->get_email_template( $content );

		$default_headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'MIME-Version: 1.0',
		);
		$headers         = array_merge( $default_headers, $headers );

		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Get the HTML template for emails.
	 *
	 * @param string $content The main content to insert into the template.
	 * @return string The complete HTML email.
	 */
	private function get_email_template( $content ) {
		return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Decker Email</title>
            <style>
                body, html {
                    margin: 0;
                    padding: 0;
                    width: 100%;
                    height: 100%;
                }
                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    padding: 20px;
                    border-radius: 5px;
                    box-shadow: 0 0 10px rgba(0,0,0,0.1);
                }
                .email-header {
                    border-top: 5px solid #0073aa;
                    padding: 10px 0;
                    margin-bottom: 20px;
                }
                .email-content {
                    color: #333333;
                    line-height: 1.6;
                    font-family: Arial, sans-serif;
                }
                .email-footer {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #eeeeee;
                    color: #666666;
                    font-size: 12px;
                    text-align: center;
                }
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
	}
}
