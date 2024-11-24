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

		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-board-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-label-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/models/class-task-manager.php';

		require_once plugin_dir_path( __DIR__ ) . 'includes/controllers/class-task-controller.php';

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


function register_decker_role() {
	// Create the "Decker User" role if it doesn't exist.
	add_role(
		'decker_role',
		__( 'Decker User', 'decker' ),
		array(
			'read'                   => true,  // Enable reading capability.
			'edit_posts'             => true, // Disallow editing standard posts.
			'delete_posts'           => false, // Disallow deleting posts.
			'upload_files'           => true,  // Allow file and image uploads.
			'delete_attachments'     => true,  // Allow deleting attachments.
			'manage_decker_tasks'    => true,  // Allow editing 'decker_task' posts and terms.
			// 'publish_decker_tasks'   => true,  // Allow publishing 'decker_task' posts.
			// 'delete_decker_tasks'    => false,  // Disallow deleting 'decker_task' posts.
			// 'edit_others_decker_tasks' => true, // Allow editing others' 'decker_task' posts.
		)
	);
}
add_action( 'init', 'register_decker_role' );

// Ensure that custom capabilities for the 'decker_task' post type are added to the role.
function add_custom_caps_to_decker_role() {
	// Get the "Decker User" role.
	$role = get_role( 'decker_role' );

	if ( $role ) {
		// Add custom capabilities related to the 'decker_task' custom post type.
		$role->add_cap( 'upload_files' );
		$role->add_cap( 'delete_attachments' );
		$role->add_cap( 'manage_decker_tasks' );

		// Add capabilities for comments (only their own).
		$role->add_cap( 'read' ); // Allow reading in general if needed.
		$role->add_cap( 'edit_comments' ); // Allow editing comments in general.
		$role->add_cap( 'delete_comments' ); // Allow deleting comments in general.
		$role->add_cap( 'publish_comments' ); // Permite publicar comentarios.

	}
}
add_action( 'init', 'add_custom_caps_to_decker_role' );

function add_capabilities_to_admin() {
	// Get the administrator role.
	$admin_role = get_role( 'administrator' );

	if ( $admin_role ) {
		// Add the generic capability for managing 'decker_task'.
		$admin_role->add_cap( 'manage_decker_tasks' );
	}
}
add_action( 'init', 'add_capabilities_to_admin' );

// Filter to restrict editing and deleting of comments to the comment's author.
function restrict_comment_editing_to_author( $allcaps, $cap, $args ) {
	// Check if the current capability is 'edit_comment' or 'delete_comment'.
	if ( in_array( $cap[0], array( 'edit_comment', 'delete_comment' ) ) ) {
		// Get the comment object.
		$comment = get_comment( $args[2] );

		// Only allow editing/deleting if the current user is the author of the comment.
		if ( isset( $comment->user_id ) && $comment->user_id !== get_current_user_id() ) {
			$allcaps[ $cap[0] ] = false;
		}
	}

	return $allcaps;
}
add_filter( 'map_meta_cap', 'restrict_comment_editing_to_author', 10, 3 );


/**
 * Restrict editing/deleting comments to the 'decker_task' post type.
 */
function restrict_comment_capabilities_to_decker_task( $caps, $cap, $user_id, $args ) {
	// Verificar si la capacidad es para editar o borrar un comentario.
	if ( in_array( $cap, array( 'edit_comment', 'delete_comment' ) ) && ! empty( $args[0] ) ) {
		// Obtener el comentario.
		$comment = get_comment( $args[0] );

		if ( $comment ) {
			// Obtener el post asociado al comentario.
			$post = get_post( $comment->comment_post_ID );

			// Verificar si el post está asociado al tipo 'decker_task'.
			if ( $post && $post->post_type === 'decker_task' ) {
				// Permitir que los usuarios editen/borrar solo sus propios comentarios.
				if ( $comment->user_id != $user_id ) {
					$caps[] = 'do_not_allow';
				}
			} else {
				// Si el comentario no pertenece al tipo 'decker_task', denegar el permiso.
				$caps[] = 'do_not_allow';
			}
		}
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'restrict_comment_capabilities_to_decker_task', 10, 4 );
