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
	 * @param string $to The recipient email address
	 * @param string $subject The email subject
	 * @param string $content The email content/body
	 * @return bool Whether the email was sent successfully
	 */
	public function send_email( $to, $subject, $content ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Add Decker prefix to subject
		$subject = '[Decker] ' . $subject;

		// Get the HTML template
		$message = $this->get_email_template( $content );

		// Send the email using WordPress function
		return wp_mail( $to, $subject, $message, $headers );
	}

	/**
	 * Get the HTML template for emails.
	 *
	 * @param string $content The main content to be inserted in the template
	 * @return string The complete HTML email
	 */
	private function get_email_template( $content ) {
		// Get plugin URL for images
		$plugin_url = plugins_url( '', __DIR__ );

		$template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">
                <div style="text-align: center; margin-bottom: 20px;">
                    <img src="data:image/png;base64,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" alt="Decker Logo" />
                </div>
                
                <div style="color: #333333; line-height: 1.6;">
                    ' . $content . '
                </div>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eeeeee; color: #666666; font-size: 12px; text-align: center;">
                    <p>' . esc_html__('This email was automatically sent by Decker', 'decker') . '</p>
                </div>
            </div>
        </body>
        </html>';

		return $template;
	}
}
