<?php
/**
 * Front-end asset registration for the public-facing side of the plugin.
 *
 * Builds the list of styles and scripts each Decker page needs, enqueues them
 * as a dependency chain, and hands the localized payloads to JavaScript.
 *
 * This file lives next to class-decker-public.php on purpose: the asset URLs are
 * resolved from __FILE__ / __DIR__, so both classes must sit in the same folder.
 *
 * @package    Decker
 * @subpackage Decker/public
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Public_Assets
 */
class Decker_Public_Assets {

	/**
	 * Register and localize every front-end asset for the current Decker page.
	 *
	 * Does nothing when the request is not a Decker page.
	 */
	public function enqueue() {
		$decker_page = get_query_var( 'decker_page' );

		if ( ! $decker_page ) {
			return;
		}

		$options                       = get_option( 'decker_settings', array() );
		$task_editor_type              = isset( $options['task_editor_type'] ) ? $options['task_editor_type'] : 'quill';
		$collaborative_editing_enabled = ! empty( $options['collaborative_editing'] ) && '1' === $options['collaborative_editing'];
		$use_quill_editor              = $collaborative_editing_enabled || 'quill' === $task_editor_type;

		$resources = $this->build_resource_list( $decker_page, $use_quill_editor );

		// Add collaborative editing module if enabled.
		$this->maybe_enqueue_collaboration();

		$localized_data = $this->build_localized_data( $task_editor_type, $use_quill_editor );

		$this->enqueue_resources( $resources );

		$this->localize_scripts( $localized_data );
	}

	/**
	 * Build the ordered list of asset URLs for a Decker page.
	 *
	 * The order is significant: enqueue_resources() chains every script to the
	 * previous one, so moving an entry changes the execution order in the browser.
	 * The shared bundle loads first, then whatever the current page and editor
	 * need, and finally the Decker modules that depend on everything above.
	 *
	 * @param string $decker_page      The current Decker page.
	 * @param bool   $use_quill_editor Whether the Quill editor is in use.
	 * @return array<int, string> Ordered list of asset URLs and core handles.
	 */
	private function build_resource_list( $decker_page, $use_quill_editor ) {
		return array_merge(
			$this->get_common_resources(),
			$this->get_page_resources( $decker_page, $use_quill_editor ),
			$this->get_footer_resources()
		);
	}

	/**
	 * Third-party bundles and base styles loaded on every Decker page.
	 *
	 * @return array<int, string> Ordered list of asset URLs and core handles.
	 */
	private function get_common_resources() {
		return array(
			// Register the main theme config script.
			plugin_dir_url( __FILE__ ) . '../public/assets/js/config.js',

			// WordPress REST API.
			'wp-api',

			// Bootstrap 5.
			'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css',
			'https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js',

			// Remix Icons.
			'https://cdn.jsdelivr.net/npm/remixicon@4.9.1/fonts/remixicon.min.css',

			// Tablesort.
			'https://cdn.jsdelivr.net/gh/tristen/tablesort@5.7.0/dist/tablesort.min.js',

			// Simplebar.
			'https://cdn.jsdelivr.net/npm/simplebar@6.3.3/dist/simplebar.min.js',

			// Font Awesome 5 Free (kept at 5.x; upgrading to 6.x requires icon class changes).
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css',

			// SortableJS.
			'https://cdn.jsdelivr.net/npm/sortablejs@1.15.7/Sortable.min.js',

			// Choices.js.
			'https://cdnjs.cloudflare.com/ajax/libs/choices.js/11.1.0/choices.min.js',
			'https://cdnjs.cloudflare.com/ajax/libs/choices.js/11.1.0/choices.min.css',

			// SweetAlert2.
			'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.21/dist/sweetalert2.all.min.js',
			'https://cdn.jsdelivr.net/npm/sweetalert2@11.26.21/dist/sweetalert2.min.css',

			// Chart.js.
			'https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js',

			// Custom files.
			plugin_dir_url( __FILE__ ) . '../public/assets/js/app.js',
			plugin_dir_url( __FILE__ ) . '../public/assets/js/sidebar-preferences.js',
			plugin_dir_url( __FILE__ ) . '../public/assets/css/attex.css',
			plugin_dir_url( __FILE__ ) . '../public/assets/css/app.css',

			plugin_dir_url( __FILE__ ) . '../public/assets/js/decker-public.js',
			plugin_dir_url( __FILE__ ) . '../public/assets/css/decker-public.css',

			plugin_dir_url( __FILE__ ) . '../public/assets/js/task-comments-popover.js',

			plugin_dir_url( __FILE__ ) . '../public/assets/js/task-labels-popover.js',

			plugin_dir_url( __FILE__ ) . '../public/assets/js/task-modal.js',

		);
	}

