<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class BoardManager
 *
 * Provides functionalities to manage boards using a Singleton pattern.
 */
class BoardManager {
    private static ?BoardManager $instance = null;    
    private static array $boards = [];

    private function __construct() {
        // Load all boards
        $terms = get_terms(
            array(
                'taxonomy' => 'decker_board',
                'hide_empty' => false,
            )
        );
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                self::$boards[$term->slug] = new Board($term);
            }
        }
    }

    /**
     * Get a board by slug.
     *
     * @param string $slug The slug of the board.
     * @return Board|null The board object or null if not found.
     */
    public static function getBoardBySlug(string $slug): ?Board {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$boards[$slug] ?? null;
    }

    /**
     * Get all boards.
     *
     * @return array List of all Board objects.
     */
    public static function getAllBoards(): array {
        if (self::$instance === null) {
            self::$instance = new self();
        }        
        return array_values(self::$boards);
    }

    /**
     * Save a board.
     *
     * @param array $data Board data including name and color.
     * @param int|null $id Board ID for updates, null for new boards.
     * @return array Response array with success status and message.
     */
    public static function saveBoard(array $data, ?int $id = null): array {
        $args = array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['name'])
        );

        if ($id) {
            $result = wp_update_term($id, 'decker_board', $args);
        } else {
            $result = wp_insert_term($data['name'], 'decker_board', $args);
        }

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }

        $term_id = $id ?? $result['term_id'];
        update_term_meta($term_id, 'term-color', sanitize_hex_color($data['color']));

        return array(
            'success' => true,
            'message' => $id ? __('Board updated successfully', 'decker') : __('Board created successfully', 'decker')
        );
    }

    /**
     * Delete a board.
     *
     * @param int $id The ID of the board to delete.
     * @return array Response array with success status and message.
     */
    public static function deleteBoard(int $id): array {
        $result = wp_delete_term($id, 'decker_board');

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }

        return array(
            'success' => true,
            'message' => __('Board deleted successfully', 'decker')
        );
    }

}
