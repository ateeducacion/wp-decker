<?php
/**
 * Deterministic conversion of legacy description HTML; no database writes.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

require_once __DIR__ . '/class-decker-task-description-html.php';
require_once __DIR__ . '/class-decker-task-description-format.php';
require_once __DIR__ . '/class-decker-task-description-lists.php';

/** Converts recognised Quill structures without guessing missing information. */
class Decker_Task_Description_Converter {

	/**
	 * Convert a fragment, retaining the original bytes when no change is needed.
	 *
	 * @param string $html Stored, unslashed HTML.
	 * @return string Classic HTML.
	 * @throws UnexpectedValueException When conversion is ambiguous.
	 */
	public static function convert( string $html ): string {
		if ( ! str_contains( $html, 'ql-' ) && ! str_contains( $html, 'data-list' ) ) {
			return $html;
		}
		if ( ! class_exists( 'DOMDocument' ) || ! preg_match( '//u', $html ) ) {
			throw new UnexpectedValueException( 'DOM and valid UTF-8 are required.' );
		}
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$document = new DOMDocument( '1.0', 'UTF-8' );
		$document->preserveWhiteSpace = true;
		try {
			$loaded = $document->loadHTML( '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
			$errors = array_filter(
				libxml_get_errors(),
				static function ( $error ) {
					// libxml's HTML4 parser reports supported HTML5 elements as unknown.
					return 801 !== $error->code || ! preg_match( '/^Tag (?:figure|figcaption|section|article|aside|header|footer|main|nav|details|summary|video|audio|source|track|picture|time|mark|ruby|rt|rp|wbr|bdi|canvas|data|meter|progress|hgroup) invalid/', $error->message );
				}
			);
			if ( ! $loaded || $errors ) {
				throw new UnexpectedValueException( 'Malformed legacy HTML requires review.' );
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$body    = $document->getElementsByTagName( 'body' )->item( 0 );
		$changed = false;
		self::walk( $body, $changed );
		if ( ! $changed ) {
			return $html;
		}
		$output = '';
		foreach ( $body->childNodes as $child ) {
			$output .= Decker_Task_Description_Html::serialize( $child );
		}
		return $output;
	}

	/**
	 * Traverse the fragment while keeping adjacent-list numbering local.
	 *
	 * @param DOMNode $parent Parent node.
	 * @param bool    $changed Whether anything changed.
	 */
	private static function walk( DOMNode $parent, bool &$changed ): void {
		$counters = array();
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$classes = Decker_Task_Description_Html::classes( $node );
			if ( in_array( 'ql-ui', $classes, true ) ) {
				self::remove_ui( $node, $classes );
				$changed = true;
				continue;
			}
			if ( in_array( 'ql-code-block-container', $classes, true ) ) {
				Decker_Task_Description_Format::code_block( $node );
				$changed = true;
				continue;
			}
			self::validate_state( $node );
			if ( in_array( $node->tagName, array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				$counters = array();
			}
			self::walk_element( $node, $counters, $changed );
			Decker_Task_Description_Format::apply( $node, $changed );
		}
	}

	/**
	 * List indentation must be captured before formatting removes its classes.
	 *
	 * @param DOMElement $node Element to recurse into.
	 * @param array      $counters Adjacent-list ordinals.
	 * @param bool       $changed Whether anything changed.
	 */
	private static function walk_element( DOMElement $node, array &$counters, bool &$changed ): void {
		if ( ! in_array( $node->tagName, array( 'ul', 'ol' ), true ) ) {
			self::walk( $node, $changed );
			return;
		}
		list( $items, $rebuild ) = Decker_Task_Description_Lists::inspect( $node );
		self::walk( $node, $changed );
		if ( $rebuild ) {
			Decker_Task_Description_Format::apply( $node, $changed );
			Decker_Task_Description_Lists::rebuild( $node, $items, $counters );
			$changed = true;
		}
	}

	/**
	 * Never silently interpret an unknown data-list value as a checkbox.
	 *
	 * @param DOMElement $node Element to validate.
	 * @throws UnexpectedValueException When a list state is unknown.
	 */
	private static function validate_state( DOMElement $node ): void {
		if ( 'li' === $node->tagName && $node->hasAttribute( 'data-list' ) && ! in_array( $node->getAttribute( 'data-list' ), array( 'checked', 'unchecked', 'bullet', 'ordered' ), true ) ) {
			throw new UnexpectedValueException( 'Unknown list state requires review.' );
		}
	}

	/**
	 * Remove only the empty interface span identified in historical Quill lists.
	 *
	 * @param DOMElement $node Candidate UI span.
	 * @param array      $classes Original classes.
	 * @throws UnexpectedValueException When the node may contain actual content.
	 */
	private static function remove_ui( DOMElement $node, array $classes ): void {
		$owner = $node->parentNode;
		$interface = 1 === count( $classes ) && 1 === $node->attributes->length && 'span' === $node->tagName && ! $node->hasChildNodes();
		$known_owner = $owner instanceof DOMElement && 'li' === $owner->tagName && in_array( $owner->getAttribute( 'data-list' ), array( 'checked', 'unchecked', 'bullet', 'ordered' ), true );
		if ( ! $interface || ! $known_owner ) {
			throw new UnexpectedValueException( 'Unrecognised Quill UI node requires review.' );
		}
		$owner->removeChild( $node );
	}
}
