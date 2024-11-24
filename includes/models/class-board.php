<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Board
 *
 * Represents a custom post type `decker_board`.
 */
class Board {

	public int $id;
	public string $name;
	public string $slug;
	public ?string $color;

	/**
	 * Board constructor.
	 *
	 * @param WP_Term $term The term object representing the board.
	 * @throws Exception If the term is invalid.
	 */
	public function __construct( WP_Term $term ) {
		if ( $term && 'decker_board' === $term->taxonomy ) {
			$this->id    = $term->term_id;
			$this->name  = $term->name;
			$this->slug  = $term->slug;
			$this->color = get_term_meta( $term->term_id, 'term-color', true ) ?: null;
		} else {
			throw new Exception( 'Invalid board term.' );
		}
	}
}
