<?php
/**
 *
 * Decker is a WordPress plugin focused on efficiently and structurally presenting a list of tasks.
 *
 * @link              https://github.com/ateeducacion/wp-decker
 * @package           Decker
 *
 * @wordpress-plugin
 * Plugin Name:       Decker
 * Plugin URI:        https://github.com/ateeducacion/wp-decker
 * Description:       Decker is a WordPress plugin focused on efficiently and structurally presenting a list of tasks. It is a simple task management plugin that utilizes a Kanban board and a unique priority system.
 * Version:           0.0.0
 * Author:            Área de Tecnología Educativa
 * Author URI:        https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       decker
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DECKER_VERSION', '0.0.0' );
define( 'DECKER_PLUGIN_FILE', __FILE__ );

/**
 * The code that runs during plugin activation.
 */
function activate_decker() {
	// Set the permalink structure if necessary.
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	// Grant capabilities for decker_journal CPT.
	$roles = array( 'editor', 'administrator' );
	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( 'edit_decker_journal' );
			$role->add_cap( 'read_decker_journal' );
			$role->add_cap( 'delete_decker_journal' );
			$role->add_cap( 'edit_decker_journals' );
			$role->add_cap( 'edit_others_decker_journals' );
			$role->add_cap( 'publish_decker_journals' );
			$role->add_cap( 'read_private_decker_journals' );
			$role->add_cap( 'delete_decker_journals' );
			$role->add_cap( 'delete_private_decker_journals' );
			$role->add_cap( 'delete_published_decker_journals' );
			$role->add_cap( 'delete_others_decker_journals' );
			$role->add_cap( 'edit_private_decker_journals' );
			$role->add_cap( 'edit_published_decker_journals' );
		}
	}

	flush_rewrite_rules();

	update_option( 'decker_flush_rewrites', true );
	update_option( 'decker_version', DECKER_VERSION );
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_decker() {
	flush_rewrite_rules();
}

/**
 * Plugin Update Handler
 *
 * @param WP_Upgrader $upgrader_object Upgrader object.
 * @param array       $options         Upgrade options.
 */
function decker_update_handler( $upgrader_object, $options ) {
	// Check if the update is for your specific plugin.
	if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		$plugins_updated = $options['plugins'];

		// Replace with your plugin's base name (typically folder/main-plugin-file.php).
		$plugin_file = plugin_basename( __FILE__ );

		// Check if your plugin is in the list of updated plugins.
		if ( in_array( $plugin_file, $plugins_updated ) ) {
			// Perform update-specific tasks.
			flush_rewrite_rules();
		}
	}
}

register_activation_hook( __FILE__, 'activate_decker' );
register_deactivation_hook( __FILE__, 'deactivate_decker' );
add_action( 'upgrader_process_complete', 'decker_update_handler', 10, 2 );


/**
 * Maybe flush rewrite rules on init if needed.
 */
function decker_maybe_flush_rewrite_rules() {
	$saved_version = get_option( 'decker_version' );

	// If plugin version changed, or a flag has been set (e.g. on activation), flush rules.
	if ( DECKER_VERSION !== $saved_version || get_option( 'decker_flush_rewrites' ) ) {
		flush_rewrite_rules();
		update_option( 'decker_version', DECKER_VERSION );
		delete_option( 'decker_flush_rewrites' );
	}
}
add_action( 'init', 'decker_maybe_flush_rewrite_rules', 999 );


/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-decker.php';


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-decker-wpcli.php';
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 */
function run_decker() {

	$plugin = new Decker();
	$plugin->run();
}
run_decker();
