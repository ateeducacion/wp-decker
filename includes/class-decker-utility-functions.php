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
	 * Retur the allowed only simple and safe HTML elements.
	 *
	 * This function allows basic formatting tags such as paragraphs, bold, italics,
	 * lists, tables, links, and spans with specific classes and data attributes.
	 * It excludes potentially dangerous elements like images, embeds, iframes, and scripts.
	 *
	 * @return array The allowrd tags array.
	 */
	public static function get_allowed_tags() {

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

		// Return the defined allowed tags.
		return $allowed_tags;
	}

	/**
	 * Generates HTML for an image with proper sanitization and optional attributes.
	 *
	 * This function is designed to output images that are part of the plugin's assets
	 * without requiring them to be added to the WordPress media library. It ensures
	 * compliance with WordPress standards for sanitization, accessibility, and responsive design.
	 *
	 * @param string $src    The URL of the image.
	 * @param string $alt    Optional. The alt text for the image. Default is an empty string.
	 * @param string $class  Optional. CSS classes to add to the image tag. Default is an empty string.
	 * @param string $width  Optional. The width of the image in pixels. Default is an empty string.
	 * @param string $height Optional. The height of the image in pixels. Default is an empty string.
	 *
	 * @return string The HTML for the image tag.
	 */
	public static function plugin_get_image_html( $src, $alt = '', $class = '', $width = '', $height = '' ) {
		$alt   = esc_attr( $alt );
		$class = esc_attr( $class );
		$src   = esc_url( $src );

		// If you have different image sizes, you can define them here
		// and generate srcset and sizes attributes accordingly.
		$srcset = '';
		$sizes  = '';

		$html = '<img src="' . $src . '" alt="' . $alt . '" class="' . $class . '"';

		if ( $width ) {
			$html .= ' width="' . esc_attr( $width ) . '"';
		}

		if ( $height ) {
			$html .= ' height="' . esc_attr( $height ) . '"';
		}

		if ( $srcset ) {
			$html .= ' srcset="' . esc_attr( $srcset ) . '"';
		}

		if ( $sizes ) {
			$html .= ' sizes="' . esc_attr( $sizes ) . '"';
		}

		$html .= ' />';

		return $html;
	}
}
