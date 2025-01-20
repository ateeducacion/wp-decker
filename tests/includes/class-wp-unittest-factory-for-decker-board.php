<?php
/**
 * Custom factory for Decker Board taxonomy terms.
 *
 * @package Decker
 */

/**
 * Class WP_UnitTest_Factory_For_Decker_Board
 *
 * This factory creates and updates terms in the 'decker_board' taxonomy.
 * It also handles setting the 'term-color' meta for the board terms.
 */
class WP_UnitTest_Factory_For_Decker_Board extends WP_UnitTest_Factory_For_Thing {

	/**
	 * Constructor
	 *
	 * Initializes the default generation definitions for creating decker_board terms.
	 *
	 * @param object $factory Global factory that can be used to create other objects in the system.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		// Default term generation: a sequence for the name and a default color.
		$this->default_generation_definitions = array(
			'name'  => new WP_UnitTest_Generator_Sequence( 'Board name %s' ),
			'color' => '#000000', // Default color black, can be overridden in tests.
		);
	}

	/**
	 * Retrieve a decker_board term by ID.
	 *
	 * @param int $object_id The term ID.
	 * @return WP_Term|false WP_Term object on success, or false if not found.
	 */
	public function get_object_by_id( $object_id ) {
		$term = get_term( $object_id, 'decker_board' );
		if ( $term instanceof WP_Term ) {
			return $term;
		}
		return false;
	}

	/**
	 * Create a decker_board term object.
	 *
	 * @param array $args Arguments for the term creation.
	 *                    Must include 'name' key. Optional: 'color'.
	 * @return int|WP_Error The term ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		$name  = isset( $args['name'] ) ? $args['name'] : 'Default Board';
		$color = isset( $args['color'] ) ? $args['color'] : '#000000';

		// Use WordPress's internal capability checks via the prevent_term_creation hook.
		$decker_boards = new Decker_Boards();

		// Use `wp_insert_term` for the actual term creation.
		$result = wp_insert_term( $name, 'decker_board' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$term_id = $result['term_id'];

		// Save color meta through Decker_Boards logic.
		$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
		$_POST['term-color'] = $color;

		// Ensure the save_color_meta function handles the meta update.
		$decker_boards->save_color_meta( $term_id );

		return $term_id;
	}

	/**
	 * Update a decker_board term object.
	 *
	 * @param int   $term_id Term ID to update.
	 * @param array $fields  Fields to update.
	 *                       Can include 'name', 'color'.
	 * @return int|WP_Error Updated term ID on success, or WP_Error on failure.
	 */
	public function update_object( $term_id, $fields ) {
		$term = get_term( $term_id, 'decker_board' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', 'Invalid decker_board term ID provided.' );
		}

		$decker_boards = new Decker_Boards();

		$args = array();

		if ( isset( $fields['name'] ) ) {
			$args['name'] = $fields['name'];
		}

		// Update the term using wp_update_term if name changes.
		if ( ! empty( $args ) ) {
			$result = wp_update_term( $term_id, 'decker_board', $args );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$term_id = $result['term_id'];
		}

		// Update color meta through Decker_Boards logic.
		if ( isset( $fields['color'] ) ) {
			$_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
			$_POST['term-color'] = $fields['color'];
			$decker_boards->save_color_meta( $term_id );
		}

		return $term_id;
	}
}
