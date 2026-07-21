<?php
/**
 * File class-decker-taxonomy-manager
 *
 * @package    Decker
 * @subpackage Decker/includes/models
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Taxonomy_Manager
 *
 * Provides shared cache and term persistence helpers for taxonomy managers.
 */
abstract class Decker_Taxonomy_Manager {

	/**
	 * The cached manager instance.
	 *
	 * @var static|null
	 */
	protected static $instance = null;

	/**
	 * Cached items loaded by the manager.
	 *
	 * @var array
	 */
	protected static array $items = array();

	/**
	 * Load the manager cache during initialization.
	 */
	final protected function __construct() {
		$this->load_items();
	}

	/**
	 * Load all managed items into the internal cache.
	 */
	abstract protected function load_items(): void;

	/**
	 * Reset the cached manager instance and items.
	 */
	public static function reset_instance() {
		static::$instance = null;
		static::$items    = array();
	}

	/**
	 * Ensure the manager instance has been initialized.
	 */
	final protected static function ensure_instance(): void {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
	}

	/**
	 * Reload cached items from the database.
	 */
	final protected static function reload_items(): void {
		static::reset_instance();
		static::ensure_instance();
	}

	/**
	 * Get a cached item by its cache key.
	 *
	 * @param string $key               The cache key.
	 * @param bool   $reload_if_missing Whether to reload items if the key is missing.
	 * @return object|null Cached item object or null when not found.
	 */
	final protected static function get_cached_item(
		string $key,
		bool $reload_if_missing = false
	) {
		static::ensure_instance();

		if ( $reload_if_missing && ! isset( static::$items[ $key ] ) ) {
			static::reload_items();
		}

		return static::$items[ $key ] ?? null;
	}

	/**
	 * Get all cached items.
	 *
	 * @param bool $reload_if_empty Whether to reload items if the cache is empty.
	 * @return array
	 */
	final protected static function get_cached_items(
		bool $reload_if_empty = true
	): array {
		static::ensure_instance();

		if ( $reload_if_empty && empty( static::$items ) ) {
			static::reload_items();
		}

		return array_values( static::$items );
	}

	/**
	 * Cache a single item using the given key.
	 *
	 * @param string $key  The cache key.
	 * @param mixed  $item The item to cache.
	 */
	final protected static function cache_item( string $key, $item ): void {
		static::$items[ $key ] = $item;
	}

	/**
	 * Build sanitized term arguments.
	 *
	 * @param array $data                 Raw term data.
	 * @param bool  $supports_description Whether the taxonomy supports description.
	 * @return array
	 */
	final protected static function build_term_args(
		array $data,
		bool $supports_description = false
	): array {
		$args = array(
			'name' => sanitize_text_field( $data['name'] ),
		);

		if ( isset( $data['slug'] ) && ! empty( $data['slug'] ) ) {
			$args['slug'] = sanitize_title( $data['slug'] );
		} else {
			$args['slug'] = sanitize_title( $data['name'] );
		}

		if ( $supports_description && isset( $data['description'] ) ) {
			$args['description'] = wp_kses_post( $data['description'] );
		}

		return $args;
	}

	/**
	 * Create or update a taxonomy term.
	 *
	 * @param array  $data                 Raw term data.
	 * @param int    $id                   Term ID for updates, or 0 for new terms.
	 * @param string $taxonomy             The taxonomy slug.
	 * @param bool   $supports_description Whether the taxonomy supports description.
	 * @return array|WP_Error
	 */
	final protected static function save_term(
		array $data,
		int $id,
		string $taxonomy,
		bool $supports_description = false
	) {
		$args = static::build_term_args( $data, $supports_description );

		if ( $id ) {
			return wp_update_term( $id, $taxonomy, $args );
		}

		return wp_insert_term( $data['name'], $taxonomy, $args );
	}

	/**
	 * Create a standard error response from a term operation error.
	 *
	 * @param WP_Error $error The WordPress error object.
	 * @return array
	 */
	final protected static function error_response( WP_Error $error ): array {
		return array(
			'success' => false,
			'message' => $error->get_error_message(),
		);
	}
}
