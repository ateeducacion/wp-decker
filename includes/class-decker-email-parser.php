<?php
/**
 * File class-decker-email-parser
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class to parse emails and extract content and attachments.
 */
class Decker_Email_Parser {

	/**
	 * The raw email content.
	 *
	 * @var string
	 */
	private $raw_email;

	/**
	 * The parsed email data, including headers, content, and attachments.
	 *
	 * @var array
	 */
	private $parsed = array(
		'headers'     => array(),
		'html'        => '',
		'text'        => '',
		'attachments' => array(),
	);

	/**
	 * Initializes the class with raw email content.
	 *
	 * @param string $raw_email The raw email content as a string.
	 */
	public function __construct( $raw_email ) {
		$this->raw_email = $raw_email;
		$this->parse_email();
	}

	/**
	 * Parses the raw email content.
	 */
	private function parse_email() {
		// Split headers and body.
		list($header_section, $body_section) = $this->split_headers_and_body( $this->raw_email );

		// Parse headers.
		$this->parsed['headers'] = $this->parse_headers( $header_section );

		// Determine content type.
		$content_type = $this->parsed['headers']['Content-Type'] ?? 'text/plain';

		// Check for multipart content.
		if ( false !== strpos( $content_type, 'multipart/' ) ) {
			// Extract boundary.
			$boundary = $this->get_boundary( $content_type );
			if ( $boundary ) {
				// Parse multipart content.
				$this->parse_multipart( $body_section, $boundary, $content_type );
			}
		} else {
			// Single part email.
			$encoding       = $this->parsed['headers']['Content-Transfer-Encoding'] ?? '7bit';
			$decoded_content = $this->decode_content( $body_section, $encoding );
			if ( false !== strpos( $content_type, 'text/html' ) ) {
				$this->parsed['html'] .= $decoded_content;
			} else {
				$this->parsed['text'] .= $decoded_content;
			}
		}
	}

	/**
	 * Parses multipart content recursively.
	 *
	 * @param string $body The body content.
	 * @param string $boundary The boundary string.
	 * @param string $parent_content_type The content type of the parent part.
	 */
	private function parse_multipart( $body, $boundary, $parent_content_type ) {
		// Split body into parts.
		$parts = $this->split_body_by_boundary( $body, $boundary );
		foreach ( $parts as $part ) {
			// Split headers and content.
			list($header_section, $body_content) = $this->split_headers_and_body( $part );
			$headers                           = $this->parse_headers( $header_section );

			// Get content type and encoding.
			$content_type = $headers['Content-Type'] ?? 'text/plain';
			$encoding    = $headers['Content-Transfer-Encoding'] ?? '7bit';

			if ( false !== strpos( $content_type, 'multipart/' ) ) {
				// Nested multipart.
				$sub_boundary = $this->get_boundary( $content_type );
				if ( $sub_boundary ) {
					$this->parse_multipart( $body_content, $sub_boundary, $content_type );
				}
			} else {
				// Decode content.
				$decoded_content = $this->decode_content( $body_content, $encoding );

				// Handle content based on type.
				if ( false !== strpos( $content_type, 'text/html' ) ) {
					$this->parsed['html'] .= $decoded_content;
				} elseif ( false !== strpos( $content_type, 'text/plain' ) ) {
					$this->parsed['text'] .= $decoded_content;
				} elseif ( isset( $headers['Content-Disposition'] ) && false !== strpos( $headers['Content-Disposition'], 'attachment' ) ) {
					// Handle attachment.
					$filename = $this->get_filename( $headers );
					if ( $filename ) {
						$this->parsed['attachments'][] = array(
							'filename' => $filename,
							'content'  => $decoded_content,
							'mimetype' => $content_type,
						);
					}
				} elseif ( false !== strpos( $content_type, 'image/' ) || false !== strpos( $content_type, 'application/' ) ) {
					// Embedded content.
					$filename                      = $this->get_filename( $headers ) ?? $this->generate_filename( $content_type );
					$this->parsed['attachments'][] = array(
						'filename' => $filename,
						'content'  => $decoded_content,
						'mimetype' => $content_type,
					);
				}
			}
		}
	}

	/**
	 * Splits raw email into headers and body.
	 *
	 * @param string $raw_email The raw email content.
	 * @return array An array containing headers and body.
	 */
	private function split_headers_and_body( $raw_email ) {
		$parts = preg_split( "/\r?\n\r?\n/", $raw_email, 2 );
		return array(
			$parts[0] ?? '',
			$parts[1] ?? '',
		);
	}

