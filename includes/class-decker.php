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
		if ( defined( 'DECKER_VERSION' ) ) {
			$this->version = DECKER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'decker';

		$this->load_dependencies();
		$this->define_settings_constants();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
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
		 * The class responsible for utility functions.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-utility-functions.php';

		/**
		 * The classes responsible for defining the custom-post-types.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-actions.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-boards.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-labels.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-user-extended.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-email-to-post.php';

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
	 * Define plugin settings constants.
	 */
	private function define_settings_constants() {
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
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    Decker_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}

/**
 * A temporary function to log messages using the Decker_Utility_Functions class.
 *
 * This function serves as a wrapper to maintain compatibility with existing code
 * that uses the `write_log` function. It forwards the log message to the
 * `Decker_Utility_Functions::write_log` method with a default log level of `LOG_LEVEL_ERROR`.
 *
 * @param string|array $message The message or data to log. If an array or object is passed,
 *                              it will be converted to a string using `print_r`.
 * @return void
 */
function write_log( $message ) {
	Decker_Utility_Functions::write_log( $message, Decker_Utility_Functions::LOG_LEVEL_ERROR );
}

function register_decker_role() {
	add_role(
		'decker_role',
		__( 'Decker User' ),
		array(
			'read'         => true,  // Habilitar la capacidad de leer.
			'edit_posts'   => false, // No permitir la edición de posts.
			'delete_posts' => false, // No permitir la eliminación de posts.
		)
	);
}
add_action( 'init', 'register_decker_role' );
