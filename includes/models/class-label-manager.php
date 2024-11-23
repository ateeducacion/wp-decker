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

    /**
     * Get a label by ID.
     *
     * @param int $id The ID of the label.
     * @return Label|null The label object or null if not found.
     */
    public static function getLabelById(int $id): ?Label {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        foreach (self::$labels as $label) {
            if ($label->id === $id) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Save a label.
     *
     * @param array $data Label data including name and color.
     * @param int|null $id Label ID for updates, null for new labels.
     * @return array Response array with success status and message.
     */
    public static function saveLabel(array $data, ?int $id = null): array {
        $args = array(
            'name' => sanitize_text_field($data['name']),
            'slug' => sanitize_title($data['name'])
        );

        if ($id) {
            $result = wp_update_term($id, 'decker_label', $args);
        } else {
            $result = wp_insert_term($data['name'], 'decker_label', $args);
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
            'message' => $id ? __('Label updated successfully', 'decker') : __('Label created successfully', 'decker')
        );
    }

    /**
     * Delete a label.
     *
     * @param int $id The ID of the label to delete.
     * @return array Response array with success status and message.
     */
    public static function deleteLabel(int $id): array {
        $result = wp_delete_term($id, 'decker_label');

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'message' => $result->get_error_message()
            );
        }

        return array(
            'success' => true,
            'message' => __('Label deleted successfully', 'decker')
        );
    }
}
