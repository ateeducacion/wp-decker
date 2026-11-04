<?php
/** Structural assertions shared by fixtures and the private export check. */
class Decker_Description_Snapshot {
	/** Existing inline CSS must remain on a corresponding element, even with added classes translated to CSS. */
	public static function preserves_inline_styles( string $before, string $after ): bool {
		$styles = static function ( $html ) {
			$nodes = array();
			foreach ( ( new DOMXPath( self::document( $html ) ) )->query( '//*[@style]' ) as $node ) {
				$tag = str_contains( $node->getAttribute( 'class' ), 'ql-code-block-container' ) ? 'pre' : $node->tagName;
				$nodes[] = array( $tag, rtrim( trim( $node->getAttribute( 'style' ) ), '; ' ) );
			}
			return $nodes;
		};
		$available = $styles( $after );
		foreach ( $styles( $before ) as list( $tag, $style ) ) {
			if ( '' === $style ) continue;
			$found = false;
			foreach ( $available as $index => list( $candidate, $value ) ) {
				if ( $candidate === $tag && ( $value === $style || str_starts_with( $value, $style . ';' ) ) ) {
					unset( $available[ $index ] );
					$found = true;
					break;
				}
			}
			if ( ! $found ) return false;
		}
		return true;
	}

	/** Meaningful document text with block boundaries and no editor UI. */
	public static function text( string $html ): string {
		$dom = self::document( $html );
		$walk = static function ( DOMNode $node ) use ( &$walk ): string {
			if ( $node instanceof DOMText ) return $node->nodeValue;
			if ( $node instanceof DOMElement && 'ql-ui' === $node->getAttribute( 'class' ) && !$node->hasChildNodes() ) return '';
			$text = '';
			foreach ( $node->childNodes as $child ) $text .= $walk( $child );
			return in_array( $node->nodeName, array( 'p', 'div', 'li', 'ul', 'ol', 'br', 'pre', 'h1', 'h2', 'h3', 'h4', 'hr', 'td', 'th', 'tr', 'blockquote' ), true ) ? "\n" . $text . "\n" : $text;
		};
		return trim( preg_replace( '/\s+/u', ' ', $walk( $dom->getElementsByTagName( 'body' )->item( 0 ) ) ) );
	}

	/** Exact code text, including the line breaks encoded by Quill nodes. */
	public static function code( string $html ): array {
		$dom = self::document( $html );
		$result = array();
		foreach ( ( new DOMXPath( $dom ) )->query( '//pre|//div[contains(concat(" ",normalize-space(@class)," ")," ql-code-block-container ")]' ) as $node ) {
			if ( 'pre' === $node->nodeName ) {
				$result[] = $node->textContent;
			} else {
				$lines = array();
				foreach ( $node->childNodes as $child ) if ( $child instanceof DOMElement ) $lines[] = $child->textContent;
				$result[] = implode( "\n", $lines );
			}
		}
		return $result;
	}

	/** All document links/images, including those outside list items. */
	public static function resources( string $html ): array {
		$dom = self::document( $html );
		$result = array();
		foreach ( ( new DOMXPath( $dom ) )->query( '//body//a|//body//img' ) as $node ) {
			$attributes = array();
			foreach ( $node->attributes as $attr ) if ( !in_array( $attr->name, array('class','style'), true ) ) $attributes[$attr->name] = $attr->value;
			ksort($attributes);
			$result[] = array($node->nodeName,$attributes,$node->textContent);
		}
		return $result;
	}
	/** Read each item's identity, semantic type, parent, state and inline tree. */
	public static function items( string $html ): array {
		$dom = self::document( $html );
		$xpath = new DOMXPath( $dom );
		$indices = array();
		$levels = array();
		$result = array();
		foreach ( $xpath->query( '//body//li' ) as $item ) {
			$index = count( $result );
			$parent = null;
			for ( $node = $item->parentNode; $node; $node = $node->parentNode ) {
				if ( 'li' === $node->nodeName ) {
					$parent = $indices[ $node->getNodePath() ];
					break;
				}
			}
			$list = $item->parentNode->getNodePath();
			$level = preg_match( '/\bql-indent-(\d+)\b/', $item->getAttribute( 'class' ), $match ) ? (int) $match[1] : 0;
			if ( $level > 0 ) {
				$parent = $levels[ $list ][ $level - 1 ] ?? 'missing';
			}
			$levels[ $list ][ $level ] = $index;
			foreach ( array_keys( $levels[ $list ] ) as $depth ) {
				if ( $depth > $level ) {
					unset( $levels[ $list ][ $depth ] );
				}
			}
			$state = $item->getAttribute( 'data-list' );
			$check = in_array( $state, array( 'checked', 'unchecked' ), true );
			$result[] = array(
				'parent' => $parent,
				'type' => $check ? 'checklist' : ( 'ordered' === $state || ( '' === $state && 'ol' === $item->parentNode->nodeName ) ? 'ordered' : 'bullet' ),
				'state' => $check ? $state : null,
				'inline' => self::children( $item ),
			);
			$indices[ $item->getNodePath() ] = $index;
		}
		return $result;
	}

	/** Parse without loading WordPress or executing any HTML. */
	public static function document( string $html ): DOMDocument {
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$previous = libxml_use_internal_errors( true );
		try {
			$dom->loadHTML( '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>', LIBXML_NONET );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return $dom;
	}

	/** Keep inline tags/attributes/text, excluding nested lists and known UI. */
	private static function children( DOMNode $parent ): array {
		$result = array();
		foreach ( $parent->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				if ( in_array( $child->tagName, array( 'ul', 'ol' ), true ) || ( 'span' === $child->tagName && 'ql-ui' === $child->getAttribute( 'class' ) && ! $child->hasChildNodes() ) ) {
					continue;
				}
				$attributes = array();
				foreach ( $child->attributes as $attribute ) {
					if ( ! in_array( $attribute->name, array( 'class', 'style' ), true ) ) {
						$attributes[ $attribute->name ] = $attribute->value;
					}
				}
				ksort( $attributes );
				$result[] = array( $child->tagName, $attributes, self::children( $child ) );
			} elseif ( $child instanceof DOMText ) {
				if ( $result && is_string( end( $result ) ) ) {
					$result[ count( $result ) - 1 ] .= $child->nodeValue;
				} else {
					$result[] = $child->nodeValue;
				}
			}
		}
		return $result;
	}
}
