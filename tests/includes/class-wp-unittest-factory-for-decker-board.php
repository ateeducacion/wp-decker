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
class WP_UnitTest_Factory_For_Decker_Board extends WP_UnitTest_Factory_For_Term {

	/**
	 * Constructor
	 *
	 * Initializes the default generation definitions for creating decker_board terms.
	 *
	 * @param object $factory Global factory that can be used to create other objects in the system.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory,  'decker_board' );

		// Default term generation: a sequence for the name and a default color.
		$this->default_generation_definitions = array(
			'name'  => new WP_UnitTest_Generator_Sequence( 'Board name %s' ),
			'slug' => new WP_UnitTest_Generator_Sequence( 'board-%s' ),
			'color' => '#000000', // Default color black, can be overridden in tests.
			'show_in_boards' => true,
			'show_in_kb' => true,
		);
	}

	/**
	 * Create a decker_board term object.
	 *
	 * @param array $args Arguments for the term creation.
	 *                    Must include 'name' key. Optional: 'color', 'show_in_boards', 'show_in_kb'.
	 * @return int|WP_Error The term ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {

        // Ensure current user can manage boards.
        if ( ! current_user_can( 'edit_posts' ) ) {
            // throw new Exception( 'Insufficient permissions to create decker_board term.' );
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions to create decker_board term.', array( 'status' => 403 ) );
        }

        // Create term via parent to ensure proper cleanup
        $term_id = parent::create_object( array(
            'name' => $args['name'],
            'slug' => $args['slug'],
            'color' => $args['color'],
            'show_in_boards' => $args['show_in_boards'],
            'show_in_kb' => $args['show_in_kb'],
        ) );

        if ( is_wp_error( $term_id ) ) {
            return $term_id;
        }

        // Save color meta through Decker_Boards logic
        $_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
        $_POST['term-color']        = $args['color'];
        
        // Set visibility options
        if ($args['show_in_boards']) {
            $_POST['term-show-in-boards'] = '1';
        } else {
            unset($_POST['term-show-in-boards']);
        }
        
        if ($args['show_in_kb']) {
            $_POST['term-show-in-kb'] = '1';
        } else {
            unset($_POST['term-show-in-kb']);
        }

        ( new Decker_Boards() )->save_color_meta( $term_id );

        return $term_id;
	}

	/**
	 * Update a decker_board term object.
	 *
	 * @param int   $term_id Term ID to update.
	 * @param array $fields  Fields to update.
	 *                       Can include 'name', 'color', 'show_in_boards', 'show_in_kb'.
	 * @return int|WP_Error Updated term ID on success, or WP_Error on failure.
	 */
	public function update_object( $term_id, $fields ) {

        // Ensure current user can manage boards.
        if ( ! current_user_can( 'edit_posts' ) ) {
            // throw new Exception( 'Insufficient permissions to update decker_board term.' );
            return new WP_Error( 'rest_forbidden', 'Insufficient permissions to update decker_board term.', array( 'status' => 403 ) );
        }

		$term = get_term( $term_id, 'decker_board' );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'invalid_term', 'Invalid decker_board term ID provided.' );
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

        // Set up POST data for the meta update
        $_POST['decker_term_nonce'] = wp_create_nonce( 'decker_term_action' );
        
        // Update color
        if ( isset( $fields['color'] ) ) {
            $_POST['term-color'] = $fields['color'];
        }
        
        // Update visibility settings
        if ( isset( $fields['show_in_boards'] ) ) {
            if ( $fields['show_in_boards'] ) {
                $_POST['term-show-in-boards'] = '1';
            } else {
                unset( $_POST['term-show-in-boards'] );
            }
        }
        
        if ( isset( $fields['show_in_kb'] ) ) {
            if ( $fields['show_in_kb'] ) {
                $_POST['term-show-in-kb'] = '1';
            } else {
                unset( $_POST['term-show-in-kb'] );
            }
        }
        
        ( new Decker_Boards() )->save_color_meta( $term_id );
        
        return $term_id;

	}
}
