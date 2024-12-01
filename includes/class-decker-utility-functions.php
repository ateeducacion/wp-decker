<?php
/**
 * Utility functions for the Decker plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Decker_Utility_Functions
 *
 * Contains utility functions used throughout the Decker plugin.
 */
class Decker_Utility_Functions {

	/**
	 * Check if the current user has at least the required role.
	 *
	 * @return bool True if the user has the required role or higher, false otherwise.
	 */
	public static function current_user_has_at_least_minimum_role() {
		// Get the saved user profile role from plugin options, default to 'editor'.
		$options = get_option( 'decker_settings', array() );
		$required_role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// WordPress role hierarchy, ordered from lowest to highest.
		$role_hierarchy = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		// Determine the index of the required role.
		$required_index = array_search( $required_role, $role_hierarchy );

		if ( false === $required_index ) {
			// Invalid role in settings, fallback to the default.
			return false;
		}

		// Check each role of the current user.
		foreach ( wp_get_current_user()->roles as $user_role ) {
			$user_index = array_search( $user_role, $role_hierarchy );

			if ( false !== $user_index && $user_index >= $required_index ) {
				return true; // User has the required role or higher.
			}
		}

		return false; // User does not meet the minimum role requirement.
	}

	/**
	 * Sanitize HTML content to allow only simple and safe HTML elements.
	 *
	 * This function allows basic formatting tags such as paragraphs, bold, italics,
	 * lists, tables, links, and spans with specific classes and data attributes.
	 * It excludes potentially dangerous elements like images, embeds, iframes, and scripts.
	 *
	 * @param string $content The HTML content to sanitize.
	 * @return string The sanitized HTML content.
	 */
	public static function sanitize_html_content( $content ) {

		// Define the allowed CSS tags.
	    $allowed_css_properties = array(
	        'background-color',
	        'color',
	        'font-size',
	        'font-family',
	        'font-weight',
	        'text-decoration',
	    );

		// Define the allowed HTML tags and their permitted attributes.
		$allowed_tags = array(
			'p' => array(
				'class' => array(),
				'style' => $allowed_css_properties,
			),
			'br' => array(),
			'strong' => array(),
			'b' => array(),
			'em' => array(),
			's' => array(),
			'u' => array(),
			'ul' => array(),
			'ol' => array(),
			'li' => array(
				'data-list' => array(),
			),
			'table' => array(
				'style' => $allowed_css_properties,
				'border' => array(),
				'cellpadding' => array(),
				'cellspacing' => array(),
			),
			'thead' => array(),
			'tbody' => array(),
			'tr' => array(),
			'th' => array(
				'style' => $allowed_css_properties,
				'colspan' => array(),
				'rowspan' => array(),
			),
			'td' => array(
				'style' => $allowed_css_properties,
				'colspan' => array(),
				'rowspan' => array(),
			),
			'a' => array(
				'href' => array(),
				'title' => array(),
				'target' => array(),
				'rel' => array(),
			),
			'span' => array(
				'class' => array(),
				'style' => $allowed_css_properties,
			),
			'blockquote' => array(),
			'code' => array(),
			'pre' => array(),
			// Add more basic tags if needed.
		);

		// First, sanitize the content using wp_kses with the defined allowed tags.
		$sanitized_content = wp_kses( $content, $allowed_tags );

		return $sanitized_content;
	}
}
