<?php
/**
 * AJAX Handlers
 *
 * @package decker
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;

class Decker_Ajax_Handlers {

	/**
	 * Handles the AJAX request to load data from the Nextcloud Deck API.
	 */
	public static function load() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'Session not started', 'decker' ) );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( __( 'Insufficient permissions', 'decker' ) );
		}

		// Determine if the script should process archived data or not.
		process_deck_api( false );

		// Exit to prevent WP from adding anything else to the JSON.
		wp_die();
	}

	/**
	 * Handles the AJAX request to load archived data from the Nextcloud Deck API.
	 */
	public static function load_archived() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Determine if the script should process archived data or not.
		process_deck_api( true );

		// Exit to prevent WP from adding anything else to the JSON.
		wp_die();
	}

	/**
	 * Creates a new task in Nextcloud Deck via an API call.
	 */
	public static function new_task() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to create a new task.
		process_new_task();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Assigns a label to a task in Nextcloud Deck via an API call.
	 */
	public static function assign_label() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to assign a label to a task.
		process_assign_label();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Creates a label for a task in all my boards in Nextcloud Deck via an API call.
	 */
	public static function create_label() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to create a label.
		process_create_label();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Changes the task stack in Nextcloud Deck via an API call.
	 */
	public static function change_stack() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to change the stack of a task.
		process_change_stack();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Marks a task as "today" in Nextcloud Deck via an API call.
	 */
	public static function set_today() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to mark a task as "today".
		process_set_today();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Deletes a task in Nextcloud Deck via an API call.
	 */
	public static function delete() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to delete a task.
		process_delete();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Retrieves a task in Nextcloud Deck via an API call.
	 */
	public static function get_card() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( 'Session not started' );
		}

		if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Launch the process to retrieve a task.
		process_get_card();

		// Always ensure to end with wp_die() in AJAX handlers to avoid additional output.
		wp_die();
	}

	/**
	 * Adds the necessary hooks to handle AJAX requests.
	 */
	public static function register_ajax_handlers() {
		// Load data.
		add_action( 'wp_ajax_decker_load', array( __CLASS__, 'load' ) );
		add_action( 'wp_ajax_nopriv_decker_load', array( __CLASS__, 'load' ) );

		// Load archived data.
		add_action( 'wp_ajax_decker_load_archived', array( __CLASS__, 'load_archived' ) );
		add_action( 'wp_ajax_nopriv_decker_load_archived', array( __CLASS__, 'load_archived' ) );

		// Create new tasks.
		add_action( 'wp_ajax_decker_newtask', array( __CLASS__, 'new_task' ) );
		add_action( 'wp_ajax_nopriv_decker_newtask', array( __CLASS__, 'new_task' ) );

		// Assign labels to tasks.
		add_action( 'wp_ajax_decker_assignlabel', array( __CLASS__, 'assign_label' ) );
		add_action( 'wp_ajax_nopriv_decker_assignlabel', array( __CLASS__, 'assign_label' ) );

		// Create new labels.
		add_action( 'wp_ajax_decker_createlabel', array( __CLASS__, 'create_label' ) );
		add_action( 'wp_ajax_nopriv_decker_createlabel', array( __CLASS__, 'create_label' ) );

		// Change the stack of a task.
		add_action( 'wp_ajax_decker_changestack', array( __CLASS__, 'change_stack' ) );
		add_action( 'wp_ajax_nopriv_decker_changestack', array( __CLASS__, 'change_stack' ) );

		// Mark a task as today.
		add_action( 'wp_ajax_decker_settoday', array( __CLASS__, 'set_today' ) );
		add_action( 'wp_ajax_nopriv_decker_settoday', array( __CLASS__, 'set_today' ) );

		// Delete a task.
		add_action( 'wp_ajax_decker_delete', array( __CLASS__, 'delete' ) );
		add_action( 'wp_ajax_nopriv_decker_delete', array( __CLASS__, 'delete' ) );

		// Retrieve a task.
		add_action( 'wp_ajax_decker_getcard', array( __CLASS__, 'get_card' ) );
		add_action( 'wp_ajax_nopriv_decker_getcard', array( __CLASS__, 'get_card' ) );
	}
}

Decker_Ajax_Handlers::register_ajax_handlers();
