<?php
/**
 * File class-labelmanager
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class LabelManager
 *
 * Provides functionalities to manage labels using a Singleton pattern.
 */
class LabelManager {

	/**
	 * Holds the singleton instance of the LabelManager.
	 *
	 * @var LabelManager|null
	 */
	private static ?LabelManager $instance = null;

	/**
	 * Stores the loaded labels as an associative array.
	 *
	 * @var array
	 */
	private static array $labels           = array();

	/**
	 * Initializes the LabelManager by loading all labels from the 'label_board' taxonomy.
	 */
	private function __construct() {
		// Load all labels.
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				self::$labels[ $term->name ] = new Label( $term );
			}
		}
	}

	/**
	 * Get a label by name.
	 *
	 * @param string $name The name of the label.
	 * @return Label|null The label object or null if not found.
	 */
	public static function get_label_by_name( string $name ): ?Label {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$labels[ $name ] ?? null;
	}

	/**
	 * Get all labels.
	 *
	 * @return array List of all Label objects.
	 */
	public static function get_all_labels(): array {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return array_values( self::$labels );
	}

	/**
	 * Get a label by ID.
	 *
	 * @param int $id The ID of the label.
	 * @return Label|null The label object or null if not found.
	 */
	public static function get_label_by_id( int $id ): ?Label {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		foreach ( self::$labels as $label ) {
			if ( $label->id === $id ) {
				return $label;
			}
		}

		// If not found, try to load the label using another method.
		$term = get_term( $id, 'decker_label' ); // Replace 'taxonomy' with the correct taxonomy slug.
		if ( $term && ! is_wp_error( $term ) ) {
			$label = new Label( $term );
			self::$labels[] = $label; // Cache the newly created label.
			return $label;
		}

		return null;
	}

	/**
	 * Save a label.
	 *
	 * @param array $data Label data including name and color.
	 * @param int   $id Label ID for updates 0 for new labels.
	 * @return array Response array with success status and message.
	 */
	public static function save_label( array $data, int $id ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to manage labels', 'decker' ),
			);
		}
		$args = array(
			'name' => sanitize_text_field( $data['name'] ),
		);

		// Only generate slug from name if no slug was provided.
		if ( isset( $data['slug'] ) && ! empty( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( $data['slug'] );
		} else {
			$args['slug'] = sanitize_title( $data['name'] );
		}

		if ( $id ) {
			$result = wp_update_term( $id, 'decker_label', $args );
		} else {
			$result = wp_insert_term( $data['name'], 'decker_label', $args );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		// Reasign $id (because it's new on create).
		$id = $result['term_id'];

		update_term_meta( $id, 'term-color', sanitize_hex_color( $data['color'] ) );

		return array(
			'success' => true,
			'message' => $id ? __( 'Label updated successfully', 'decker' ) : __( 'Label created successfully', 'decker' ),
		);
	}

	/**
	 * Delete a label.
	 *
	 * @param int $id The ID of the label to delete.
	 * @return array Response array with success status and message.
	 */
	public static function delete_label( int $id ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to delete labels', 'decker' ),
			);
		}
		$result = wp_delete_term( $id, 'decker_label' );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Label deleted successfully', 'decker' ),
		);
	}

	/**
	 * Resets the LabelManager instance and labels (for testing purposes).
	 */
	public static function reset_instance() {
		self::$instance = null;
		self::$labels = array();
	}
}
