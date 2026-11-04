<?php
/**
 * Task attachment-link validation, labels and merge persistence.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Shares the link metadata contract between the REST editor and task merges. */
class Decker_Task_Attachment_Links {
	/**
	 * Write a link's display title into the task's url => title map.
	 *
	 * The URL stays the meta value of _decker_attachment_link, so deleting a
	 * link by value and the merge dedupe keep working untouched; an empty
	 * title drops the entry and leaves pre-existing links rendering their URL.
	 *
	 * @param int    $task_id The task ID.
	 * @param string $url     The link URL.
	 * @param string $label   The sanitized title, empty to drop the entry.
	 * @return void
	 */
	public static function store_label( $task_id, $url, $label ) {
		$key    = '_decker_attachment_link_labels';
		$labels = get_post_meta( $task_id, $key, true );
		$labels = is_array( $labels ) ? $labels : array();
		if ( '' === $label ) {
			if ( ! isset( $labels[ $url ] ) ) {
				return;
			}
			unset( $labels[ $url ] );
		} else {
			$labels[ $url ] = $label;
		}
		update_post_meta( $task_id, $key, wp_slash( $labels ) );
	}

	/**
	 * Validate an HTTP or HTTPS attachment URL without embedded credentials.
	 *
	 * @param string $url The attachment URL.
	 * @return bool Whether the URL can be stored unchanged.
	 */
	public static function is_valid_url( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) && filter_var( $url, FILTER_VALIDATE_URL )
			&& esc_url_raw( $url, array( 'http', 'https' ) ) === $url
			&& null === wp_parse_url( $url, PHP_URL_USER ) && null === wp_parse_url( $url, PHP_URL_PASS );
	}

	/** Merge external links while preserving destination labels on shared URLs.
	 *
	 * @param int $source_task_id Source task.
	 * @param int $destination_task_id Destination task.
	 */
	public static function merge( int $source_task_id, int $destination_task_id ) {
		$destination_links = get_post_meta( $destination_task_id, '_decker_attachment_link' );
		foreach ( get_post_meta( $source_task_id, '_decker_attachment_link' ) as $url ) {
			if ( ! in_array( $url, $destination_links, true ) ) {
				add_post_meta( $destination_task_id, '_decker_attachment_link', wp_slash( $url ) );
			}
		}

		// The link titles live in a url => title map beside the links, so they
		// travel with them; the destination's own titles win on a shared URL.
		$source_labels      = get_post_meta( $source_task_id, '_decker_attachment_link_labels', true );
		$destination_labels = get_post_meta( $destination_task_id, '_decker_attachment_link_labels', true );
		$merged_labels      = ( is_array( $destination_labels ) ? $destination_labels : array() )
			+ ( is_array( $source_labels ) ? $source_labels : array() );
		if ( ! empty( $merged_labels ) ) {
			update_post_meta( $destination_task_id, '_decker_attachment_link_labels', wp_slash( $merged_labels ) );
		}

		delete_post_meta( $source_task_id, '_decker_attachment_link_labels' );
		delete_post_meta( $source_task_id, '_decker_attachment_link' );
	}
}
