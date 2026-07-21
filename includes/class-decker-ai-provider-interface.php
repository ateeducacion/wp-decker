<?php
/**
 * AI provider interface.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract for server-side AI providers.
 */
interface Decker_AI_Provider_Interface {

	/**
	 * Improve a task description.
	 *
	 * @param string $prompt Prompt text to send to the provider.
	 * @return string|WP_Error Improved description or error.
	 */
	public function improve_description( $prompt );
}
