<?php
/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and hooks for enqueueing scripts, rewrite rules, query vars, and template redirects.
 *
 * @package    Decker
 * @subpackage Decker/public
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Public
 */
class Decker_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Registrar for the front-end styles and scripts.
	 *
	 * @access   private
	 * @var      Decker_Public_Assets    $assets    Builds and enqueues the public assets.
	 */
	private $assets;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version           The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->assets      = new Decker_Public_Assets();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'init', array( $this, 'decker_add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'decker_query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'decker_pre_get_posts' ) );
		add_action( 'template_redirect', array( $this, 'decker_template_redirect' ) );
	}

	/**
	 * Add rewrite rules for Decker pages.
	 */
	public function decker_add_rewrite_rules() {
		add_rewrite_rule( '^decker/?$', 'index.php?decker_page=priority', 'top' );
		add_rewrite_rule( '^decker/board/([^/]+)/?$', 'index.php?decker_page=board&slug=$matches[1]', 'top' );
		add_rewrite_rule( '^decker/analytics/?$', 'index.php?decker_page=analytics', 'top' );
		add_rewrite_rule( '^decker/task/new/?$', 'index.php?decker_page=task', 'top' );
		add_rewrite_rule( '^decker/task/([^/]+)/?$', 'index.php?decker_page=task&id=$matches[1]', 'top' );
		add_rewrite_rule( '^decker/tasks/?$', 'index.php?decker_page=tasks&type=active', 'top' );
		add_rewrite_rule( '^decker/tasks/active/?$', 'index.php?decker_page=tasks&type=active', 'top' );
		add_rewrite_rule( '^decker/tasks/my/?$', 'index.php?decker_page=tasks_my', 'top' );
		add_rewrite_rule( '^decker/tasks/archived/?$', 'index.php?decker_page=tasks_archived', 'top' );

		// Short task URL.
		add_rewrite_rule( '^t/(\d+)/?$', 'index.php?decker_page=task&id=$matches[1]', 'top' );
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array $vars The WP query vars array.
	 * @return array Modified WP query vars array.
	 */
	public function decker_query_vars( $vars ) {
		$vars[] = 'decker_page';
		$vars[] = 'slug';
		$vars[] = 'decker_task';
		$vars[] = 'id';
		return $vars;
	}

	/**
	 * Modify the main query for Decker pages before it runs.
	 *
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 */
	public function decker_pre_get_posts( $query ) {
		// Only modify the main query on the front-end.
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$decker_task_id = $query->get( 'decker_task' );

		// Check if we are viewing a single 'decker_task' via its canonical URL.
		if ( $query->is_singular( 'decker_task' ) || ! empty( $decker_task_id ) ) {
			// Set 'decker_page' to 'task' so our other hooks recognize it.
			$query->set( 'decker_page', 'task' );

			// Get the task ID, whether from a pretty permalink or a plain one.
			$task_id_to_set = ! empty( $decker_task_id ) ? $decker_task_id : $query->get_queried_object_id();

			// Set the 'id' query var so get_query_var('id') will work in the template.
			if ( $task_id_to_set ) {
				$query->set( 'id', $task_id_to_set );
			}
		}
	}

	/**
	 * Template redirect for Decker page.
	 */
	public function decker_template_redirect() {
		$decker_page = get_query_var( 'decker_page' );

		if ( $decker_page ) {
			// Verify if user is logged in.
			if ( ! is_user_logged_in() ) {
				$this->redirect_to_login();
				exit;
			}

			// Check if the current user has at least the required role.
			if ( ! Decker::current_user_has_at_least_minimum_role() ) {
				$this->deny_access();
			}

			// Include the corresponding Decker page.
			$this->include_decker_page( $decker_page );

			exit;
		}
	}


	/**
	 * Redirect the user to the login page.
	 */
	protected function redirect_to_login() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$redirect_url = home_url( $request_uri );
		$login_url = wp_login_url( $redirect_url );
		wp_safe_redirect( $login_url );
	}

	/**
	 * Deny access to the user.
	 */
	protected function deny_access() {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'decker' ) );
	}

	/**
	 * Include the corresponding file according to the Decker page.
	 *
	 * @param string $decker_page The Decker page to include.
	 */
	protected function include_decker_page( $decker_page ) {
		$include_files = array(
			'analytics'      => 'public/app-analytics.php',
			'board'          => 'public/app-kanban.php',
			'calendar'       => 'public/app-calendar.php',
			'my-board'       => 'public/app-kanban-my.php',
			'priority'       => 'public/app-priority.php',
			'task'           => 'public/app-task-full.php',
			'tasks'          => 'public/app-tasks.php',
			'term-manager'   => 'public/app-term-manager.php',
			'upcoming'       => 'public/app-upcoming.php',
			'event-manager'  => 'public/app-event-manager.php',
			'knowledge-base' => 'public/app-knowledge-base.php',
		);

		if ( array_key_exists( $decker_page, $include_files ) ) {
			$file_path = plugin_dir_path( __DIR__ ) . $include_files[ $decker_page ];
			include apply_filters( 'decker_include_file', $file_path, $decker_page );
		}
	}

	/**
	 * Register the JavaScript and stylesheets for the public-facing side of the site.
	 */
	public function enqueue_scripts() {
		$this->assets->enqueue();
	}
}