	/**
	 * Assets required by the current page and the selected task editor.
	 *
	 * Besides collecting URLs, some branches enqueue WordPress core bundles
	 * (media library, classic editor) that have no URL of their own.
	 *
	 * @param string $decker_page      The current Decker page.
	 * @param bool   $use_quill_editor Whether the Quill editor is in use.
	 * @return array<int, string> Ordered list of asset URLs.
	 */
	private function get_page_resources( $decker_page, $use_quill_editor ) {
		$resources = array();

		if ( $use_quill_editor ) {
			$resources[] = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.min.js';
			$resources[] = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.min.css';
			$resources[] = 'https://cdn.jsdelivr.net/npm/quill-html-edit-button@3.0.0/dist/quill.htmlEditButton.min.js';
			$resources[] = 'https://cdn.jsdelivr.net/npm/quill-cursors@4.1.0/dist/quill-cursors.min.js';
			$resources[] = 'https://cdn.jsdelivr.net/npm/quill-cursors@4.1.0/dist/quill-cursors.css';
		}

		if ( 'board' == $decker_page ) {
			// Dragula.
			$resources[] = 'https://cdnjs.cloudflare.com/ajax/libs/dragula/3.7.3/dragula.min.js';
		}

		if ( 'calendar' == $decker_page ) {

			// FullCalendar.
			$resources[] = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.20/index.global.min.js';

			$resources[] = plugin_dir_url( __FILE__ ) . '../public/assets/js/event-calendar.js';

		}

		if ( 'calendar' == $decker_page || 'event-manager' == $decker_page ) {

			$resources[] = plugin_dir_url( __FILE__ ) . '../public/assets/js/event-modal.js';
			$resources[] = plugin_dir_url( __FILE__ ) . '../public/assets/js/event-card.js';

		}

		if ( 'knowledge-base' == $decker_page ) {

			wp_enqueue_media(); // Required for media uploads.

			wp_enqueue_editor();

			// Page-specific script for Knowledge Base interactions.
			$resources[] = plugin_dir_url( __FILE__ ) . '../public/assets/js/knowledge-base.js';

		}

		if ( ! $use_quill_editor ) {
			// Load the WordPress Classic Editor assets and media library for task descriptions when Quill is not selected.
			wp_enqueue_editor();
			wp_enqueue_media();

			// Checklist support for the classic editor (Quill-compatible markup).
			$resources[] = plugin_dir_url( __FILE__ ) . '../public/assets/js/tinymce-checklist.js';
		}

		if ( 'tasks' == $decker_page ) { // Only load datatables.net on tasks page.
			// Datatables JS CDN.
			$resources[] = 'https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js';
			$resources[] = 'https://cdn.datatables.net/searchbuilder/1.6.0/js/dataTables.searchBuilder.min.js';
			$resources[] = 'https://cdn.datatables.net/select/1.7.0/js/dataTables.select.min.js';
			$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js';
			$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js';
			$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js';

			$resources[] = 'https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css';
			$resources[] = 'https://cdn.datatables.net/searchbuilder/1.6.0/css/searchBuilder.dataTables.min.css';
			$resources[] = 'https://cdn.datatables.net/select/1.7.0/css/select.dataTables.min.css';
			$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css';

		}

		return $resources;
	}

