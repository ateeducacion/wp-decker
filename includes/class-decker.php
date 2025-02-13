<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Decker {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @access   protected
	 * @var      Decker_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;


	/**
	 * The unique identifier of this plugin.
	 *
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct() {
		$this->version = DECKER_VERSION;
		$this->plugin_name = 'decker';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

		add_action( 'init', array( $this, 'maybe_flush_permalinks' ), 999 );

		// Hook demo data creation to init.
		add_action( 'init', array( $this, 'maybe_create_demo_data' ) );
	}

	/**
	 * Checks if flush_rewrite_rules() should be executed.
	 */
	public function maybe_flush_permalinks() {
		// Only flush if the option is set.
		if ( get_option( 'decker_flush_rewrites' ) ) {
			flush_rewrite_rules();
			delete_option( 'decker_flush_rewrites' );
		}
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Decker_Loader. Orchestrates the hooks of the plugin.
	 * - Decker_i18n. Defines internationalization functionality.
	 * - Decker_Admin. Defines all hooks for the admin area.
	 * - Decker_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-i18n.php';

		/**
		 * The classes responsible for defining the custom-post-types.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-user-extended.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-actions.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-boards.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-labels.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-events.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-kb.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-email-to-post.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-mailer.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-notification-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-calendar.php';

		/**
		 * The class responsible for defining the MVC.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-board.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-label.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-task.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-boardmanager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-labelmanager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-taskmanager.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-decker-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'public/class-decker-public.php';

		$this->loader = new Decker_Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Decker_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Decker_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Decker_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 10, 1 );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Decker_Public( $this->get_plugin_name(), $this->get_version() );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		// Initialize notification handler.
		new Decker_Notification_Handler();

		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}


	/**
	 * Check if the current user has at least the required role.
	 *
	 * @return bool True if the user has the required role or higher, false otherwise.
	 */
	public static function current_user_has_at_least_minimum_role() {
		// Get the saved user profile role from plugin options, default to 'editor'.
		$options = get_option( 'decker_settings', array() );
		$required_role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// WordPress role hierarchy, ordered from lowest to highest.
		$role_hierarchy = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		// Determine the index of the required role.
		$required_index = array_search( $required_role, $role_hierarchy );

		if ( false === $required_index ) {
			// Invalid role in settings, fallback to the default.
			return false;
		}

		// Check each role of the current user.
		foreach ( wp_get_current_user()->roles as $user_role ) {
			$user_index = array_search( $user_role, $role_hierarchy );

			if ( false !== $user_index && $user_index >= $required_index ) {
				return true; // User has the required role or higher.
			}
		}

		return false; // User does not meet the minimum role requirement.
	}

	/**
	 * Retur the allowed only simple and safe HTML elements.
	 *
	 * This function allows basic formatting tags such as paragraphs, bold, italics,
	 * lists, tables, links, and spans with specific classes and data attributes.
	 * It excludes potentially dangerous elements like images, embeds, iframes, and scripts.
	 *
	 * @return array The allowrd tags array.
	 */
	public static function get_allowed_tags() {

		// Define the allowed CSS tags.
		$allowed_css_properties = array(
			'background-color',
			'color',
			'font-size',
			'font-family',
			'font-weight',
			'text-decoration',
		);

		// Define the allowed HTML tags and their permitted attributes.
		$allowed_tags = array(
			'p' => array(
				'class' => array(),
				'style' => $allowed_css_properties,
			),
			'br' => array(),
			'strong' => array(),
			'b' => array(),
			'em' => array(),
			's' => array(),
			'u' => array(),
			'ul' => array(),
			'ol' => array(),
			'li' => array(
				'data-list' => array(),
			),
			'table' => array(
				'style' => $allowed_css_properties,
				'border' => array(),
				'cellpadding' => array(),
				'cellspacing' => array(),
			),
			'thead' => array(),
			'tbody' => array(),
			'tr' => array(),
			'th' => array(
				'style' => $allowed_css_properties,
				'colspan' => array(),
				'rowspan' => array(),
			),
			'td' => array(
				'style' => $allowed_css_properties,
				'colspan' => array(),
				'rowspan' => array(),
			),
			'a' => array(
				'href' => array(),
				'title' => array(),
				'target' => array(),
				'rel' => array(),
			),
			'span' => array(
				'class' => array(),
				'style' => $allowed_css_properties,
			),
			'blockquote' => array(),
			'code' => array(),
			'pre' => array(),
			'img' => array(
				'src' => array(),
				'alt' => array(),
				'style' => $allowed_css_properties,
				'width' => array(),
				'height' => array(),
			),
			// Add more basic tags if needed.
		);

		// Return the defined allowed tags.
		return $allowed_tags;
	}

	/**
	 * Create demo data if the version is 0.0.0
	 */
	public function maybe_create_demo_data() {

		// Check if we are in wp-env-test environment (PHPUNIT).
		if ( defined( 'WP_TESTS_DOMAIN' ) && WP_TESTS_DOMAIN === 'localhost:8889' ) {
			// If we're in test environment, skip demo data creation.
			return;
		}

		// If we're in development version and there are no tasks, create sample data.
		if ( defined( 'DECKER_VERSION' ) && DECKER_VERSION === '0.0.0' ) {
			$args = array(
				'post_type' => 'decker_task',
				'posts_per_page' => 1,
				'post_status' => 'any',
			);

			$query = new WP_Query( $args );

			if ( ! $query->have_posts() ) {
				require_once plugin_dir_path( __FILE__ ) . 'class-decker-demo-data.php';
				$demo_data = new Decker_Demo_Data();
				$demo_data->create_sample_data();
			}
		}
	}
}
