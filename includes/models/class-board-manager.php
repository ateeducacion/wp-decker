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
}