	/**
	 * Decker modules loaded last, after every dependency is already queued.
	 *
	 * @return array<int, string> Ordered list of asset URLs.
	 */
	private function get_footer_resources() {
		return array(
			plugin_dir_url( __FILE__ ) . '../public/assets/js/task-card.js',

			plugin_dir_url( __FILE__ ) . '../public/assets/js/decker-heartbeat.js',

			// Add global search script.
			plugin_dir_url( __FILE__ ) . '../public/assets/js/global-search.js',

			// Add the AI improvement module.
			plugin_dir_url( __FILE__ ) . '../public/assets/css/decker-ai.css',
			plugin_dir_url( __FILE__ ) . '../public/assets/js/decker-ai.js',
		);
	}

	/**
	 * Enqueue the resolved resource list.
	 *
	 * Each script is chained to the previously enqueued script so the browser
	 * keeps the declared execution order. Entries without a .css or .js
	 * extension (bundled WordPress handles) are enqueued separately beforehand.
	 *
	 * @param array<int, string> $resources Ordered list of asset URLs.
	 */
	private function enqueue_resources( $resources ) {
		$last_handle = '';

		// Add the bundled jQuery library.
		wp_enqueue_script( 'jquery' );

		// Ensure that the Heartbeat script is enqueued.
		wp_enqueue_script( 'heartbeat' );

		// Add the bundled Backbone library.
		wp_enqueue_script( 'wp-api' );

		foreach ( $resources as $resource ) {
			$handle = sanitize_title( basename( $resource, '.' . pathinfo( $resource, PATHINFO_EXTENSION ) ) );

			if ( false !== strpos( $resource, '.css' ) ) {
				wp_enqueue_style( $handle, $resource, array(), DECKER_VERSION );

			} elseif ( false !== strpos( $resource, '.js' ) ) {

				$deps = array();
				if ( $last_handle ) {
					$deps[] = $last_handle;
				}
				wp_enqueue_script( $handle, $resource, $deps, DECKER_VERSION, true );

				$last_handle = $handle; // Update last_handle to current script handle.

			}
		}
	}

	/**
	 * Collect the site users exposed to JavaScript.
	 *
	 * @return array<int, WP_User> Users with their nickname attached.
	 */
	private function get_users_for_js() {
		$users = get_users(
			array(
				'fields' => array( 'ID', 'display_name' ), // Campos nativos.
			)
		);

		// Add the nickname to each user.
		foreach ( $users as &$user ) {
			$user->nickname = get_user_meta( $user->ID, 'nickname', true ); // Replace 'alias' with your real meta key.
		}

		return $users;
	}

	/**
	 * Build the unified payload shared by the task and event scripts.
	 *
	 * @param string $task_editor_type Configured editor type.
	 * @param bool   $use_quill_editor Whether Quill is the effective editor.
	 * @return array<string, mixed> Localized data array.
	 */
	private function build_localized_data( $task_editor_type, $use_quill_editor ) {
		return array(
			'ajax_url'               => admin_url( 'admin-ajax.php' ),
			'home_url'               => home_url( '/' ),
			'login_url'              => wp_login_url( home_url( '/' ) ),
			'nonces'                 => array(
				'task_comment_nonce'      => wp_create_nonce( 'task_comment_nonce' ),
				'wp_rest_nonce'           => wp_create_nonce( 'wp_rest' ),
				'upload_attachment_nonce' => wp_create_nonce( 'upload_attachment_nonce' ),
				'delete_attachment_nonce' => wp_create_nonce( 'delete_attachment_nonce' ),
				'save_decker_task_nonce'  => wp_create_nonce( 'save_decker_task_nonce' ),
			),
			'strings'                => $this->get_ui_strings(),
			'timeFormat24h'          => ( get_option( 'time_format' ) === 'H:i' ),
			'disabled'               => isset( $disabled ) && $disabled ? true : false,
			'current_user_id'        => get_current_user_id(),
			'task_editor_type'       => $task_editor_type,
			'use_quill_editor'       => $use_quill_editor,
			'users'                  => $this->get_users_for_js(),
			'locale'                 => substr( get_user_locale(), 0, 2 ), // Ej: "es_ES" → "es".
			'taskPermalinkStructure' => get_option( 'permalink_structure' )
				? home_url( '/decker/task/%d/' )
				: home_url( '/?decker_task=%d' ),
			'ai'                     => $this->get_ai_config(),
		);
	}

