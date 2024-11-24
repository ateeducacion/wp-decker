<?php
/**
 * Class to parse emails and extract content and attachments.
 */
class Decker_Email_Parser {

	private $rawEmail;
	private $parsed = array(
		'headers'     => array(),
		'html'        => '',
		'text'        => '',
		'attachments' => array(),
	);

	/**
	 * Initializes the class with raw email content.
	 *
	 * @param string $rawEmail The raw email content as a string.
	 */
	public function __construct( $rawEmail ) {
		$this->rawEmail = $rawEmail;
		$this->parseEmail();
	}

	/**
	 * Parses the raw email content.
	 */
	private function parseEmail() {
		// Split headers and body
		list($headerSection, $bodySection) = $this->splitHeadersAndBody( $this->rawEmail );

		// Parse headers
		$this->parsed['headers'] = $this->parseHeaders( $headerSection );

		// Determine content type
		$contentType = $this->parsed['headers']['Content-Type'] ?? 'text/plain';

		// Check for multipart content
		if ( false !== strpos( $contentType, 'multipart/' ) ) {
			// Extract boundary
			$boundary = $this->getBoundary( $contentType );
			if ( $boundary ) {
				// Parse multipart content
				$this->parseMultipart( $bodySection, $boundary, $contentType );
			}
		} else {
			// Single part email
			$encoding       = $this->parsed['headers']['Content-Transfer-Encoding'] ?? '7bit';
			$decodedContent = $this->decodeContent( $bodySection, $encoding );
			if ( false !== strpos( $contentType, 'text/html' ) ) {
				$this->parsed['html'] .= $decodedContent;
			} else {
				$this->parsed['text'] .= $decodedContent;
			}
		}
	}

	/**
	 * Parses multipart content recursively.
	 *
	 * @param string $body The body content.
	 * @param string $boundary The boundary string.
	 * @param string $parentContentType The content type of the parent part.
	 */
	private function parseMultipart( $body, $boundary, $parentContentType ) {
		// Split body into parts
		$parts = $this->splitBodyByBoundary( $body, $boundary );
		foreach ( $parts as $part ) {
			// Split headers and content
			list($headerSection, $bodyContent) = $this->splitHeadersAndBody( $part );
			$headers                           = $this->parseHeaders( $headerSection );

			// Get content type and encoding
			$contentType = $headers['Content-Type'] ?? 'text/plain';
			$encoding    = $headers['Content-Transfer-Encoding'] ?? '7bit';

			if ( false !== strpos( $contentType, 'multipart/' ) ) {
				// Nested multipart
				$subBoundary = $this->getBoundary( $contentType );
				if ( $subBoundary ) {
					$this->parseMultipart( $bodyContent, $subBoundary, $contentType );
				}
			} else {
				// Decode content
				$decodedContent = $this->decodeContent( $bodyContent, $encoding );

				// Handle content based on type
				if ( false !== strpos( $contentType, 'text/html' ) ) {
					$this->parsed['html'] .= $decodedContent;
				} elseif ( false !== strpos( $contentType, 'text/plain' ) ) {
					$this->parsed['text'] .= $decodedContent;
				} elseif ( isset( $headers['Content-Disposition'] ) && false !== strpos( $headers['Content-Disposition'], 'attachment' ) ) {
					// Handle attachment
					$filename = $this->getFilename( $headers );
					if ( $filename ) {
						$this->parsed['attachments'][] = array(
							'filename' => $filename,
							'content'  => $decodedContent,
							'mimetype' => $contentType,
						);
					}
				} elseif ( false !== strpos( $contentType, 'image/' ) || false !== strpos( $contentType, 'application/' ) ) {
					// Embedded content
					$filename                      = $this->getFilename( $headers ) ?? $this->generateFilename( $contentType );
					$this->parsed['attachments'][] = array(
						'filename' => $filename,
						'content'  => $decodedContent,
						'mimetype' => $contentType,
					);
				}
			}
		}
	}

	/**
	 * Splits raw email into headers and body.
	 *
	 * @param string $rawEmail The raw email content.
	 * @return array An array containing headers and body.
	 */
	private function splitHeadersAndBody( $rawEmail ) {
		$parts = preg_split( "/\r?\n\r?\n/", $rawEmail, 2 );
		return array(
			$parts[0] ?? '',
			$parts[1] ?? '',
		);
	}

	/**
	 * Parses email headers into an associative array.
	 *
	 * @param string $headerText The header section of the email.
	 * @return array Associative array of headers.
	 */
	private function parseHeaders( $headerText ) {
		$headers       = array();
		$lines         = preg_split( "/\r?\n/", $headerText );
		$currentHeader = '';

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s+/', $line ) ) {
				// Continuation of previous header
				$headers[ $currentHeader ] .= ' ' . trim( $line );
			} else {
				$parts = explode( ':', $line, 2 );
				if ( 2 == count( $parts ) ) {
					$currentHeader             = trim( $parts[0] );
					$headers[ $currentHeader ] = trim( $parts[1] );
				}
			}
		}

		return $headers;
	}

	/**
	 * Extracts the boundary string from the Content-Type header.
	 *
	 * @param string $contentType The Content-Type header value.
	 * @return string|null The boundary string or null if not found.
	 */
	private function getBoundary( $contentType ) {
		if ( preg_match( '/boundary="?([^";]+)"?/i', $contentType, $matches ) ) {
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
	private function splitBodyByBoundary( $body, $boundary ) {
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
	private function decodeContent( $content, $encoding ) {
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
	private function getFilename( $headers ) {
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
	 * @param string $contentType The content type.
	 * @return string A generated filename.
	 */
	private function generateFilename( $contentType ) {
		$extension = explode( '/', $contentType )[1] ?? 'dat';
		return 'attachment_' . uniqid() . '.' . $extension;
	}

	/**
	 * Gets the headers of the email.
	 *
	 * @return array The email headers.
	 */
	public function getHeaders() {
		return $this->parsed['headers'];
	}

	/**
	 * Gets the HTML part of the email.
	 *
	 * @return string|null The HTML content or null if not available.
	 */
	public function getHtmlPart() {
		return ! empty( $this->parsed['html'] ) ? $this->parsed['html'] : null;
	}

	/**
	 * Gets the text part of the email.
	 *
	 * @return string|null The text content or null if not available.
	 */
	public function getTextPart() {
		return ! empty( $this->parsed['text'] ) ? $this->parsed['text'] : null;
	}

	public function getBody() {
		// Try to get HTML content first
		$html_content = $this->getHtmlPart();
		if ( ! empty( $html_content ) ) {
			return $this->sanitize_html_content( $html_content );
		}

		// Fall back to text content
		$text_content = $this->getTextPart();
		if ( ! empty( $text_content ) ) {
			return wp_kses_post( nl2br( $text_content ) );
		}

		// If both are empty, return a empty message
		return '';
	}

	private function sanitize_html_content( $html ) {
		// Basic sanitization while preserving most HTML formatting
		return wp_kses_post( $html );
	}


	/**
	 * Gets the attachments from the email.
	 *
	 * @return array An array of attachments.
	 */
	public function getAttachments() {
		return $this->parsed['attachments'];
	}
}
