<?php
/**
 * HTML serialization and conservative preservation comparisons.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

/** HTML serialization and conservative preservation comparisons. */
class Decker_Task_Description_Html {

	/**
	 * Read element classes.
	 *
	 * @param DOMElement $node Element.
	 * @return array Classes.
	 */
	public static function classes( DOMElement $node ): array {
		return preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Serialize HTML without libxml silently percent-encoding link attributes.
	 *
	 * @param DOMNode $node Node to serialize.
	 * @return string HTML with explicit non-void closing tags.
	 */
	public static function serialize( DOMNode $node ): string {
		if ( $node instanceof DOMText ) {
			return htmlspecialchars( $node->nodeValue, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
		}
		if ( $node instanceof DOMComment ) {
			return '<!--' . $node->nodeValue . '-->';
		}
		if ( ! $node instanceof DOMElement ) {
			return '';
		}
		$html = '<' . $node->tagName;
		foreach ( $node->attributes as $attribute ) {
			$html .= ' ' . $attribute->name . '="' . htmlspecialchars( $attribute->value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . '"';
		}
		$html .= '>';
		if ( in_array( $node->tagName, array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ), true ) ) {
			return $html;
		}
		foreach ( $node->childNodes as $child ) {
			$html .= self::serialize( $child );
		}
		return $html . '</' . $node->tagName . '>';
	}

	/**
	 * Ignore only harmless serialization of attribute order/entities/style endings.
	 *
	 * @param string $before Converted HTML.
	 * @param string $after KSES result.
	 * @return bool Whether KSES retained the historical structure and attributes.
	 */
	public static function same_safe_html( string $before, string $after ): bool {
		if ( $before === $after ) {
			return true;
		}
		$canonical = static function ( $html ) {
			$dom = new DOMDocument( '1.0', 'UTF-8' );
			$previous = libxml_use_internal_errors( true );
			try {
				$loaded = $dom->loadHTML( '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
			} finally {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous );
			}
			if ( ! $loaded || ! $dom->getElementsByTagName( 'body' )->length ) {
				return false;
			}
			foreach ( ( new DOMXPath( $dom ) )->query( '//*[@style]' ) as $node ) {
				$node->setAttribute( 'style', self::canonical_style( $node->getAttribute( 'style' ) ) );
			}
			foreach ( ( new DOMXPath( $dom ) )->query( '//*[@href or @src]' ) as $node ) {
				foreach ( array( 'href', 'src' ) as $attribute ) {
					if ( $node->hasAttribute( $attribute ) ) {
						$node->setAttribute( $attribute, trim( $node->getAttribute( $attribute ) ) );
					}
				}
			}
			return $dom->getElementsByTagName( 'body' )->item( 0 )->C14N();
		};
		$original = $canonical( $before );
		return is_string( $original ) && $original === $canonical( $after );
	}

	/**
	 * Ignore separator whitespace without changing spaces inside CSS values.
	 *
	 * @param string $css Inline CSS.
	 * @return string Canonical declarations used only for preservation checks.
	 */
	private static function canonical_style( string $css ): string {
		$parts = array();
		$part = '';
		$quote = '';
		$depth = 0;
		$escaped = false;
		foreach ( str_split( $css ) as $character ) {
			if ( self::consume_string( $character, $quote, $escaped ) ) {
				$part .= $character;
				continue;
			}
			$depth += match ( $character ) {
				'(' => 1,
				')' => -1,
				default => 0,
			};
			if ( ';' === $character && 0 === $depth ) {
				self::append_declaration( $parts, $part );
				$part = '';
				continue;
			}
			$part .= $character;
		}
		if ( $quote || $depth || $escaped ) {
			return $css;
		}
		self::append_declaration( $parts, $part );
		return implode( ';', $parts );
	}

	/**
	 * Consume CSS quoting/escaping before interpreting declaration separators.
	 *
	 * @param string $character Current byte.
	 * @param string $quote Current quote delimiter.
	 * @param bool   $escaped Whether the preceding byte escaped this one.
	 * @return bool Whether this byte is part of a string or escape.
	 */
	private static function consume_string( string $character, string &$quote, bool &$escaped ): bool {
		if ( $escaped ) {
			$escaped = false;
			return true;
		}
		if ( '\\' === $character ) {
			$escaped = true;
			return true;
		}
		if ( $quote ) {
			if ( $character === $quote ) {
				$quote = '';
			}
			return true;
		}
		if ( in_array( $character, array( '"', "'" ), true ) ) {
			$quote = $character;
			return true;
		}
		return false;
	}

	/**
	 * Retain every nonempty declaration in its original order.
	 *
	 * @param array  $parts Accumulated declarations.
	 * @param string $part Current declaration.
	 */
	private static function append_declaration( array &$parts, string $part ): void {
		if ( '' !== trim( $part ) ) {
			$parts[] = trim( $part );
		}
	}
}
