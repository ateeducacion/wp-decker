<?php
/**
 * Admin list-table presentation for events.
 *
 * The columns of the decker_event list table and the small CSS tweaks the
 * edit screen needs. Presentation for administrators, kept apart from the
 * post type registration and meta handling in Decker_Events.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Event_Admin_Screen
 */
class Decker_Event_Admin_Screen {

	/**
	 * Hook the list-table columns and the admin CSS tweaks.
	 */
	public function __construct() {
		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );
		add_filter( 'manage_decker_event_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_decker_event_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
	}

	/**
	 * Hide visibility options for decker_event post type.
	 */
	public function hide_visibility_options() {
		$screen = get_current_screen();
		if ( $screen && 'decker_event' === $screen->post_type ) {
			echo '<style type="text/css">
				.misc-pub-section.misc-pub-visibility {
					display: none;
				}
			</style>';
		}
	}

	/**
	 * Add custom columns to the event admin list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_custom_columns( $columns ) {
		   unset( $columns['date'] ); // Optional: remove the default date column.

		$columns['event_allday'] = __( 'All Day Event', 'decker' );
		$columns['event_start'] = __( 'Start', 'decker' );
		$columns['event_end']   = __( 'End', 'decker' );
		$columns['event_category'] = __( 'Category', 'decker' );

		   $columns['date'] = __( 'Date', 'decker' ); // Add it again at the end.
		return $columns;
	}

	/**
	 * Render content for custom columns in the event admin list.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'event_allday':
				$allday = get_post_meta( $post_id, 'event_allday', true );
				printf(
					'<input type="checkbox" disabled %s>',
					checked( $allday, '1', false )
				);
				break;
			case 'event_start':
				$start = get_post_meta( $post_id, 'event_start', true );
				echo esc_html( $start );
				break;
			case 'event_end':
				$end = get_post_meta( $post_id, 'event_end', true );
				echo esc_html( $end );
				break;
			case 'event_category':
				$category = get_post_meta( $post_id, 'event_category', true );
				echo esc_html( $category );
				break;
		}
	}
}
