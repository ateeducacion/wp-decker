<?php
/**
 * Custom factory for Decker Label taxonomy terms.
 *
 * @package Decker
 */

/**
 * Class WP_UnitTest_Factory_For_Decker_Label
 *
 * This factory creates and updates terms in the 'decker_label' taxonomy.
 * It also handles setting the 'term-color' meta for the label terms.
 */
class WP_UnitTest_Factory_For_Decker_Label extends WP_UnitTest_Factory_For_Thing {

	/**
	 * Constructor
	 *
	 * Initializes the default generation definitions for creating decker_label terms.
	 *
	 * @param object $factory Global factory that can be used to create other objects in the system.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Default term generation: a sequence for the name and a default color.
		$this->default_generation_definitions = array(
			'name'  => new WP_UnitTest_Generator_Sequence( 'Label name %s' ),
			'color' => '#000000', // Default color black, can be overridden in tests.
		);
	}

	/**
	 * Retrieve a decker_label term by ID.
	 *
	 * @param int $object_id The term ID.
	 * @return WP_Term|false WP_Term object on success, or false if not found.
	 */
	public function get_object_by_id( $object_id ) {
		$term = get_term( $object_id, 'decker_label' );
		if ( $term instanceof WP_Term ) {
			return $term;
		}
		return false;
	}

	/**
	 * Create a decker_label term object.
	 *
	 * @param array $args Arguments for the term creation.
	 *                    Must include 'name' key. Optional: 'color'.
	 * @return int|WP_Error The term ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		$name  = isset( $args['name'] ) ? $args['name'] : 'Default Label';
		$color = isset( $args['color'] ) ? $args['color'] : '#000000';

		// Use WordPress's internal capability checks via the prevent_term_creation hook.
		$decker_labels = new Decker_Labels();

		// Use `wp_insert_term` for the actual term creation.
		$result = wp_insert_term( $name, 'decker_label' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];

		// Save color meta through Decker_Labels logic.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term-color'] = $color;

		// Ensure the save_color_meta function handles the meta update.
		$decker_labels->save_color_meta( $term_id );

		return $term_id;
	}

	/**
	 * Update a decker_label term object.
	 *
	 * @param int   $term_id Term ID to update.
	 * @param array $fields  Fields to update.
	 *                       Can include 'name', 'color'.
	 * @return int|WP_Error Updated term ID on success, or WP_Error on failure.
	 */
	public function update_object( $term_id, $fields ) {
		$term = get_term( $term_id, 'decker_label' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', 'Invalid decker_label term ID provided.' );
		}

		$decker_labels = new Decker_Labels();

		$args = array();

		if ( isset( $fields['name'] ) ) {
			$args['name'] = $fields['name'];
		}

		// Update the term using wp_update_term if name changes.
		if ( ! empty( $args ) ) {
			$result = wp_update_term( $term_id, 'decker_label', $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$term_id = $result['term_id'];
		}

		// Update color meta through Decker_Labels logic.
		if ( isset( $fields['color'] ) ) {
			$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
			$_POST['term-color'] = $fields['color'];
			$decker_labels->save_color_meta( $term_id );
		}

		return $term_id;
	}
}
