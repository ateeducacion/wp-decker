<?php
/**
 * File class-label
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Label
 *
 * Represents a custom post type `decker_label`.
 */
class Label {

	/**
	 * The ID of the label.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The name of the label.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The slug of the label.
	 *
	 * @var string
	 */
	public string $slug;

	/**
	 * The color associated with the label, or null if not set.
	 *
	 * @var string|null
	 */
	public ?string $color;

	/**
	 * Label constructor.
	 *
	 * @param WP_Term $term The term object representing the label.
	 * @throws Exception If the term is invalid.
	 */
	public function __construct( WP_Term $term ) {
		if ( $term && 'decker_label' === $term->taxonomy ) {
			$this->id    = $term->term_id;
			$this->name  = $term->name;
			$this->slug  = $term->slug;

			// Avoid short ternaries by using a complete ternary expression.
			$color = get_term_meta( $term->term_id, 'term-color', true );
			$this->color = $color ? $color : null;
		} else {
			throw new Exception( 'Invalid label term.' );
		}
	}
}
