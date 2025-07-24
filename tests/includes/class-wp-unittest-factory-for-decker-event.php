<?php
/**
 * Custom factory for Decker Event custom post type.
 *
 * @package Decker
 */

/**
 * Class WP_UnitTest_Factory_For_Decker_Event
 *
 * A factory that uses Decker_Events meta fields for creating and updating decker_event posts.
 * It integrates with the WordPress Core unit test factories.
 */
class WP_UnitTest_Factory_For_Decker_Event extends WP_UnitTest_Factory_For_Post {

    /**
     * Constructor.
     *
     * Initializes default generation definitions for decker_event creation.
     *
     * @param object|null $factory The global factory instance.
     */
    public function __construct( $factory = null ) {
        parent::__construct( $factory );

        // Extend parent's default generation definitions.
        $this->default_generation_definitions = array_merge(
            $this->default_generation_definitions,
            array(
                // Custom definitions for decker_event.
                'post_title'   => new WP_UnitTest_Generator_Sequence( 'Event title %s' ),
                'post_content' => new WP_UnitTest_Generator_Sequence( 'Event description %s' ),
                'post_author'  => 1, // Default to user ID 1 (admin).
                'post_type'    => 'decker_event',
                'post_status'  => 'publish',  // Asegura que los eventos estén publicados para que los consulte el calendario.
                'event_allday' => false,
                'event_start'  => date( 'Y-m-d H:i:s', strtotime( '+1 day' ) ),
                'event_end'    => date( 'Y-m-d H:i:s', strtotime( '+1 day 2 hours' ) ),
                'event_location' => new WP_UnitTest_Generator_Sequence( 'Location %s' ),
                'event_url' => new WP_UnitTest_Generator_Sequence( 'https://example.com/event-%s' ),
                'event_category' => 'bg-success',
                'event_assigned_users' => array(),
            )
        );
    }

    /**
     * Retrieves a Decker Event post by ID.
     *
     * @param int $object_id The post ID.
     * @return WP_Post|false WP_Post object on success, or false if not found.
     */
    public function get_object_by_id( $object_id ) {
        $post = get_post( $object_id );
        if ( $post && 'decker_event' === $post->post_type ) {
            return $post;
        }
        return false;
    }

    /**
     * Creates a decker_event with custom meta fields.
     *
     * @param array $args Arguments for creating the event.
     * @return int|WP_Error The created event ID or WP_Error on failure.
     */
    public function create_object( $args ) {
        // Create the basic post
        $event_id = parent::create_object( $args );
        
        if ( is_wp_error( $event_id ) ) {
            return $event_id;
        }

        // Save custom meta fields
        $meta_fields = array(
            'event_allday',
            'event_start',
            'event_end',
            'event_location',
            'event_url',
            'event_category',
            'event_assigned_users'
        );

        foreach ( $meta_fields as $field ) {
            if ( isset( $args[ $field ] ) ) {
                update_post_meta( $event_id, $field, $args[ $field ] );
            }
        }

        return $event_id;
    }

    /**
     * Updates a decker_event with custom meta fields.
     *
     * @param int   $event_id Event ID to update.
     * @param array $fields  Fields to update.
     * @return int|WP_Error Updated event ID or WP_Error on failure.
     */
    public function update_object( $event_id, $fields ) {
        // Update basic post fields
        parent::update_object( $event_id, $fields );

        // Update custom meta fields
        $meta_fields = array(
            'event_allday',
            'event_start',
            'event_end',
            'event_location',
            'event_url',
            'event_category',
            'event_assigned_users'
        );

        foreach ( $meta_fields as $field ) {
            if ( isset( $fields[ $field ] ) ) {
                update_post_meta( $event_id, $field, $fields[ $field ] );
            }
        }

        return $event_id;
    }
}
