<?php
/**
 * File class-boardmanager
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class BoardManager
 *
 * Provides functionalities to manage boards using a Singleton pattern.
 */
class BoardManager extends Decker_Taxonomy_Manager {

	/**
	 * Cached singleton instance for the board manager.
	 *
	 * @var BoardManager|null $instance
	 */
	protected static $instance = null;

	/**
	 * Cached Board objects keyed by slug.
	 *
	 * @var Board[] $items
	 */
	protected static array $items = array();

	/**
	 * Load all boards from the database.
	 */
	protected function load_items(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				static::cache_item( $term->slug, new Board( $term ) );
			}
		}
	}

	/**
	 * Get a board by slug.
	 *
	 * @param string $slug The slug of the board.
	 * @return Board|null The board object or null if not found.
	 */
	public static function get_board_by_slug( string $slug ): ?Board {
		return static::get_cached_item( $slug, true );
	}

	/**
	 * Get all boards.
	 *
	 * @return array List of all Board objects.
	 */
	public static function get_all_boards(): array {
		return static::get_cached_items();
	}

	/**
	 * Save a board.
	 *
	 * @param array $data Board data including name and color.
	 * @param int   $id Board ID for updates 0 for new boards.
	 * @return array Response array with success status and message.
	 */
	public static function save_board( array $data, int $id ): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to create terms.', 'decker' ),
			);
		}

		$is_existing_term = (bool) $id;
		$result           = static::save_term( $data, $id, 'decker_board', true );

		if ( is_wp_error( $result ) ) {
			return static::error_response( $result );
		}

		$id = $result['term_id'];

		update_term_meta( $id, 'term-color', sanitize_hex_color( $data['color'] ) );

		$show_in_boards = isset( $data['show_in_boards'] ) && $data['show_in_boards'] ? '1' : '0';
		$show_in_kb     = isset( $data['show_in_kb'] ) && $data['show_in_kb'] ? '1' : '0';

		update_term_meta( $id, 'term-show-in-boards', $show_in_boards );
		update_term_meta( $id, 'term-show-in-kb', $show_in_kb );

		static::reset_instance();

		return array(
			'success' => true,
			'message' => $is_existing_term
			? __( 'Board updated successfully', 'decker' )
			: __( 'Board created successfully', 'decker' ),
		);
	}

	/**
	 * Delete a board.
	 *
	 * @param int $id The ID of the board to delete.
	 * @return array Response array with success status and message.
	 */
	public static function delete_board( int $id ): array {

		if ( ! current_user_can( 'edit_posts' ) ) {
			return array(
				'success' => false,
				'message' => __( 'You do not have permission to delete boards', 'decker' ),
			);
		}

		$result = wp_delete_term( $id, 'decker_board' );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Board deleted successfully', 'decker' ),
		);
	}
}
