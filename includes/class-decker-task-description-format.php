<?php
/**
 * Recognised Quill presentation and code-line conversion.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

/** Recognised Quill presentation and code-line conversion. */
class Decker_Task_Description_Format {

	/**
	 * Convert recognised presentation classes to standard attributes and CSS.
	 *
	 * @param DOMElement $node Element being converted.
	 * @param bool       $changed Whether anything changed.
	 */
	public static function apply( DOMElement $node, bool &$changed ): void {
		$remaining = array();
		foreach ( Decker_Task_Description_Html::classes( $node ) as $class ) {
			if ( ! str_starts_with( $class, 'ql-' ) ) {
				$remaining[] = $class;
				continue;
			}
			$style = self::style( $node, $class );
			if ( $style ) {
				self::append_style( $node, $style );
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
	 * Resolve one recognised class without accepting arbitrary CSS.
	 *
	 * @param DOMElement $node Element being converted.
	 * @param string     $class Quill class.
	 * @return string Standard declaration, or an attribute-only conversion.
	 * @throws UnexpectedValueException When the format is unknown.
	 */
	private static function style( DOMElement $node, string $class ): string {
		if ( preg_match( '/^ql-align-(left|center|right|justify)$/', $class, $match ) ) {
			return 'text-align: ' . $match[1];
		}
		if ( preg_match( '/^ql-indent-([1-8])$/', $class, $match ) ) {
			return self::paragraph_indent( $node, (int) $match[1] );
		}
		if ( 'ql-direction-rtl' === $class ) {
			$node->setAttribute( 'dir', 'rtl' );
			return '';
		}
		$styles = array(
			'ql-size-small' => 'font-size: 0.75em',
			'ql-size-large' => 'font-size: 1.5em',
			'ql-size-huge' => 'font-size: 2.5em',
			'ql-font-monospace' => 'font-family: Monaco, Courier New, monospace',
			'ql-font-serif' => 'font-family: Georgia, Times New Roman, serif',
		);
		if ( isset( $styles[ $class ] ) ) {
			return $styles[ $class ];
		}
		if ( preg_match( '/^ql-(color|bg)-(black|white|red|orange|yellow|green|blue|purple)$/', $class, $match ) ) {
			return self::color( $match[1], $match[2] );
		}
		if ( 'ql-syntax' === $class && 'pre' === $node->tagName ) {
			return '';
		}
		throw new UnexpectedValueException( 'Unknown Quill format requires review.' );
	}

	/**
	 * Resolve the fixed Quill color palette.
	 *
	 * @param string $kind Color or background.
	 * @param string $name Known color name.
	 * @return string CSS declaration.
	 */
	private static function color( string $kind, string $name ): string {
		$colors = array(
			'black' => '#000000',
			'white' => '#ffffff',
			'red' => '#e60000',
			'orange' => '#ff9900',
			'yellow' => '#ffff00',
			'green' => '#008a00',
			'blue' => '#0066cc',
			'purple' => '#9933ff',
		);
		return ( 'bg' === $kind ? 'background-color' : 'color' ) . ': ' . $colors[ $name ];
	}

	/**
	 * Paragraph indentation is presentation; list indentation is structural.
	 *
	 * @param DOMElement $node Element being converted.
	 * @param int        $level Quill indentation level.
	 * @return string CSS declaration, or empty for list items.
	 */
	private static function paragraph_indent( DOMElement $node, int $level ): string {
		if ( 'li' === $node->tagName ) {
			return '';
		}
		$classes = Decker_Task_Description_Html::classes( $node );
		$rtl = in_array( 'ql-direction-rtl', $classes, true ) && in_array( 'ql-align-right', $classes, true );
		return ( $rtl ? 'padding-right: ' : 'padding-left: ' ) . ( 3 * $level ) . 'em';
	}

	/**
	 * Keep existing inline declarations and refuse ambiguous overlaps.
	 *
	 * @param DOMElement $node Element being converted.
	 * @param string     $style Converted declaration.
	 * @throws UnexpectedValueException When both formats specify one property.
	 */
	private static function append_style( DOMElement $node, string $style ): void {
		$property = strstr( $style, ':', true );
		if ( preg_match( '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:/i', $node->getAttribute( 'style' ) ) ) {
			throw new UnexpectedValueException( 'Overlapping inline and legacy formatting requires review.' );
		}
		$node->setAttribute( 'style', rtrim( $node->getAttribute( 'style' ), '; ' ) . ( $node->hasAttribute( 'style' ) ? '; ' : '' ) . $style . ';' );
	}

	/**
	 * Convert separate Quill code lines without trimming their contents.
	 *
	 * @param DOMElement $node Code container.
	 * @throws UnexpectedValueException When the container is unfamiliar.
	 */
	public static function code_block( DOMElement $node ): void {
		$lines = array();
		foreach ( $node->childNodes as $line ) {
			if ( $line instanceof DOMText && '' === trim( $line->textContent ) ) {
				continue;
			}
			if ( ! $line instanceof DOMElement || ! in_array( 'ql-code-block', Decker_Task_Description_Html::classes( $line ), true ) ) {
				throw new UnexpectedValueException( 'Unrecognised code block requires review.' );
			}
			self::validate_code_line( $line );
			$lines[] = $line->textContent;
		}
		$pre  = $node->ownerDocument->createElement( 'pre' );
		foreach ( $node->attributes as $attribute ) {
			$pre->setAttribute( $attribute->name, $attribute->value );
		}
		$classes = array_diff( Decker_Task_Description_Html::classes( $node ), array( 'ql-code-block-container' ) );
		$pre->setAttribute( 'class', implode( ' ', $classes ) );
		$formatted = false;
		self::apply( $pre, $formatted );
		$code = $node->ownerDocument->createElement( 'code' );
		$code->appendChild( $node->ownerDocument->createTextNode( implode( "\n", $lines ) ) );
		$pre->appendChild( $code );
		$node->parentNode->replaceChild( $pre, $node );
	}

	/**
	 * Accept only empty-line placeholders and recognised syntax highlighting.
	 *
	 * @param DOMElement $line Original code line.
	 * @throws UnexpectedValueException When inline structure carries unknown data.
	 */
	private static function validate_code_line( DOMElement $line ): void {
		foreach ( $line->getElementsByTagName( '*' ) as $inline ) {
			$placeholder = 'br' === $inline->tagName && '' === trim( $line->textContent ) && 1 === $line->childNodes->length;
			$highlight = 'span' === $inline->tagName && 1 === $inline->attributes->length && preg_match( '/^hljs-[\w-]+(?:\s+hljs-[\w-]+)*$/', $inline->getAttribute( 'class' ) );
			if ( ! $placeholder && ! $highlight ) {
				throw new UnexpectedValueException( 'Unrecognised code line structure requires review.' );
			}
		}
	}
}