	/**
	 * Translated UI strings handed to the front-end scripts.
	 *
	 * @return array<string, string> Map of string key to translated text.
	 */
	private function get_ui_strings() {
		return array(
			// Common strings.
			'confirm_delete_comment'        => __( 'Are you sure you want to delete this comment?', 'decker' ),
			'failed_delete_comment'         => __( 'Failed to delete comment.', 'decker' ),
			'error_deleting_comment'        => __( 'Error deleting comment.', 'decker' ),
			'please_select_file'            => __( 'Please select a file to upload.', 'decker' ),
			'confirm_delete_attachment'     => __( 'Are you sure you want to delete this attachment?', 'decker' ),
			'failed_delete_attachment'      => __( 'Failed to delete attachment.', 'decker' ),
			'error_uploading_attachment'    => __( 'Error uploading attachment.', 'decker' ),
			'delete'                        => __( 'Delete', 'decker' ),
			'server_response_error'         => __( 'Server response error.', 'decker' ),
			// Connection banner strings.
			'connection_offline'            => __( 'No internet connection. Your changes cannot be saved right now.', 'decker' ),
			'connection_lost'               => __( 'The server is not responding. Your changes cannot be saved right now.', 'decker' ),
			'connection_restored'           => __( 'Connection restored.', 'decker' ),
			'session_expired_message'       => __( 'Your session has expired. Log in again to keep working.', 'decker' ),
			'log_in_again'                  => __( 'Log in again', 'decker' ),
			'an_error_occurred_saving_task' => __( 'An error occurred while saving the task.', 'decker' ),
			'request_error'                 => __( 'Request error.', 'decker' ),
			'error_saving_task'             => __( 'Error saving task.', 'decker' ),
			'task_saved_success'            => __( 'The task has been saved successfully.', 'decker' ),
			'show_html_source'              => __( 'Show HTML source', 'decker' ),
			'edit_html_content'             => __( 'Edit the content in HTML format', 'decker' ),
			'ok'                            => __( 'OK', 'decker' ),
			'cancel'                        => __( 'Cancel', 'decker' ),
			// Additional strings (from first version).
			'confirm_archive_task_title'    => __( 'Are you sure you want to archive this task?', 'decker' ),
			'confirm_archive_task_text'     => __( 'This action will move the task to the archive.', 'decker' ),
			'confirm_unarchive_task_title'  => __( 'Are you sure you want to unarchive this task?', 'decker' ),
			'confirm_unarchive_task_text'   => __( 'This action will restore the task.', 'decker' ),
			'archive_task'                  => __( 'Archive', 'decker' ),
			'unarchive_task'                => __( 'Unarchive', 'decker' ),
			'failed_archive_task'           => __( 'Failed to archive task.', 'decker' ),
			'task_archived_success'         => __( 'The task has been successfully archived.', 'decker' ),
			'task_unarchived_success'       => __( 'The task has been successfully unarchived.', 'decker' ),
			'error_archiving_task'          => __( 'An error occurred while archiving the task.', 'decker' ),
			// Clone task strings.
			'confirm_clone_task_title'      => __( 'Are you sure you want to clone this task?', 'decker' ),
			'confirm_clone_task_text'       => __( 'A copy of this task will be created.', 'decker' ),
			'clone_task'                    => __( 'Clone', 'decker' ),
			'task_cloned_success'           => __( 'The task has been successfully cloned.', 'decker' ),
			'error_cloning_task'            => __( 'An error occurred while cloning the task.', 'decker' ),
			// Merge task strings.
			'merge_task'                    => __( 'Merge into...', 'decker' ),
			'merge_task_title'              => __( 'Merge task', 'decker' ),
			'merge_task_text'               => __( 'Choose the destination task that should remain active.', 'decker' ),
			'merge_task_search_placeholder' => __( 'Search tasks by title', 'decker' ),
			'merge_task_search_hint'        => __( 'Type at least 2 characters to search for a destination task.', 'decker' ),
			'merge_task_searching'          => __( 'Searching tasks...', 'decker' ),
			'merge_task_no_results'         => __( 'No matching tasks found.', 'decker' ),
			'merge_task_select_label'       => __( 'Destination task', 'decker' ),
			'select_task_to_merge'          => __( 'Please select a destination task.', 'decker' ),
			'confirm_merge_task_title'      => __( 'Are you sure you want to merge this task?', 'decker' ),
			'confirm_merge_task_text'       => __( 'The current task will be archived and merged into the selected destination task.', 'decker' ),
			'confirm_merge_task_button'     => __( 'Merge task', 'decker' ),
			'task_merged_success'           => __( 'The task has been successfully merged.', 'decker' ),
			'error_merging_task'            => __( 'An error occurred while merging the task.', 'decker' ),
			// Extra keys from first version.
			'success'                       => __( 'Success', 'decker' ),
			'error'                         => __( 'Error', 'decker' ),
			'today'                         => __( 'Today', 'decker' ),
			'month'                         => __( 'Month', 'decker' ),
			'week'                          => __( 'Week', 'decker' ),
			'day'                           => __( 'Day', 'decker' ),
			'list'                          => __( 'List', 'decker' ),
			'unsaved_changes_title'         => __( 'Unsaved changes', 'decker' ),
			'unsaved_changes_text'          => __( 'You have unsaved changes. Close without saving?', 'decker' ),
			'close_anyway'                  => __( 'Close anyway', 'decker' ),
			'confirm_delete_event'          => __( 'Are you sure you want to delete this event?', 'decker' ),
			'task_url_copied'               => __( 'Task URL copied!', 'decker' ),
			'task_url_copy_error'           => __( 'Could not copy URL. Please copy it manually:', 'decker' ),
			'copy_task_url'                 => __( 'Copy Task URL', 'decker' ),
			'checklist'                     => __( 'Checklist', 'decker' ),
			'loading_comments'              => __( 'Loading comments…', 'decker' ),
			'no_comments'                   => __( 'No comments yet.', 'decker' ),
			'comments_error'                => __( 'Could not load comments.', 'decker' ),
			/* translators: %d is the number of additional comments not shown in the popover preview. */
			'more_comments'                 => __( 'and %d more', 'decker' ),
			// Task edit lock strings.
			'take_over_editing'             => __( 'Take over editing', 'decker' ),
			'taking_over'                   => __( 'Taking over…', 'decker' ),
			/* translators: %s is the display name of the user currently editing the card. */
			'card_locked_by'                => __( 'This card is currently locked by %s.', 'decker' ),
			'lock_lost_title'               => __( 'Editing taken over', 'decker' ),
			'lock_lost_message'             => __( 'You can no longer save this card because another user has taken over editing. Please reload the card to see the latest changes.', 'decker' ),
			'lock_takeover_failed'          => __( 'The card could not be taken over. Please reload and try again.', 'decker' ),
			'reload_card'                   => __( 'Reload card', 'decker' ),
			// "For today" quick-action strings.
			'add_to_today'                  => __( 'Add to today', 'decker' ),
			'remove_from_today'             => __( 'Remove from today', 'decker' ),
			'adding_to_today'               => __( 'Adding to today…', 'decker' ),
			'removing_from_today'           => __( 'Removing from today…', 'decker' ),
			'today_update_failed'           => __( 'The task could not be updated.', 'decker' ),
		);
	}

