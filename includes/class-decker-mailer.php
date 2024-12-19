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
    public function send_email($to, $subject, $content) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // Add Decker prefix to subject
        $subject = '[Decker] ' . $subject;
        
        // Get the HTML template
        $message = $this->get_email_template($content);
        
        // Send the email using WordPress function
        return wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Get the HTML template for emails.
     *
     * @param string $content The main content to be inserted in the template
     * @return string The complete HTML email
     */
    private function get_email_template($content) {
        // Get plugin URL for images
        $plugin_url = plugins_url('', dirname(__FILE__));
        
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
                    <img src="' . $plugin_url . '/admin/images/decker-logo.png" alt="Decker Logo" style="max-width: 200px;">
                </div>
                
                <div style="color: #333333; line-height: 1.6;">
                    ' . $content . '
                </div>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eeeeee; color: #666666; font-size: 12px; text-align: center;">
                    <p>Este correo ha sido enviado automáticamente por Decker</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $template;
    }
}
