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
		$this->register_hooks();
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
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-user-extended.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-actions.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-boards.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-labels.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-decker-email-to-post.php';

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
	 * Register all hooks related to roles, capabilities, and restrictions.
	 */
	private function register_hooks() {
		$this->loader->add_action( 'init', $this, 'register_role' );
		$this->loader->add_action( 'init', $this, 'add_custom_caps_to_role' );
		$this->loader->add_action( 'init', $this, 'add_caps_to_admin' );

		$this->loader->add_filter( 'map_meta_cap', $this, 'restrict_comment_editing_to_author', 10, 3 );
		$this->loader->add_filter( 'map_meta_cap', $this, 'restrict_comment_capabilities_to_decker_task', 10, 4 );
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
	 * Create the "Decker User" role if it doesn't exist.
	 */
	public function register_role() {
		add_role(
			'decker_role',
			__( 'Decker User', 'decker' ),
			array(
				'read'                => true,
				'edit_posts'          => true,
				'delete_posts'        => false,
				'upload_files'        => true,
				'delete_attachments'  => true,
				'manage_decker_tasks' => true,
			)
		);
	}

	/**
	 * Add custom capabilities to the "Decker User" role.
	 */
	public function add_custom_caps_to_role() {
		$role = get_role( 'decker_role' );

		if ( $role ) {
			$role->add_cap( 'upload_files' );
			$role->add_cap( 'delete_attachments' );
			$role->add_cap( 'manage_decker_tasks' );
			$role->add_cap( 'edit_comments' );
			$role->add_cap( 'delete_comments' );
		}
	}

	/**
	 * Add custom capabilities to the administrator role.
	 */
	public function add_caps_to_admin() {
		$admin_role = get_role( 'administrator' );

		if ( $admin_role ) {
			$admin_role->add_cap( 'manage_decker_tasks' );
		}
	}

	/**
	 * Restrict comment editing/deleting to the comment's author.
	 *
	 * @param array $allcaps Capabilities for the current user.
	 * @param array $cap     Requested capability.
	 * @param array $args    Additional arguments.
	 * @return array Updated capabilities.
	 */
	public function restrict_comment_editing_to_author( $allcaps, $cap, $args ) {
		if ( in_array( $cap[0], array( 'edit_comment', 'delete_comment' ), true ) ) {
			$comment = get_comment( $args[2] );

			if ( isset( $comment->user_id ) && get_current_user_id() !== $comment->user_id ) {
				$allcaps[ $cap[0] ] = false;
			}
		}

		return $allcaps;
	}

	/**
	 * Restrict comment editing/deleting to the 'decker_task' post type.
	 *
	 * @param array  $caps    User's actual capabilities.
	 * @param string $cap     Capability name.
	 * @param int    $user_id User ID.
	 * @param array  $args    Additional arguments.
	 * @return array Updated capabilities.
	 */
	public function restrict_comment_capabilities_to_decker_task( $caps, $cap, $user_id, $args ) {
		if ( in_array( $cap, array( 'edit_comment', 'delete_comment' ), true ) && ! empty( $args[0] ) ) {
			$comment = get_comment( $args[0] );

			if ( $comment ) {
				$post = get_post( $comment->comment_post_ID );

				if ( $post && 'decker_task' === $post->post_type && $comment->user_id !== $user_id ) {
					$caps[] = 'do_not_allow';
				}
			}
		}

		return $caps;
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
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
