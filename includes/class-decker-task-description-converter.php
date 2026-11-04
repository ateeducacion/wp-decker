<?php
/**
 * Deterministic conversion of legacy description HTML; no database writes.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

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
			$output .= self::serialize( $child );
		}
		return $output;
	}

	/**
	 * Transform children, preserving counters across adjacent legacy lists.
	 *
	 * @param DOMNode $parent Parent node.
	 * @param bool    $changed Whether anything changed.
	 * @throws UnexpectedValueException When a structure is unknown.
	 */
	private static function walk( DOMNode $parent, bool &$changed ): void {
		$counters = array();
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			$classes = self::classes( $node );
			if ( in_array( 'ql-ui', $classes, true ) ) {
				$owner = $node->parentNode;
				if ( 1 !== count( $classes ) || 1 !== $node->attributes->length || 'span' !== $node->tagName || $node->hasChildNodes() || ! $owner instanceof DOMElement || 'li' !== $owner->tagName || ! in_array( $owner->getAttribute( 'data-list' ), array( 'checked', 'unchecked', 'bullet', 'ordered' ), true ) ) {
					throw new UnexpectedValueException( 'Unrecognised Quill UI node requires review.' );
				}
				$parent->removeChild( $node );
				$changed = true;
				continue;
			}
			if ( in_array( 'ql-code-block-container', $classes, true ) ) {
				self::code_block( $node );
				$changed = true;
				continue;
			}
			if ( 'li' === $node->tagName && $node->hasAttribute( 'data-list' ) && ! in_array( $node->getAttribute( 'data-list' ), array( 'checked', 'unchecked', 'bullet', 'ordered' ), true ) ) {
				throw new UnexpectedValueException( 'Unknown list state requires review.' );
			}
			if ( in_array( $node->tagName, array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
				$counters = array();
			}
			if ( in_array( $node->tagName, array( 'ul', 'ol' ), true ) ) {
				// Read indentation before recursively removing the Quill classes.
				$items   = array();
				$rebuild = false;
				foreach ( $node->childNodes as $item ) {
					if ( $item instanceof DOMElement && 'li' === $item->tagName ) {
						$state     = $item->getAttribute( 'data-list' );
						$level     = self::indent( $item );
						$items[]   = array( $item, $state, $level );
						$rebuild   = $rebuild || $level > 0 || in_array( $state, array( 'bullet', 'ordered' ), true ) || ( 'ol' === $node->tagName && in_array( $state, array( 'checked', 'unchecked' ), true ) );
					} elseif ( trim( $item->textContent ) !== '' || $item instanceof DOMElement ) {
						throw new UnexpectedValueException( 'Unexpected list child requires review.' );
					}
				}
				self::walk( $node, $changed );
				if ( $rebuild ) {
					self::format( $node, $changed );
					self::rebuild_list( $node, $items, $counters );
					$changed = true;
				}
			} else {
				self::walk( $node, $changed );
			}
			self::format( $node, $changed );
		}
	}

	/**
	 * Rebuild flat lists into standard nested HTML, retaining each original li.
	 *
	 * @param DOMElement $list Original list.
	 * @param array      $items Original elements, states and levels.
	 * @param array      $counters Numbering at each level.
	 * @throws UnexpectedValueException When a level has no parent.
	 */
	private static function rebuild_list( DOMElement $list, array $items, array &$counters ): void {
		$document = $list->ownerDocument;
		$fragment = $document->createDocumentFragment();
		$frames   = array();
		if ( $list->hasAttribute( 'reversed' ) ) {
			throw new UnexpectedValueException( 'Reversed legacy numbering requires review.' );
		}
		if ( $list->hasAttribute( 'start' ) ) {
			$counters[0] = (int) $list->getAttribute( 'start' ) - 1;
		}
		foreach ( $items as list( $item, $state, $level ) ) {
			if ( $level > 0 && empty( $frames[ $level - 1 ]['last'] ) ) {
				throw new UnexpectedValueException( 'Indented list item has no identifiable parent.' );
			}
			$type   = 'ordered' === $state ? 'ol' : ( in_array( $state, array( 'bullet', 'checked', 'unchecked' ), true ) ? 'ul' : $list->tagName );
			$parent = $level > 0 ? $frames[ $level - 1 ]['last'] : $fragment;
			if ( ! isset( $frames[ $level ] ) || $frames[ $level ]['type'] !== $type || $frames[ $level ]['parent'] !== $parent ) {
				$container = $document->createElement( $type );
				if ( 0 === $level ) {
					foreach ( $list->attributes as $attribute ) {
						if ( ! in_array( $attribute->name, array( 'start', 'type' ), true ) && ! ( 'id' === $attribute->name && $fragment->hasChildNodes() ) ) {
							$container->setAttribute( $attribute->name, $attribute->value );
						}
					}
				}
				if ( 'ol' === $type ) {
					$start = ( $counters[ $level ] ?? 0 ) + 1;
					if ( 1 !== $start ) {
						$container->setAttribute( 'start', (string) $start );
					}
					if ( $level > 0 ) {
						$container->setAttribute( 'type', array( '1', 'a', 'i' )[ $level % 3 ] );
					} elseif ( $list->hasAttribute( 'type' ) ) {
						$container->setAttribute( 'type', $list->getAttribute( 'type' ) );
					}
				}
				$parent->appendChild( $container );
				$frames[ $level ] = array(
					'type' => $type,
					'parent' => $parent,
					'list' => $container,
				);
			}
			if ( 'ol' === $type ) {
				$counters[ $level ] = $item->hasAttribute( 'value' ) ? (int) $item->getAttribute( 'value' ) : ( $counters[ $level ] ?? 0 ) + 1;
			}
			foreach ( array_keys( $frames ) as $depth ) {
				if ( $depth > $level ) {
					unset( $frames[ $depth ] );
				}
			}
			foreach ( array_keys( $counters ) as $depth ) {
				if ( $depth > $level ) {
					unset( $counters[ $depth ] );
				}
			}
			if ( in_array( $state, array( 'bullet', 'ordered' ), true ) ) {
				$item->removeAttribute( 'data-list' );
			}
			$frames[ $level ]['list']->appendChild( $item );
			$frames[ $level ]['last'] = $item;
		}
		$list->parentNode->replaceChild( $fragment, $list );
	}

	/**
	 * Convert separate Quill code lines without trimming their contents.
	 *
	 * @param DOMElement $node Code container.
	 * @throws UnexpectedValueException When the container is unfamiliar.
	 */
	private static function code_block( DOMElement $node ): void {
		$lines = array();
		foreach ( $node->childNodes as $line ) {
			if ( $line instanceof DOMText && '' === trim( $line->textContent ) ) {
				continue;
			}
			if ( ! $line instanceof DOMElement || ! in_array( 'ql-code-block', self::classes( $line ), true ) ) {
				throw new UnexpectedValueException( 'Unrecognised code block requires review.' );
			}
			foreach ( $line->getElementsByTagName( '*' ) as $inline ) {
				$placeholder = 'br' === $inline->tagName && '' === trim( $line->textContent ) && 1 === $line->childNodes->length;
				$highlight = 'span' === $inline->tagName && 1 === $inline->attributes->length && preg_match( '/^hljs-[\w-]+(?:\s+hljs-[\w-]+)*$/', $inline->getAttribute( 'class' ) );
				if ( ! $placeholder && ! $highlight ) {
					throw new UnexpectedValueException( 'Unrecognised code line structure requires review.' );
				}
			}
			$lines[] = $line->textContent;
		}
		$pre  = $node->ownerDocument->createElement( 'pre' );
		foreach ( $node->attributes as $attribute ) {
			$pre->setAttribute( $attribute->name, $attribute->value );
		}
		$classes = array_diff( self::classes( $node ), array( 'ql-code-block-container' ) );
		$pre->setAttribute( 'class', implode( ' ', $classes ) );
		$formatted = false;
		self::format( $pre, $formatted );
		$code = $node->ownerDocument->createElement( 'code' );
		$code->appendChild( $node->ownerDocument->createTextNode( implode( "\n", $lines ) ) );
		$pre->appendChild( $code );
		$node->parentNode->replaceChild( $pre, $node );
	}

	/**
	 * Convert recognised presentation classes to standard attributes/CSS.
	 *
	 * @param DOMElement $node Element being converted.
	 * @param bool       $changed Whether anything changed.
	 * @throws UnexpectedValueException When a Quill format is unknown.
	 */
	private static function format( DOMElement $node, bool &$changed ): void {
		$remaining = array();
		$colors    = array(
			'black' => '#000000',
			'white' => '#ffffff',
			'red' => '#e60000',
			'orange' => '#ff9900',
			'yellow' => '#ffff00',
			'green' => '#008a00',
			'blue' => '#0066cc',
			'purple' => '#9933ff',
		);
		foreach ( self::classes( $node ) as $class ) {
			if ( ! str_starts_with( $class, 'ql-' ) ) {
				$remaining[] = $class;
				continue;
			}
			$style = '';
			if ( preg_match( '/^ql-align-(left|center|right|justify)$/', $class, $match ) ) {
				$style = 'text-align: ' . $match[1];
			} elseif ( preg_match( '/^ql-indent-([1-8])$/', $class, $match ) ) {
				if ( 'li' !== $node->tagName ) {
					$rtl = in_array( 'ql-direction-rtl', self::classes( $node ), true ) && in_array( 'ql-align-right', self::classes( $node ), true );
					$style = ( $rtl ? 'padding-right: ' : 'padding-left: ' ) . ( 3 * (int) $match[1] ) . 'em';
				}
			} elseif ( 'ql-direction-rtl' === $class ) {
				$node->setAttribute( 'dir', 'rtl' );
			} elseif ( preg_match( '/^ql-size-(small|large|huge)$/', $class, $match ) ) {
				$style = 'font-size: ' . array(
					'small' => '0.75em',
					'large' => '1.5em',
					'huge' => '2.5em',
				)[ $match[1] ];
			} elseif ( 'ql-font-monospace' === $class || 'ql-font-serif' === $class ) {
				$style = 'font-family: ' . ( 'ql-font-monospace' === $class ? 'Monaco, Courier New, monospace' : 'Georgia, Times New Roman, serif' );
			} elseif ( preg_match( '/^ql-(color|bg)-(black|white|red|orange|yellow|green|blue|purple)$/', $class, $match ) ) {
				$style = ( 'bg' === $match[1] ? 'background-color' : 'color' ) . ': ' . $colors[ $match[2] ];
			} elseif ( 'ql-syntax' !== $class || 'pre' !== $node->tagName ) {
				throw new UnexpectedValueException( 'Unknown Quill format requires review.' );
			}
			if ( $style ) {
				$property = strstr( $style, ':', true );
				if ( preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:/i', $node->getAttribute( 'style' ) ) ) {
					throw new UnexpectedValueException( 'Overlapping inline and legacy formatting requires review.' );
				}
				$node->setAttribute( 'style', rtrim( $node->getAttribute( 'style' ), '; ' ) . ( $node->hasAttribute( 'style' ) ? '; ' : '' ) . $style . ';' );
			}
			$changed = true;
		}
		if ( $node->hasAttribute( 'class' ) ) {
			if ( $remaining ) {
				$node->setAttribute( 'class', implode( ' ', $remaining ) );
			} else {
				$node->removeAttribute( 'class' );
			}
		}
	}

	/**
	 * Read element classes.
	 *
	 * @param DOMElement $node Element.
	 * @return array Classes.
	 */
	private static function classes( DOMElement $node ): array {
		return preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Serialize HTML without libxml silently percent-encoding link attributes.
	 *
	 * @param DOMNode $node Node to serialize.
	 * @return string HTML with explicit non-void closing tags.
	 */
	private static function serialize( DOMNode $node ): string {
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
	 * Read a list indentation level.
	 *
	 * @param DOMElement $node List item.
	 * @return int Level.
	 */
	private static function indent( DOMElement $node ): int {
		return preg_match( '/(?:^|\s)ql-indent-([1-8])(?:\s|$)/', $node->getAttribute( 'class' ), $match ) ? (int) $match[1] : 0;
	}
}
