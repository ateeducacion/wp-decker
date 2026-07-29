<?php
/**
 * Resolves which board an incoming e-mail's task belongs on.
 *
 * The sender can address a board with a "board:slug" directive at the start
 * of the subject; otherwise the task lands on their default board. This class
 * owns that parsing and the fallbacks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Email_Board_Resolver
 */
class Decker_Email_Board_Resolver {

	/**
	 * Resolves the target board and the cleaned subject for a task created from email.
	 *
	 * Supports an optional board directive at the start of the subject:
	 *   [slug] Title         -> board with slug "slug" (shorthand).
	 *   [board:slug] Title   -> same, explicit qualifier.
	 *   [tablero:slug] Title -> same, Spanish qualifier.
	 *
	 * When the directive matches an existing board, that board is used and the directive is
	 * stripped from the title. When no directive is present, the sender's default board is
	 * used. An explicit "board:"/"tablero:" qualifier that references a missing board fails
	 * with 'invalid_board_slug'; a bare "[slug]" that matches no board is treated as a literal
	 * title and falls back to the default board, so legitimate bracketed subject prefixes
	 * (e.g. "[URGENT] ...") are never dropped.
	 *
	 * @param string $subject   The raw email subject.
	 * @param int    $author_id The sender WordPress user ID.
	 * @return array|WP_Error An array with 'board_id', 'subject' and 'source' on success,
	 *                        or a WP_Error on failure.
	 */
	public function resolve_board_and_subject_from_email( string $subject, int $author_id ) {
		$directive = $this->parse_board_directive_from_subject( $subject );

		$board_id = null;
		$title    = $directive['original'];
		$source   = 'default';

		if ( null !== $directive['slug'] ) {
			$board_id = $this->get_board_by_slug( $directive['slug'] );

			if ( null !== $board_id ) {
				// Directive matched a board: route there and strip it from the title.
				$title  = $directive['title'];
				$source = 'subject';
			} elseif ( $directive['qualified'] ) {
				// Explicit "board:"/"tablero:" directive with no matching board: fail loudly.
				return new WP_Error(
					'invalid_board_slug',
					'The board referenced in the subject does not exist',
					array( 'status' => 400 )
				);
			}
			// Bare "[slug]" with no matching board: keep $board_id null so the default
			// board is used below, preserving the original subject as the title.
		}

		if ( null === $board_id ) {
			$board_id = $this->get_default_board_for_user( $author_id );
			if ( is_wp_error( $board_id ) ) {
				return $board_id;
			}
		}

		if ( '' === $title ) {
			return new WP_Error(
				'missing_field',
				'The title is required',
				array( 'status' => 400 )
			);
		}

		return array(
			'board_id' => $board_id,
			'subject'  => $title,
			'source'   => $source,
		);
	}

	/**
	 * Parses an optional board directive from the start of an email subject.
	 *
	 * Recognizes "[slug]", "[board:slug]" and "[tablero:slug]" at the beginning of the
	 * subject. The slug is normalized with sanitize_title() so matching is case-insensitive.
	 *
	 * @param string $subject The raw email subject.
	 * @return array {
	 *     @type string|null $slug      The normalized board slug, or null when no usable directive is present.
	 *     @type bool        $qualified Whether an explicit "board:"/"tablero:" qualifier was used.
	 *     @type string      $title     The title with the directive stripped (used when the board matches).
	 *     @type string      $original  The trimmed subject with the directive preserved (fallback title).
	 * }
	 */
	private function parse_board_directive_from_subject( string $subject ): array {
		$trimmed = trim( $subject );

		$result = array(
			'slug'      => null,
			'qualified' => false,
			'title'     => $trimmed,
			'original'  => $trimmed,
		);

		// Match a leading "[ ... ]" directive followed by the remaining title.
		if ( ! preg_match( '/^\[\s*([^\]]+?)\s*\]\s*(.*)$/s', $trimmed, $matches ) ) {
			return $result;
		}

		$directive = $matches[1];
		$remainder = trim( $matches[2] );

		// Strip an optional "board:" or "tablero:" qualifier.
		$qualified = false;
		if ( preg_match( '/^(?:board|tablero)\s*:\s*(.+)$/i', $directive, $qualifier ) ) {
			$directive = $qualifier[1];
			$qualified = true;
		}

		$slug = sanitize_title( $directive );
		if ( '' === $slug ) {
			// Not a usable directive (e.g. "[ ]" or "[!!!]"); treat the subject as having none.
			return $result;
		}

		$result['slug']      = $slug;
		$result['qualified'] = $qualified;
		$result['title']     = $remainder;

		return $result;
	}

	/**
	 * Returns the sender's default board, preserving the historical error contract.
	 *
	 * @param int $user_id The sender WordPress user ID.
	 * @return int|WP_Error The board term ID, or a WP_Error when it is missing or invalid.
	 */
	private function get_default_board_for_user( int $user_id ) {
		$default_board = (int) get_user_meta( $user_id, 'decker_default_board', true );
		if ( $default_board <= 0 || ! term_exists( $default_board, 'decker_board' ) ) {
			return new WP_Error( 'invalid_board', 'Invalid default board' );
		}
		return $default_board;
	}

	/**
	 * Returns the term ID of a decker_board by slug.
	 *
	 * @param string $slug The board slug.
	 * @return int|null The board term ID, or null when no board matches.
	 */
	private function get_board_by_slug( string $slug ) {
		$term = get_term_by( 'slug', $slug, 'decker_board' );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		return (int) $term->term_id;
	}
}
