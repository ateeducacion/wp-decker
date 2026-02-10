<?php
/**
 * Journal Post Type for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Journal_CPT.
 *
 * Handles the simplified Custom Post Type for journal entry comments.
 */
class Decker_Journal_CPT {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta_fields' ) );
	}

	/**
	 * Register the decker_journal post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Journal Entries', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Journal Entry', 'post type singular name', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=decker_task',
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'author' ),
			'taxonomies'         => array( 'decker_board' ),
			'show_in_rest'       => true,
			'rest_base'          => 'decker-journals',
		);

		register_post_type( 'decker_journal', $args );
	}

	/**
	 * Register meta fields for the decker_journal post type.
	 */
	public function register_meta_fields() {
		register_post_meta(
			'decker_journal',
			'journal_date',
			array(
				'type'              => 'string',
				'description'       => __( 'The date of the journal entry in YYYY-MM-DD format.', 'decker' ),
				'single'            => true,
				'sanitize_callback' => array( $this, 'sanitize_date' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'   => 'string',
						'format' => 'date',
					),
				),
			)
		);
	}

	/**
	 * Sanitize a date string.
	 *
	 * @param string $value The date string to sanitize.
	 * @return string|null
	 */
	public function sanitize_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		try {
			$date = new DateTime( $value );
			return $date->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			return null;
		}
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Journal_CPT' ) ) {
	new Decker_Journal_CPT();
}
