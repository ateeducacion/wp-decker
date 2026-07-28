<?php
/**
 * Characterization tests for parsing Gemini API responses.
 *
 * The response body comes from a third-party service, so the parser has to
 * cope with malformed JSON, missing candidates and empty parts without
 * handing anything unsafe back to the editor.
 *
 * @package Decker
 */

class DeckerAIGeminiApiProviderTest extends Decker_Test_Base {

	/**
	 * Instance under test.
	 *
	 * @var Decker_AI_Gemini_API_Provider
	 */
	private $provider;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->provider = new Decker_AI_Gemini_API_Provider( 'test-key', 'gemini-test' );
	}

	/**
	 * Invoke the private response parser.
	 *
	 * @param string $body Raw response body.
	 * @return string|WP_Error Parsed content or the error.
	 */
	private function parse( $body ) {
		$method = new ReflectionMethod( $this->provider, 'parse_response_body' );
		$method->setAccessible( true );

		return $method->invoke( $this->provider, $body );
	}

	/**
	 * Build a Gemini response body from a list of candidate part texts.
	 *
	 * @param array<int, array<int, string>> $candidates Text parts per candidate.
	 * @return string Encoded response body.
	 */
	private function response_with( array $candidates ) {
		$encoded = array();

		foreach ( $candidates as $parts ) {
			$encoded[] = array(
				'content' => array(
					'parts' => array_map(
						function ( $text ) {
							return array( 'text' => $text );
						},
						$parts
					),
				),
			);
		}

		return wp_json_encode( array( 'candidates' => $encoded ) );
	}

	/**
	 * A body that is not JSON is reported as unparseable.
	 */
	public function test_rejects_non_json_body() {
		$result = $this->parse( 'upstream gateway error' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_invalid_response', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * A JSON scalar is not a usable response either.
	 */
	public function test_rejects_json_scalar_body() {
		$result = $this->parse( '"just a string"' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_invalid_response', $result->get_error_code() );
	}

	/**
	 * A well-formed body carrying no candidates yields the empty-response error.
	 */
	public function test_rejects_body_without_candidates() {
		$result = $this->parse( wp_json_encode( array( 'candidates' => array() ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_empty_response', $result->get_error_code() );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Candidates without usable parts are skipped rather than fatal.
	 */
	public function test_rejects_candidates_without_parts() {
		$body = wp_json_encode(
			array(
				'candidates' => array(
					array( 'finishReason' => 'SAFETY' ),
					array( 'content' => array( 'parts' => array() ) ),
				),
			)
		);

		$result = $this->parse( $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_empty_response', $result->get_error_code() );
	}

	/**
	 * Whitespace-only parts do not count as content.
	 */
	public function test_rejects_whitespace_only_parts() {
		$result = $this->parse( $this->response_with( array( array( '   ', "\n" ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_empty_response', $result->get_error_code() );
	}

	/**
	 * A single text part is returned as the content.
	 */
	public function test_returns_single_part_text() {
		$this->assertSame(
			'Improved description.',
			$this->parse( $this->response_with( array( array( 'Improved description.' ) ) ) )
		);
	}

	/**
	 * Several parts and candidates are joined with newlines, in order.
	 */
	public function test_joins_parts_across_candidates_in_order() {
		$result = $this->parse(
			$this->response_with(
				array(
					array( 'first', 'second' ),
					array( 'third' ),
				)
			)
		);

		$this->assertSame( "first\nsecond\nthird", $result );
	}

	/**
	 * Empty parts are dropped while the surrounding text is kept.
	 */
	public function test_skips_empty_parts_between_content() {
		$result = $this->parse( $this->response_with( array( array( 'kept', '  ', 'also kept' ) ) ) );

		$this->assertSame( "kept\nalso kept", $result );
	}

	/**
	 * Markdown code fences wrapped around the answer are stripped.
	 */
	public function test_strips_code_fences() {
		$result = $this->parse( $this->response_with( array( array( "```html\n<p>Body</p>\n```" ) ) ) );

		$this->assertSame( '<p>Body</p>', $result );
	}

	/**
	 * Unsafe markup is removed before the content reaches the editor.
	 */
	public function test_sanitizes_unsafe_markup() {
		$result = $this->parse(
			$this->response_with( array( array( '<p>Safe</p><script>alert(1)</script>' ) ) )
		);

		$this->assertStringContainsString( '<p>Safe</p>', $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	/**
	 * Sanitizing keeps the inner text of a stripped tag as plain text.
	 *
	 * wp_kses_post() removes the markup but not what it wrapped, so a
	 * script-only response survives as inert text rather than becoming empty.
	 */
	public function test_script_only_content_survives_as_inert_text() {
		$result = $this->parse( $this->response_with( array( array( '<script>alert(1)</script>' ) ) ) );

		$this->assertSame( 'alert(1)', $result );
	}

	/**
	 * Content that sanitizes away entirely is reported as empty.
	 *
	 * A reply consisting only of a code-fence marker has nothing left once the
	 * fence is stripped.
	 */
	public function test_reports_empty_when_sanitizing_removes_everything() {
		$result = $this->parse( $this->response_with( array( array( '```' ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'decker_ai_empty_response', $result->get_error_code() );
	}
}
