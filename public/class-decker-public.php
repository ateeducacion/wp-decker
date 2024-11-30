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
	 * Initialize the class and set its properties.
	 *
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version           The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'init', array( $this, 'decker_add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'decker_query_vars' ) );
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
	}

	/**
	 * Add custom query vars.
	 *
	 * @param array $vars The WP query vars array.
	 * @return array Modified WP query vars array.
	 */
	public function decker_query_vars( $vars ) {
		$vars[] = 'decker_page';
		$vars[] = 'board_slug';
		return $vars;
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
			if ( ! Decker_Utility_Functions::current_user_has_at_least_minimum_role() ) {
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
			'analytics'       => 'public/app-analytics.php',
			'my-board'        => 'public/app-kanban-my.php',
			'board'           => 'public/app-kanban.php',
			'task'            => 'public/app-task-full.php',
			'tasks'           => 'public/app-tasks.php',
			'upcoming'        => 'public/app-upcoming.php',
			'priority'        => 'public/app-priority.php',
			'term-manager'    => 'public/app-term-manager.php',
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
		$decker_page = get_query_var( 'decker_page' );

		if ( $decker_page ) {
			$resources = array(
				// Register the main theme config script.
				plugin_dir_url( __FILE__ ) . '../public/assets/js/config.js',

				// Bootstrap 5.
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',

				// Remix Icons.
				'https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.5.0/remixicon.min.css',

				// Tablesort.
				'https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/tablesort.min.js',

				// Simplebar.
				'https://cdn.jsdelivr.net/npm/simplebar@6.2.7/dist/simplebar.min.js',

				// Day.js.
				'https://unpkg.com/dayjs/dayjs.min.js',
				'https://unpkg.com/dayjs/plugin/relativeTime.js',
				'https://unpkg.com/dayjs/locale/es.js',

				// Font Awesome.
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css',

				// SortableJS.
				'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js',

				// Quill.
				'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.snow.min.css',

				// Choices.js.
				'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js',
				'https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css',

				// Custom files.
				plugin_dir_url( __FILE__ ) . '../public/assets/js/app.js',
				plugin_dir_url( __FILE__ ) . '../public/assets/css/app.min.css',

				plugin_dir_url( __FILE__ ) . '../public/assets/js/decker-public.js',
				plugin_dir_url( __FILE__ ) . '../public/assets/css/decker-public.css',

				plugin_dir_url( __FILE__ ) . '../public/assets/js/task-modal.js',
			);

			if ( 'analytics' == $decker_page ) {
				// Chart.js.
				$resources[] = 'https://cdn.jsdelivr.net/npm/chart.js';
			}

			if ( 'board' == $decker_page ) {
				// Dragula.
				$resources[] = 'https://cdnjs.cloudflare.com/ajax/libs/dragula/3.7.3/dragula.min.js';
			}

			if ( 'tasks' == $decker_page ) { // Only load datatables.net on tasks page.
				// Datatables JS CDN.
				$resources[] = 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js';
				$resources[] = 'https://cdn.datatables.net/searchbuilder/1.6.0/js/dataTables.searchBuilder.min.js';
				$resources[] = 'https://cdn.datatables.net/select/1.7.0/js/dataTables.select.min.js';
				$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js';
				$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js';
				$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js';

				$resources[] = 'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css';
				$resources[] = 'https://cdn.datatables.net/searchbuilder/1.6.0/css/searchBuilder.dataTables.min.css';
				$resources[] = 'https://cdn.datatables.net/select/1.7.0/css/select.dataTables.min.css';
				$resources[] = 'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css';
			}

			$last_handle = '';

			wp_enqueue_script( 'jquery' );

			foreach ( $resources as $resource ) {
				$handle = sanitize_title( basename( $resource, '.' . pathinfo( $resource, PATHINFO_EXTENSION ) ) );

				if ( false !== strpos( $resource, '.css' ) ) {
					wp_enqueue_style( $handle, $resource, array(), null );

				} elseif ( false !== strpos( $resource, '.js' ) ) {

					$deps = array();
					if ( $last_handle ) {
						$deps[] = $last_handle;
					}
					wp_enqueue_script( $handle, $resource, $deps, null, true );

					$last_handle = $handle; // Update last_handle to current script handle.

				}
			}

			wp_localize_script(
				'task-modal', // task-modal script handle.
				'jsdata',
				array(
					'ajaxUrl'      => esc_url( admin_url( 'admin-ajax.php' ) ),
					'url'          => esc_url( plugins_url( 'public/layouts/task-card.php', __DIR__ ) ),
					'errorMessage' => esc_html__( 'Error loading content. Please try again.', 'decker' ),
					'nonce'        => wp_create_nonce( 'decker_task_card' ),
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
				'userId'       => get_current_user_id(),
			);

			wp_localize_script( 'decker-public', 'deckerData', $script_data );

		}
	}
}
