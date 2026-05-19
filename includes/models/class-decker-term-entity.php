<?php
/**
 * File class-term-entity
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Term_Entity
 *
 * Provides shared data hydration for taxonomy-based models.
 */
abstract class Decker_Term_Entity {

	/**
	 * The ID of the term.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The name of the term.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The slug of the term.
	 *
	 * @var string
	 */
	public string $slug;

	/**
	 * The color associated with the term, or null if not set.
	 *
	 * @var string|null
	 */
	public ?string $color;

	/**
	 * Populate common term properties.
	 *
	 * @param WP_Term $term              The term object to hydrate from.
	 * @param string  $expected_taxonomy The expected taxonomy slug.
	 * @param string  $error_message     The exception message for invalid terms.
	 * @throws Exception If the term does not match the expected taxonomy.
	 */
	protected function hydrate_term(
		WP_Term $term,
		string $expected_taxonomy,
		string $error_message
	): void {
		if ( $expected_taxonomy !== $term->taxonomy ) {
			throw new Exception( esc_html( $error_message ) );
		}

		$this->id   = $term->term_id;
		$this->name = $term->name;
		$this->slug = $term->slug;

		$color       = get_term_meta( $term->term_id, 'term-color', true );
		$this->color = $color ? $color : null;
	}
}