	/**
	 * Hand the localized payloads to the scripts that consume them.
	 *
	 * @param array<string, mixed> $localized_data Unified payload shared by task and event scripts.
	 */
	private function localize_scripts( $localized_data ) {
		// Localize the script with new data.
		wp_localize_script(
			'task-modal',
			'jsdata_task',
			array(
				'ajaxUrl'        => esc_url( admin_url( 'admin-ajax.php' ) ),
				'url'            => esc_url( plugins_url( 'public/layouts/task-card.php', __DIR__ ) ),
				'loadingMessage' => esc_html__( 'Loading content. Please wait.', 'decker' ),
				'errorMessage'   => esc_html__( 'Error loading content. Please try again.', 'decker' ),
				'nonce'          => wp_create_nonce( 'decker_task_card' ),
			)
		);

		// Add inline script for the current user.
		wp_add_inline_script(
			'config', // The handle of the config.js file.
			'const userId = ' . get_current_user_id() . ';',
			'before',
		);

		// Localize the script with new data.
		$script_data = array(
			'userId' => get_current_user_id(),
		);

		wp_localize_script( 'decker-public', 'deckerData', $script_data );

		// Use unified $localized_data for task-card and other scripts.
		wp_localize_script( 'task-card', 'deckerVars', $localized_data );

		// Localize the script so that it has ajaxurl and nonce.
		wp_localize_script(
			'decker-heartbeat',
			'DeckerData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'heartbeat-nonce' ),
				'userId'  => get_current_user_id(),
				'labels'  => array(
					'delete'              => __( 'Delete', 'decker' ),
					'delete_notification' => __( 'Delete notification', 'decker' ),
				),
			)
		);

