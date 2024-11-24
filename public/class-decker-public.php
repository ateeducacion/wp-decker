<?php

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
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
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		// Not yet used
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'init', array( $this, 'decker_add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'decker_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'decker_template_redirect' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes for boards and tasks.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'decker/v1',
			'/board/(?P<slug>[a-zA-Z0-9-]+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_board' ),
			)
		);

		register_rest_route(
			'decker/v1',
			'/task/(?P<slug>[a-zA-Z0-9-]+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get_task' ),
			)
		);
	}

	// Define the callback functions for the REST API routes here.
	// For example:
	public function get_board( $request ) {
		// Logic to retrieve board by slug.
	}

	public function get_task( $request ) {
		// Logic to retrieve task by slug.
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

		flush_rewrite_rules();
	}

	/**
	 * Add custom query vars.
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
				// Retrieve and sanitize the current request URI.
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

				// Build the full redirect URL, respecting the site's protocol and maintaining query arguments.
				$redirect_url = home_url( $request_uri );

				// Get the login URL with the redirect URL as a parameter.
				$login_url = wp_login_url( $redirect_url );

				// Safely redirect the user to the login page.
				wp_safe_redirect( $login_url );
				exit;
			}

			// Verify user permissions.
			if ( ! current_user_can( 'decker_role' ) && ! is_super_admin() ) {
				die( esc_attr_e( 'You do not have permission to view this page.', 'decker' ) );
			}

			switch ( $decker_page ) {
				case 'analytics':
					include plugin_dir_path( __DIR__ ) . 'public/app-analytics.php';
					break;
				case 'my-board':
					include plugin_dir_path( __DIR__ ) . 'public/app-kanban-my.php';
					break;
				case 'board':
					include plugin_dir_path( __DIR__ ) . 'public/app-kanban.php';
					break;
				case 'task':
					include plugin_dir_path( __DIR__ ) . 'public/app-task-full.php';
					break;
				case 'tasks':
					include plugin_dir_path( __DIR__ ) . 'public/app-tasks.php';
					break;
				case 'upcoming':
					include plugin_dir_path( __DIR__ ) . 'public/app-upcoming.php';
					break;
				case 'priority':
					include plugin_dir_path( __DIR__ ) . 'public/app-priority.php';
					break;
				case 'term-manager':
					include plugin_dir_path( __DIR__ ) . 'public/app-term-manager.php';
					break;

				default:
					// Default action if no match is found
					break;
			}

			exit;
		}
	}


	/**
	 * Register the JavaScript and stylesheets for the public-facing side of the site.
	 */
	public function enqueue_scripts() {

		$decker_page = get_query_var( 'decker_page' );

		if ( $decker_page ) {

			// Registra el script principal.
			// wp_register_script( 'config-js', plugin_dir_url( __FILE__ ) . '../public/assets/js/config.js', array(), null, true );

			$resources = array(

				plugin_dir_url( __FILE__ ) . '../public/assets/js/config.js',

				// jQuery.
				// 'https://code.jquery.com/jquery-3.7.1.min.js',

				// Bootstrap 5.
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
				'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',

				// Remix Icons
				'https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.5.0/remixicon.min.css',

				// Tablesort
				'https://cdnjs.cloudflare.com/ajax/libs/tablesort/5.2.1/tablesort.min.js',

				// Simplebar
				'https://cdn.jsdelivr.net/npm/simplebar@6.2.7/dist/simplebar.min.js',

				// Day.js
				'https://unpkg.com/dayjs/dayjs.min.js',
				'https://unpkg.com/dayjs/plugin/relativeTime.js',
				'https://unpkg.com/dayjs/locale/es.js',

				// // Datatables.net.
				// 'https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.min.css',
				// 'https://cdn.datatables.net/2.0.8/js/dataTables.min.js',
				// 'https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.min.js',

				// // Datatables.net Buttons extension.
				// 'https://cdn.datatables.net/buttons/3.0.2/css/buttons.bootstrap5.min.css',
				// 'https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.min.js',
				// 'https://cdn.datatables.net/buttons/3.0.2/js/buttons.bootstrap5.min.js',
				// 'https://cdn.datatables.net/buttons/3.0.2/js/buttons.colVis.min.js',
				// 'https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js',
				// 'https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js',

				// // Datatables.net Responsive extension.
				// 'https://cdn.datatables.net/responsive/3.0.2/css/responsive.bootstrap5.min.css',
				// 'https://cdn.datatables.net/responsive/3.0.2/js/dataTables.responsive.min.js',
				// 'https://cdn.datatables.net/responsive/3.0.2/js/responsive.bootstrap5.min.js',

				// // Datatables.net SearchBuilder extension.
				// 'https://cdn.datatables.net/searchbuilder/1.7.1/css/searchBuilder.bootstrap5.min.css',
				// 'https://cdn.datatables.net/searchbuilder/1.7.1/js/dataTables.searchBuilder.min.js',
				// 'https://cdn.datatables.net/searchbuilder/1.7.1/js/searchBuilder.bootstrap5.min.js',

				// // Datatables.net SearchPanes extension.
				// 'https://cdn.datatables.net/searchpanes/2.3.1/css/searchPanes.bootstrap5.min.css',
				// 'https://cdn.datatables.net/searchpanes/2.3.1/js/dataTables.searchPanes.min.js',
				// 'https://cdn.datatables.net/searchpanes/2.3.1/js/searchPanes.bootstrap5.min.js',

				// // Datatables.net Select extension.
				// 'https://cdn.datatables.net/select/2.0.3/css/select.bootstrap5.min.css',
				// 'https://cdn.datatables.net/select/2.0.3/js/dataTables.select.min.js',
				// 'https://cdn.datatables.net/select/2.0.3/js/select.bootstrap5.min.js',

				// Font awesome
				'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css',

				// SortableJS
				'https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js',

				// Quill
				'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.min.js',
				'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.snow.min.css',

				// Choices.js
				'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js',
				'https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css',

				// Custom files.

				plugin_dir_url( __FILE__ ) . '../public/assets/js/app.js',
				plugin_dir_url( __FILE__ ) . '../public/assets/css/app.min.css',

				plugin_dir_url( __FILE__ ) . '../public/assets/js/decker-public.js',
				// plugin_dir_url( __FILE__ ) . 'css/decker-public.css',

			);

			if ( 'analytics' == $decker_page ) {
				// Chart.js.
				$resources[] = 'https://cdn.jsdelivr.net/npm/chart.js';
			}

			if ( 'board' == $decker_page ) {
				// dragula
				$resources[] = 'https://cdnjs.cloudflare.com/ajax/libs/dragula/3.7.3/dragula.min.js';
			}

			if ( 'tasks' == $decker_page ) { // Only load datatables.net on tasks page

				// Datatables JS CDN
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

					$last_handle = $handle; // Update last_handle to current script handle

				}
			}

			$current_user = wp_get_current_user();
			if ( ! ( $current_user instanceof WP_User ) ) {
				return; // No está autenticado ningún usuario.
			}

			// Agrega inline script para el usuario actual.
			wp_add_inline_script(
				'config', // The handle of the config.js file
				'const userId = ' . get_current_user_id() . ';',
				'before'
			);

			// Localize the script with new data.
			$script_data = array(
				'userId'       => get_current_user_id(),
				'display_name' => $current_user->display_name,
				'nickname'     => $current_user->nickname,

			);

			wp_localize_script( 'decker-public', 'deckerData', $script_data );

		}
	}
}
