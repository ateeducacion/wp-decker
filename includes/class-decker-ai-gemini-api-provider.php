<?php
/**
 * Gemini API provider.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side Gemini API provider implementation.
 */
class Decker_AI_Gemini_API_Provider implements Decker_AI_Provider_Interface {

	/**
	 * Base Gemini API endpoint.
	 *
	 * @var string
	 */
	const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @param string $api_key Gemini API key.
	 * @param string $model   Gemini model name.
	 */
	public function __construct( $api_key, $model ) {
		$this->api_key = trim( (string) $api_key );
		$this->model   = trim( (string) $model );
	}

	/**
	 * Improve a task description with Gemini.
	 *
	 * Uses the official Gemini API key header so the credential is sent outside
	 * the request URL.
	 *
	 * @param string $prompt Prompt text to send.
	 * @return string|WP_Error
	 */
	public function improve_description( $prompt ) {
		if ( '' === $this->api_key ) {
			return new WP_Error(
				'decker_ai_missing_api_key',
				__(
					'The Gemini API provider is selected, but no API key has been saved in Decker settings.',
					'decker'
				),
				array( 'status' => 400 )
			);
		}

		$response = wp_remote_post(
			sprintf( self::API_ENDPOINT, rawurlencode( $this->model ) ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'X-Goog-Api-Key' => $this->api_key,
				),
				'body'    => wp_json_encode( $this->get_request_body( $prompt ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'decker_ai_request_failed',
				__(
					'The Gemini API request failed. Please try again in a moment.',
					'decker'
				),
				array( 'status' => 502 )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return $this->get_error_for_status(
				$status_code,
				wp_remote_retrieve_body( $response )
			);
		}

		return $this->parse_response_body( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Build the request body for Gemini.
	 *
	 * @param string $prompt Prompt text.
	 * @return array
	 */
	private function get_request_body( $prompt ) {
		return array(
			'contents'         => array(
				array(
					'parts' => array(
						array(
							'text' => $prompt,
						),
					),
				),
			),
			'generationConfig' => array(
				'temperature' => 0.2,
			),
		);
	}

	/**
	 * Map HTTP status codes to safe user-facing errors.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $response_body Raw response body.
	 * @return WP_Error
	 */
	private function get_error_for_status( $status_code, $response_body ) {
		$error_message = $this->get_api_error_message( $response_body );

		if ( 401 === $status_code || 403 === $status_code ) {
			return new WP_Error(
				'decker_ai_invalid_api_key',
				__(
					'The Gemini API key was rejected. Please ask an administrator to review the saved key.',
					'decker'
				),
				array(
					'status'   => 502,
					'provider' => $error_message,
				)
			);
		}

		if ( 429 === $status_code ) {
			return new WP_Error(
				'decker_ai_rate_limited',
				__(
					'The Gemini API rate limit was reached. Please wait a moment and try again.',
					'decker'
				),
				array(
					'status'   => 429,
					'provider' => $error_message,
				)
			);
		}

		if ( 408 === $status_code || 504 === $status_code ) {
			return new WP_Error(
				'decker_ai_timeout',
				__(
					'The Gemini API took too long to respond. Please try again.',
					'decker'
				),
				array(
					'status'   => 504,
					'provider' => $error_message,
				)
			);
		}

		return new WP_Error(
			'decker_ai_bad_response',
			__(
				'The Gemini API returned an unexpected response. Please try again later.',
				'decker'
			),
			array(
				'status'   => 502,
				'provider' => $error_message,
			)
		);
	}

	/**
	 * Parse the Gemini response body.
	 *
	 * @param string $response_body Raw response body.
	 * @return string|WP_Error
	 */
	private function parse_response_body( $response_body ) {
		$decoded = json_decode( $response_body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'decker_ai_invalid_response',
				__(
					'The Gemini API response could not be parsed.',
					'decker'
				),
				array( 'status' => 502 )
			);
		}

		$text_parts = array();

		if ( ! empty( $decoded['candidates'] ) && is_array( $decoded['candidates'] ) ) {
			foreach ( $decoded['candidates'] as $candidate ) {
				if (
					empty( $candidate['content']['parts'] ) ||
					! is_array( $candidate['content']['parts'] )
				) {
					continue;
				}

				foreach ( $candidate['content']['parts'] as $part ) {
					if ( isset( $part['text'] ) && '' !== trim( $part['text'] ) ) {
						$text_parts[] = $part['text'];
					}
				}
			}
		}

		$content = $this->sanitize_response( implode( "\n", $text_parts ) );

		if ( '' === $content ) {
			return new WP_Error(
				'decker_ai_empty_response',
				__(
					'The Gemini API returned an empty response.',
					'decker'
				),
				array( 'status' => 502 )
			);
		}

		return $content;
	}

	/**
	 * Extract a provider error message when present.
	 *
	 * @param string $response_body Raw response body.
	 * @return string
	 */
	private function get_api_error_message( $response_body ) {
		$decoded = json_decode( $response_body, true );

		if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
			return sanitize_text_field( $decoded['error']['message'] );
		}

		return '';
	}

	/**
	 * Sanitize provider output before returning it to the editor.
	 *
	 * @param string $content Raw provider output.
	 * @return string
	 */
	private function sanitize_response( $content ) {
		$content = trim( (string) $content );
		$content = preg_replace( '/^```[a-z]*\s*/i', '', $content );
		$content = preg_replace( '/\s*```\s*$/i', '', $content );

		return trim( wp_kses_post( $content ) );
	}
}
