<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Label
 *
 * Represents a custom post type `decker_label`.
 */
class Label {
    public int $id;
    public string $name;
    public string $slug;
    public ?string $color;

    /**
     * Label constructor.
     *
     * @param WP_Term $term The term object representing the label.
     * @throws Exception If the term is invalid.
     */
    public function __construct(WP_Term $term) {
        if ($term && $term->taxonomy === 'decker_label') {
            $this->id = $term->term_id;
            $this->name = $term->name;
            $this->slug = $term->slug;
            $this->color = get_term_meta($term->term_id, 'term-color', true) ?: null;
        } else {
            throw new Exception('Invalid label term.');
        }
    }
}

