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
class WP_UnitTest_Factory_For_Decker_Label extends WP_UnitTest_Factory_For_Term {

	/**
	 * Constructor
	 *
	 * Initializes the default generation definitions for creating decker_label terms.
	 *
	 * @param object $factory Global factory that can be used to create other objects in the system.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory, 'decker_label' );

		// Default term generation: a sequence for the name and a default color.
		$this->default_generation_definitions = array(
			'name'  => new WP_UnitTest_Generator_Sequence( 'Label name %s' ),
			'slug' => new WP_UnitTest_Generator_Sequence( 'label-%s' ),			
			'color' => '#000000', // Default color black, can be overridden in tests.
		);
	}

	/**
	 * Create a decker_label term object.
	 *
	 * @param array $args Arguments for the term creation.
	 *                    Must include 'name' key. Optional: 'color'.
	 * @return int|WP_Error The term ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {

        // Ensure current user can manage labels.
        if ( ! current_user_can( 'edit_posts' ) ) {
            // throw new Exception( 'Insufficient permissions to create decker_label term.' );
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions to create decker_label term.', array( 'status' => 403 ) );
        }


        // Create term via parent to ensure proper cleanup
        $term_id = parent::create_object( array(
            'name' => $args['name'],
            'slug' => $args['slug'],
        ) );
        if ( is_wp_error( $term_id ) ) {
            return $term_id;
        }

        // Save color meta through Decker_Labels logic
        $_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
        $_POST['term-color']        = $args['color'];
        ( new Decker_Labels() )->save_color_meta( $term_id );

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
	
        // Ensure current user can manage labels.
        if ( ! current_user_can( 'edit_posts' ) ) {
            // throw new Exception( 'Insufficient permissions to update decker_label term.' );
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions to update decker_label term.', array( 'status' => 403 ) );
        }

		$term = get_term( $term_id, 'decker_label' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', 'Invalid decker_label term ID provided.' );
		}

    	// Update name/slug via parent
        $update_args = array();
        if ( isset( $fields['name'] ) ) {
            $update_args['name'] = $fields['name'];
        }
        if ( isset( $fields['slug'] ) ) {
            $update_args['slug'] = $fields['slug'];
        }
        if ( $update_args ) {
            $result = parent::update_object( $term_id, $update_args );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        // Update color
        if ( isset( $fields['color'] ) ) {
            $_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
            $_POST['term-color']        = $fields['color'];
            ( new Decker_Labels() )->save_color_meta( $term_id );
        }
	}
}