	/**
	 * Parses email headers into an associative array.
	 *
	 * @param string $header_text The header section of the email.
	 * @return array Associative array of headers.
	 */
	private function parse_headers( $header_text ) {
		$headers       = array();
		$lines         = preg_split( "/\r?\n/", $header_text );
		$current_header = '';

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s+/', $line ) ) {
				// Continuation of previous header.
				$headers[ $current_header ] .= ' ' . trim( $line );
			} else {
				$parts = explode( ':', $line, 2 );
				if ( 2 == count( $parts ) ) {
					$current_header             = trim( $parts[0] );
					$headers[ $current_header ] = trim( $parts[1] );
				}
			}
		}

		return $headers;
	}

	/**
	 * Extracts the boundary string from the Content-Type header.
	 *
	 * @param string $content_type The Content-Type header value.
	 * @return string|null The boundary string or null if not found.
	 */
	private function get_boundary( $content_type ) {
		if ( preg_match( '/boundary="?([^";]+)"?/i', $content_type, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Splits the body into parts using the boundary.
	 *
	 * @param string $body The body of the email.
	 * @param string $boundary The boundary string.
	 * @return array An array of body parts.
	 */
	private function split_body_by_boundary( $body, $boundary ) {
		$boundary = preg_quote( $boundary, '/' );
		$pattern  = "/--$boundary(?:--)?\r?\n/";
		$parts    = preg_split( $pattern, $body );
		return array_filter(
			$parts,
			function ( $part ) {
				return '' !== trim( $part );
			}
		);
	}

	/**
	 * Decodes content based on the encoding specified.
	 *
	 * @param string $content The content to decode.
	 * @param string $encoding The encoding type.
	 * @return string The decoded content.
	 */
	private function decode_content( $content, $encoding ) {
		$encoding = strtolower( $encoding );
		switch ( $encoding ) {
			case 'base64':
				return base64_decode( $content );
			case 'quoted-printable':
				return quoted_printable_decode( $content );
			case '7bit':
			case '8bit':
			default:
				return $content;
		}
	}

	/**
	 * Extracts the filename from the headers.
	 *
	 * @param array $headers The headers array.
	 * @return string|null The filename or null if not found.
	 */
	private function get_filename( $headers ) {
		if ( isset( $headers['Content-Disposition'] ) ) {
			if ( preg_match( '/filename="([^"]+)"/i', $headers['Content-Disposition'], $matches ) ) {
				return $matches[1];
			}
		}
		if ( isset( $headers['Content-Type'] ) ) {
			if ( preg_match( '/name="([^"]+)"/i', $headers['Content-Type'], $matches ) ) {
				return $matches[1];
			}
		}
		return null;
	}

	/**
	 * Generates a filename based on the content type.
	 *
	 * @param string $content_type The content type.
	 * @return string A generated filename.
	 */
	private function generate_filename( $content_type ) {
		$extension = explode( '/', $content_type )[1] ?? 'dat';
		return 'attachment_' . uniqid() . '.' . $extension;
	}

	/**
	 * Gets the headers of the email.
	 *
	 * @return array The email headers.
	 */
	public function get_headers() {
		return $this->parsed['headers'];
	}

	/**
	 * Gets the HTML part of the email.
	 *
	 * @return string|null The HTML content or null if not available.
	 */
	public function get_html_part() {
		return ! empty( $this->parsed['html'] ) ? $this->parsed['html'] : null;
	}

	/**
	 * Gets the text part of the email.
	 *
	 * @return string|null The text content or null if not available.
	 */
	public function get_text_part() {
		return ! empty( $this->parsed['text'] ) ? $this->parsed['text'] : null;
	}

	/**
	 * Gets the body of the email.
	 *
	 * @return string The email body.
	 */
	public function get_body() {
		// Try to get HTML content first.
		$html_content = $this->get_html_part();
		if ( ! empty( $html_content ) ) {
			return $this->sanitize_html_content( $html_content );
		}

		// Fall back to text content.
		$text_content = $this->get_text_part();
		if ( ! empty( $text_content ) ) {
			return wp_kses_post( nl2br( $text_content ) );
		}

		// If both are empty, return a empty message.
		return '';
	}

	/**
	 * Sanitize the HTML content.
	 *
	 * @param string $html The html content.
	 * @return string.
	 */
	private function sanitize_html_content( $html ) {
		// Basic sanitization while preserving most HTML formatting.
		return wp_kses_post( $html );
	}


	/**
	 * Gets the attachments from the email.
	 *
	 * @return array An array of attachments.
	 */
	public function get_attachments() {
		return $this->parsed['attachments'];
	}
}
