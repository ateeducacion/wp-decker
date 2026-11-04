<?php
/**
 * Structural conversion of flat legacy lists and their numbering.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM properties use camelCase.

/** Structural conversion of flat legacy lists and their numbering. */
class Decker_Task_Description_Lists {

	/**
	 * Capture indentation and state before recursively removing legacy classes.
	 *
	 * @param DOMElement $list Original list.
	 * @return array Original items and whether structural rebuilding is needed.
	 * @throws UnexpectedValueException When a child cannot safely be moved.
	 */
	public static function inspect( DOMElement $list ): array {
		$items = array();
		$rebuild = false;
		foreach ( $list->childNodes as $item ) {
			if ( $item instanceof DOMElement && 'li' === $item->tagName ) {
				$state = $item->getAttribute( 'data-list' );
				$level = self::indent( $item );
				$items[] = array( $item, $state, $level );
				$rebuild = $rebuild || self::needs_rebuild( $list, $state, $level );
			} elseif ( trim( $item->textContent ) !== '' || $item instanceof DOMElement ) {
				throw new UnexpectedValueException( 'Unexpected list child requires review.' );
			}
		}
		return array( $items, $rebuild );
	}

	/**
	 * Decide whether a list uses Quill's flat storage structure.
	 *
	 * @param DOMElement $list Original list.
	 * @param string     $state Stored item state.
	 * @param int        $level Original indentation.
	 * @return bool Whether the container needs reconstruction.
	 */
	private static function needs_rebuild( DOMElement $list, string $state, int $level ): bool {
		return $level > 0 || in_array( $state, array( 'bullet', 'ordered' ), true ) || ( 'ol' === $list->tagName && in_array( $state, array( 'checked', 'unchecked' ), true ) );
	}

	/**
	 * Rebuild flat lists into HTML nesting, retaining every original item node.
	 *
	 * @param DOMElement $list Original container.
	 * @param array      $items Original elements, states and levels.
	 * @param array      $counters Numbering at each level.
	 * @throws UnexpectedValueException When original numbering is ambiguous.
	 */
	public static function rebuild( DOMElement $list, array $items, array &$counters ): void {
		$fragment = $list->ownerDocument->createDocumentFragment();
		$frames = array();
		if ( $list->hasAttribute( 'reversed' ) ) {
			throw new UnexpectedValueException( 'Reversed legacy numbering requires review.' );
		}
		if ( $list->hasAttribute( 'start' ) ) {
			$counters[0] = (int) $list->getAttribute( 'start' ) - 1;
		}
		foreach ( $items as list( $item, $state, $level ) ) {
			self::append_item( $list, $fragment, $frames, $counters, $item, $state, $level );
		}
		$list->parentNode->replaceChild( $fragment, $list );
	}

	/**
	 * Move one item into its semantic container without touching descendants.
	 *
	 * @param DOMElement          $list Original list.
	 * @param DOMDocumentFragment $fragment Replacement top-level containers.
	 * @param array               $frames Open containers by depth.
	 * @param array               $counters Ordinals by depth.
	 * @param DOMElement          $item Original item.
	 * @param string              $state Stored item state.
	 * @param int                 $level Original indentation.
	 * @throws UnexpectedValueException When indentation has no available parent.
	 */
	private static function append_item( DOMElement $list, DOMDocumentFragment $fragment, array &$frames, array &$counters, DOMElement $item, string $state, int $level ): void {
		if ( $level > 0 && empty( $frames[ $level - 1 ]['last'] ) ) {
			throw new UnexpectedValueException( 'Indented list item has no identifiable parent.' );
		}
		$type = self::list_type( $list, $state );
		$parent = $level > 0 ? $frames[ $level - 1 ]['last'] : $fragment;
		if ( ! self::matches_frame( $frames[ $level ] ?? array(), $type, $parent ) ) {
			$container = $list->ownerDocument->createElement( $type );
			if ( 0 === $level ) {
				self::copy_attributes( $list, $container, $fragment->hasChildNodes() );
			}
			self::number_container( $container, $list, $level, $counters );
			$parent->appendChild( $container );
			$frames[ $level ] = array(
				'type' => $type,
				'parent' => $parent,
				'list' => $container,
			);
		}
		self::advance_counter( $item, $type, $level, $counters );
		self::truncate_depth( $frames, $level );
		self::truncate_depth( $counters, $level );
		if ( in_array( $state, array( 'bullet', 'ordered' ), true ) ) {
			$item->removeAttribute( 'data-list' );
		}
		$frames[ $level ]['list']->appendChild( $item );
		$frames[ $level ]['last'] = $item;
	}

	/**
	 * Resolve a legacy item kind to a standard list tag.
	 *
	 * @param DOMElement $list Original container.
	 * @param string     $state Stored state.
	 * @return string Container tag.
	 */
	private static function list_type( DOMElement $list, string $state ): string {
		return match ( $state ) {
			'ordered' => 'ol',
			'bullet', 'checked', 'unchecked' => 'ul',
			default => $list->tagName,
		};
	}

	/**
	 * Only reuse containers belonging to the same parent and semantic list kind.
	 *
	 * @param array   $frame Existing frame, if any.
	 * @param string  $type Target list tag.
	 * @param DOMNode $parent Target parent.
	 * @return bool Whether the existing container can receive this item.
	 */
	private static function matches_frame( array $frame, string $type, DOMNode $parent ): bool {
		return isset( $frame['type'] ) && $frame['type'] === $type && $frame['parent'] === $parent;
	}

	/**
	 * Preserve presentation without duplicating IDs across split containers.
	 *
	 * @param DOMElement $list Original container.
	 * @param DOMElement $container New container.
	 * @param bool       $split Whether an earlier container already owns the ID.
	 */
	private static function copy_attributes( DOMElement $list, DOMElement $container, bool $split ): void {
		foreach ( $list->attributes as $attribute ) {
			if ( ! in_array( $attribute->name, array( 'start', 'type' ), true ) && ! ( 'id' === $attribute->name && $split ) ) {
				$container->setAttribute( $attribute->name, $attribute->value );
			}
		}
	}

	/**
	 * Keep list restarts and Quill's depth-dependent ordinal presentation.
	 *
	 * @param DOMElement $container New container.
	 * @param DOMElement $list Original container.
	 * @param int        $level Indentation depth.
	 * @param array      $counters Previous ordinals.
	 */
	private static function number_container( DOMElement $container, DOMElement $list, int $level, array $counters ): void {
		if ( 'ol' !== $container->tagName ) {
			return;
		}
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

	/**
	 * Explicit item values take precedence over sequential numbering.
	 *
	 * @param DOMElement $item Original item.
	 * @param string     $type Container tag.
	 * @param int        $level Indentation depth.
	 * @param array      $counters Ordinals to update.
	 */
	private static function advance_counter( DOMElement $item, string $type, int $level, array &$counters ): void {
		if ( 'ol' === $type ) {
			$counters[ $level ] = $item->hasAttribute( 'value' ) ? (int) $item->getAttribute( 'value' ) : ( $counters[ $level ] ?? 0 ) + 1;
		}
	}

	/**
	 * Discard state below the current item when returning to a parent level.
	 *
	 * @param array $levels Frames or ordinals by depth.
	 * @param int   $level Current depth.
	 */
	private static function truncate_depth( array &$levels, int $level ): void {
		foreach ( array_keys( $levels ) as $depth ) {
			if ( $depth > $level ) {
				unset( $levels[ $depth ] );
			}
		}
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
