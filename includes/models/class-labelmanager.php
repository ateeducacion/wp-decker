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
class LabelManager extends Decker_Taxonomy_Manager {

	/**
	 * Cached manager instance for the label manager.
	 *
	 * @var static|null
	 */
	protected static $instance = null;

	/**
	 * Cached label items loaded by the label manager.
	 *
	 * @var array
	 */
	protected static array $items = array();

	/**
	 * Load all labels from the database.
	 */
	protected function load_items(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				static::cache_item( $term->name, new Label( $term ) );
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
		return static::get_cached_item( $name );
	}

	/**
	 * Get all labels.
	 *
	 * @return array List of all Label objects.
	 */
	public static function get_all_labels(): array {
		return static::get_cached_items( false );
	}

	/**
	 * Get a label by ID.
	 *
	 * @param int $id The ID of the label.
	 * @return Label|null The label object or null if not found.
	 */
	public static function get_label_by_id( int $id ): ?Label {
		foreach ( static::get_cached_items( false ) as $label ) {
			if ( $label->id === $id ) {
				return $label;
			}
		}

		$term = get_term( $id, 'decker_label' );
		if ( $term && ! is_wp_error( $term ) ) {
			$label = new Label( $term );
			static::cache_item( $label->name, $label );
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

		$is_update = (bool) $id;
		$result    = static::save_term( $data, $id, 'decker_label' );

		if ( is_wp_error( $result ) ) {
			return static::error_response( $result );
		}

		$id = $result['term_id'];

		update_term_meta( $id, 'term-color', sanitize_hex_color( $data['color'] ) );

		static::reset_instance();

		return array(
			'success' => true,
			'message' => $is_update
			? __( 'Label updated successfully', 'decker' )
			: __( 'Label created successfully', 'decker' ),
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
			return static::error_response( $result );
		}

		static::reset_instance();

		return array(
			'success' => true,
			'message' => __( 'Label deleted successfully', 'decker' ),
		);
	}
}
