<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * @package    Decker
 * @subpackage Decker/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Decker
 * @subpackage Decker/admin
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Decker_Admin {

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
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		$this->load_dependencies();
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( DECKER_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add settings link to the plugins page.
	 *
	 * @param array $links The existing links.
	 * @return array The modified links.
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=decker_settings' ) . '">' . __( 'Settings', 'decker' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add a link to the admin bar.
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar object.
	 */
	public function add_admin_bar_link( $admin_bar ) {

		// Check if the current user has at least the required role.
		if ( Decker::current_user_has_at_least_minimum_role() ) {

			$admin_bar->add_menu(
				array(
					'id'    => 'decker_frontend_link',
					'title' => '<span class="ab-icon dashicons-welcome-widgets-menus"></span> ' . __( 'Go to Decker', 'decker' ),
					'href'  => home_url( '/?decker_page=priority' ),
					'meta'  => array(
						'title' => __( 'Go to Decker', 'decker' ),
						'class' => 'dashicons-before dashicons-welcome-widgets-menus',
					),
				)
			);
		}
	}

	/**
	 * Load the required dependencies for this class.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-decker-admin-settings.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-decker-admin-export.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-decker-admin-import.php';

		if ( ! has_action( 'admin_menu', array( 'Decker_Admin_Settings', 'create_menu' ) ) ) {
			new Decker_Admin_Settings();
		}
		new Decker_Admin_Export();
		new Decker_Admin_Import();
	}


	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'settings_page_decker_settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/decker-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_decker_settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/decker-admin.js', array( 'jquery' ), $this->version, false );
	}
}
