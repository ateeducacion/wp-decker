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
class BoardManager {

	/**
	 * Holds the singleton instance of the BoardManager.
	 *
	 * @var BoardManager|null
	 */
	private static ?BoardManager $instance = null;

	/**
	 * Stores the loaded boards as an associative array.
	 *
	 * @var array
	 */
	private static array $boards           = array();

	/**
	 * Initializes the BoardManager by loading all boards from the 'decker_board' taxonomy.
	 */
	private function __construct() {
		// Load all boards.
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				self::$boards[ $term->slug ] = new Board( $term );
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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$boards[ $slug ] ?? null;
	}

	/**
	 * Get all boards.
	 *
	 * @return array List of all Board objects.
	 */
	public static function get_all_boards(): array {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return array_values( self::$boards );
	}

	/**
	 * Save a board.
	 *
	 * @param array $data Board data including name and color.
	 * @param int   $id Board ID for updates 0 for new boards.
	 * @return array Response array with success status and message.
	 */
	public static function save_board( array $data, int $id ): array {
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
			$result = wp_update_term( $id, 'decker_board', $args );
		} else {
			$result = wp_insert_term( $data['name'], 'decker_board', $args );
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
			'message' => $id ? __( 'Board updated successfully', 'decker' ) : __( 'Board created successfully', 'decker' ),
		);
	}

	/**
	 * Delete a board.
	 *
	 * @param int $id The ID of the board to delete.
	 * @return array Response array with success status and message.
	 */
	public static function delete_board( int $id ): array {

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
