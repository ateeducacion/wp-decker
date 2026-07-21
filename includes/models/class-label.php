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
class Label extends Decker_Term_Entity {

	/**
	 * Label constructor.
	 *
	 * @param WP_Term $term The term object representing the label.
	 * @throws Exception If the term is invalid.
	 */
	public function __construct( WP_Term $term ) {
		$this->hydrate_term( $term, 'decker_label' );
	}
}
