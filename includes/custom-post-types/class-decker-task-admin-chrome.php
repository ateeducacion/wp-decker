<?php
/**
 * Edit-screen and menu chrome for tasks.
 *
 * The small admin-side tweaks around the decker_task edit screen and its
 * menu entry: hiding the visibility and menu_order controls, hiding the
 * permalink/slug box, relabeling the publish meta box, disabling
 * Gutenberg, and removing the "Add New" submenu link. Presentation for
 * administrators, kept apart from the post type registration and meta
 * handling in Decker_Tasks.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Admin_Chrome
 */
class Decker_Task_Admin_Chrome {

	/**
	 * Hook the edit-screen and menu tweaks.
	 */
	public function __construct() {
		add_action( 'admin_head', array( $this, 'hide_visibility_options' ) );
		add_action( 'admin_head', array( $this, 'disable_menu_order_field' ) );
		add_action( 'admin_head', array( $this, 'hide_permalink_and_slug' ) );
		add_action( 'admin_head', array( $this, 'change_publish_meta_box_title' ) );
		add_action( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'remove_add_new_link' ) );
	}

	/**
	 * Remove 'Add New' button for decker_task post type.
	 */
	public function remove_add_new_link() {
		global $submenu;
		// Remove the "Add New" submenu link.
		if ( isset( $submenu['edit.php?post_type=decker_task'] ) ) {
			foreach ( $submenu['edit.php?post_type=decker_task'] as $key => $item ) {
				// Searches for the "Add New Entry" item.
				if ( 'post-new.php?post_type=decker_task' === $item[2] ) {
					unset( $submenu['edit.php?post_type=decker_task'][ $key ] );
				}
			}
		}
	}

	/**
	 * Hide the permalink and slug for decker_task.
	 */
	public function hide_permalink_and_slug() {
		$screen = get_current_screen();
		if ( $screen && 'decker_task' == $screen->post_type && 'post' == $screen->base ) {
			echo '<style type="text/css">
				#edit-slug-box, #post-name { display: none; }
			</style>';
		}
	}

	/**
	 * Disables the menu_order field in the admin interface for decker_task.
	 */
	public function disable_menu_order_field() {
		$screen = get_current_screen();
		if ( $screen && 'decker_task' === $screen->post_type && 'post' === $screen->base ) {
			?>
			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function() {
					// Disable the menu_order field.
					var menuOrderField = document.getElementById('menu_order');
					if (menuOrderField) {
						menuOrderField.disabled = true;
					}
				});
			</script>
			<?php
		}
	}

	/**
	 * Disable Gutenberg editor for decker_task.
	 *
	 * @param bool   $current_status The current status.
	 * @param string $post_type      The post type.
	 * @return bool The modified status.
	 */
	public function disable_gutenberg( $current_status, $post_type ) {
		if ( 'decker_task' === $post_type ) {
			return false;
		}
		return $current_status;
	}

	/**
	 * Changes the title of the publish meta box for the 'decker_task' post type.
	 *
	 * Updates the title of the publish meta box to "Status" using JavaScript
	 * when editing or creating a task of the 'decker_task' post type.
	 *
	 * @return void Outputs a script to modify the meta box title dynamically.
	 */
	public function change_publish_meta_box_title() {
		global $post_type;
		if ( 'decker_task' === $post_type ) {
			echo '<script>
	            jQuery(document).ready(function($) {
	                jQuery("#submitdiv .hndle").text("' . esc_html__( 'Status', 'decker' ) . '");
	            });
	        </script>';
		}
	}

	/**
	 * Hide visibility options for decker_task post type.
	 */
	public function hide_visibility_options() {
		global $post_type;
		if ( 'decker_task' == $post_type ) {

			echo '<style type="text/css">
	            .misc-pub-section.misc-pub-visibility {
	                display: none;
	            }
		        /* hide the parent of the group of password and private */
		        .inline-edit-group.wp-clearfix .inline-edit-password-input,
		        .inline-edit-group.wp-clearfix .inline-edit-private,
		        .inline-edit-group.wp-clearfix .inline-edit-or,
		        .inline-edit-group.wp-clearfix {
		            display: none;
		        }
		        .page-title-action {
	               	display: none !important;
	            }
			</style>';
		}
	}
}
