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
class Board extends Decker_Term_Entity {

	/**
	 * Whether to show this board in the Boards section of the sidebar.
	 *
	 * @var bool
	 */
	public bool $show_in_boards;

	/**
	 * Whether to show this board in the Knowledge Base section of the sidebar.
	 *
	 * @var bool
	 */
	public bool $show_in_kb;

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
		$this->hydrate_term( $term, 'decker_board' );
		$this->description = $term->description;

		$show_in_boards       = get_term_meta(
			$term->term_id,
			'term-show-in-boards',
			true
		);
		$show_in_kb           = get_term_meta( $term->term_id, 'term-show-in-kb', true );
		$this->show_in_boards = '0' !== $show_in_boards;
		$this->show_in_kb     = '0' !== $show_in_kb;
	}
}