		// Localize the script with new data.
		wp_localize_script(
			'event-modal',
			'jsdata_event',
			array(
				'ajaxUrl'        => esc_url( admin_url( 'admin-ajax.php' ) ),
				'url'            => esc_url( plugins_url( 'public/layouts/event-card.php', __DIR__ ) ),
				'loadingMessage' => esc_html__( 'Loading content. Please wait.', 'decker' ),
				'errorMessage'   => esc_html__( 'Error loading content. Please try again.', 'decker' ),
				'nonce'          => wp_create_nonce( 'decker_event_card' ),
			)
		);

		// TODO: This can be removed, review.
		wp_localize_script( 'event-card', 'deckerVars', $localized_data );

		// Localize the global search script.
		wp_localize_script(
			'global-search',
			'deckerSearchVars',
			array(
				'restUrl' => rest_url(),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'strings' => array(
					'search_placeholder' => __( 'Search tasks...', 'decker' ),
					'search_hint'        => __( 'Type to search tasks by title', 'decker' ),
					'navigate'           => __( 'to navigate', 'decker' ),
					'select'             => __( 'to select', 'decker' ),
					'close'              => __( 'to close', 'decker' ),
					'no_results'         => __( 'No tasks found', 'decker' ),
					'error'              => __( 'Error searching tasks', 'decker' ),
				),
			)
		);
	}

	/**
	 * Build the AI configuration object passed to JavaScript.
	 *
	 * Exposes browser-only AI prompts and all required UI strings.
	 *
	 * @return array AI configuration array.
	 */
	private function get_ai_config() {
		$options = get_option( 'decker_settings', array() );

		return array(
			'enabled'          => isset( $options['ai_enabled'] ) && '1' === $options['ai_enabled'],
			'provider'         => Decker_AI_Manager::get_selected_provider( $options ),
			'api_endpoint'     => Decker_AI_Manager::get_rest_endpoint_url(),
			'server_available' => '' !== Decker_AI_Manager::get_api_key( $options ),
			'strings'          => array(
				'improve_with_ai'          => __( 'Improve with AI', 'decker' ),
				'choose_action'            => __( 'Choose an action', 'decker' ),
				'mode_improve_description' => __( 'Improve description', 'decker' ),
				'mode_make_actionable'     => __( 'Make it actionable', 'decker' ),
				'mode_generate_checklist'  => __( 'Generate checklist', 'decker' ),
				'mode_summarize'           => __( 'Summarize', 'decker' ),
				'improving'                => __( 'Improving text…', 'decker' ),
				'preview_title'            => __( 'Review improvement', 'decker' ),
				'original_text'            => __( 'Original', 'decker' ),
				'improved_text'            => __( 'Improved', 'decker' ),
				'accept'                   => __( 'Accept', 'decker' ),
				'cancel'                   => __( 'Cancel', 'decker' ),
				'error'                    => __( 'Error', 'decker' ),
				'error_message'            => __( 'An error occurred while improving the text.', 'decker' ),
				'no_content'               => __( 'No content', 'decker' ),
				'no_content_message'       => __( 'Please add some text before using AI improvement.', 'decker' ),
				'ai_unavailable_title'     => __( 'Browser AI unavailable', 'decker' ),
				'ai_unavailable_intro'     => __( 'This AI action requires a compatible browser with built-in AI support.', 'decker' ),
				'ai_chrome_unavailable'    => __( 'Chrome can use the Prompt API, but built-in AI is not currently available or enabled in this browser profile.', 'decker' ),
				'ai_edge_unavailable'      => __( 'Microsoft Edge can support the experimental Prompt API, but it is not available in this browser profile.', 'decker' ),
				'ai_download_required'     => __( 'The browser AI model is not ready yet. Finish downloading or enabling the built-in model and try again.', 'decker' ),
				'ai_browser_unsupported'   => __( 'This feature currently requires a compatible browser with built-in AI support, such as Chrome or Microsoft Edge with the Prompt API enabled.', 'decker' ),
				'ai_help_link'             => __( 'Open setup guide', 'decker' ),
				'ai_session_error'         => __( 'The browser AI session could not be started.', 'decker' ),
				'ai_empty_response'        => __( 'The browser AI response was empty.', 'decker' ),
				'ai_api_missing_key'       => __( 'The Gemini API provider is selected, but no API key has been saved in Decker settings. Please ask an administrator to configure it.', 'decker' ),
				'ai_api_request_error'     => __( 'The Gemini API request failed. Please try again in a moment.', 'decker' ),
				'yes'                      => _x( 'Yes', 'AI task context boolean value', 'decker' ),
				'no'                       => _x( 'No', 'AI task context boolean value', 'decker' ),
			),
			'prompts'          => Decker_AI_Manager::get_prompt_config( $options ),
		);
	}

	/**
	 * Enqueue collaborative editing module if enabled.
	 *
	 * Loads the Yjs-based collaboration module as an ES module.
	 */
	private function maybe_enqueue_collaboration() {
		$options = get_option( 'decker_settings', array() );

		// Check if collaborative editing is enabled.
		if ( empty( $options['collaborative_editing'] ) || '1' !== $options['collaborative_editing'] ) {
			return;
		}

		$current_user     = wp_get_current_user();
		$signaling_server = ! empty( $options['signaling_server'] ) ? $options['signaling_server'] : 'wss://signaling.yjs.dev';

		// Generate a user color based on user ID for consistency.
		$colors     = array( '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7', '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9' );
		$user_color = $colors[ $current_user->ID % count( $colors ) ];

		// Prepare configuration for the collaboration module.
		$collab_config = array(
			'enabled'         => true,
			'signalingServer' => esc_url( $signaling_server, array( 'wss', 'ws', 'https', 'http' ) ),
			'roomPrefix'      => 'decker-task-' . sanitize_key( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-',
			'userName'        => esc_js( $current_user->display_name ),
			'userColor'       => $user_color,
			'userId'          => $current_user->ID,
			'userAvatar'      => esc_url( get_avatar_url( $current_user->ID, array( 'size' => 32 ) ) ),
			'strings'         => array(
				'connecting'         => __( 'Connecting...', 'decker' ),
				'collaborative_mode' => __( 'Collaborative mode', 'decker' ),
				'disconnected'       => __( 'Disconnected', 'decker' ),
				'you'                => __( 'you', 'decker' ),
			),
		);

		// Add inline script to set configuration before the module loads.
		add_action(
			'wp_footer',
			function () use ( $collab_config ) {
				$config_json = wp_json_encode( $collab_config );
				$module_url  = esc_url( plugin_dir_url( __FILE__ ) . 'assets/js/decker-collaboration.js' );
				?>
				<script>
					window.deckerCollabConfig = <?php echo $config_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				</script>
				<script type="module" src="<?php echo $module_url; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript,WordPress.Security.EscapeOutput.OutputNotEscaped -- ES modules require type="module" which wp_enqueue_script doesn't support ?>"></script>
				<?php
			},
			100
		);
	}
}
