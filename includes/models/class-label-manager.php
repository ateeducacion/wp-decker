<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class LabelManager
 *
 * Provides functionalities to manage labels using a Singleton pattern.
 */
class LabelManager {
    private static ?LabelManager $instance = null;    
    private static array $labels = [];

    private function __construct() {
        // Load all labels
        $terms = get_terms(
            array(
                'taxonomy' => 'decker_label',
                'hide_empty' => false,
            )
        );

        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                self::$labels[$term->name] = new Label($term);
            }
        }
    }

    /**
     * Get a label by name.
     *
     * @param string $name The name of the label.
     * @return Label|null The label object or null if not found.
     */
    public static function getLabelByName(string $name): ?Label {
        if (self::$instance === null) {
            self::$instance = new self();
        }        
        return self::$labels[$name] ?? null;
    }

    /**
     * Get all labels.
     *
     * @return array List of all Label objects.
     */
    public static function getAllLabels(): array {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return array_values(self::$labels);
    }
}
