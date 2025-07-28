<?php
/**
 * Factory for the Decker Event custom post type.
 *
 * @package Decker
 */
class WP_UnitTest_Factory_For_Decker_Event extends WP_UnitTest_Factory_For_Post {

	/**
	 * Constructor.
	 *
	 * @param WP_UnitTest_Factory|null $factory Factory instance.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		$this->default_generation_definitions = array_merge(
			$this->default_generation_definitions,
			array(
				'post_type'    => 'decker_event',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_title'   => new WP_UnitTest_Generator_Sequence( 'Event title %s' ),
				'post_content' => new WP_UnitTest_Generator_Sequence( 'Event description %s' ),
			)
		);
	}

	/**
	 * Creates a new decker_event post and processes its meta using the plugin's logic.
	 *
	 * @param array $args Arguments for wp_insert_post(), plus 'meta_input'.
	 * @return int|WP_Error Post ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		// Extract 'meta_input' which contains our custom fields.
		$meta_input = isset( $args['meta_input'] ) ? $args['meta_input'] : array();
		unset( $args['meta_input'] );

		// Create the post using the parent method (which calls wp_insert_post).
		$post_id = parent::create_object( $args );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id;
		}

		// Now, process the meta data using our refactored, testable method.
		// We need an instance of the class to call the method.
		$decker_events_instance = new Decker_Events();
		$decker_events_instance->process_and_save_meta( $post_id, $meta_input );

		return $post_id;
	}

	/**
	 * Updates a decker_event post and processes its meta using the plugin's logic.
	 *
	 * @param int   $post_id The ID of the post to update.
	 * @param array $fields  The fields to update, plus 'meta_input'.
	 * @return int|WP_Error Post ID on success, or WP_Error on failure.
	 */
	public function update_object( $post_id, $fields ) {
		// Extract 'meta_input'.
		$meta_input = isset( $fields['meta_input'] ) ? $fields['meta_input'] : array();
		unset( $fields['meta_input'] );

		// Update the core post fields.
		$result = parent::update_object( $post_id, $fields );

		// Process the meta data using our refactored method.
		if ( ! empty( $meta_input ) ) {
			$decker_events_instance = new Decker_Events();
			$decker_events_instance->process_and_save_meta( $post_id, $meta_input );
		}

		return $result;
	}

	/**
	 * Retrieves a decker_event post by ID.
	 *
	 * @param int $object_id Post ID.
	 * @return WP_Post|false The post object if valid, or false otherwise.
	 */
	public function get_object_by_id( $object_id ) {
		$post = get_post( $object_id );
		return ( $post && 'decker_event' === $post->post_type ) ? $post : false;
	}
}
