<?php
/**
 * File class-board
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Board
 *
 * Represents a custom post type `decker_board`.
 */
class Board {

	/**
	 * The ID of the board.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The name of the board.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The slug of the board.
	 *
	 * @var string
	 */
	public string $slug;

	/**
	 * The color associated with the board, or null if not set.
	 *
	 * @var string|null
	 */
	public ?string $color;

	/**
	 * The description of the board, or null if not set.
	 *
	 * @var string|null
	 */
	public ?string $description;

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

			// Avoid short ternaries by using a complete ternary expression.
			$color = get_term_meta( $term->term_id, 'term-color', true );
			$this->color = $color ? $color : null;

			// Get description from term meta
			$description = get_term_meta( $term->term_id, 'term-description', true );
			$this->description = $description ? $description : null;
		} else {
			throw new Exception( 'Invalid board term.' );
		}
	}
}
