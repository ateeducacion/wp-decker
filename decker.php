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
 * This action is documented in includes/class-decker-activator.php
 */
function activate_decker() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-decker-activator.php';
	Decker_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-decker-deactivator.php
 */
function deactivate_decker() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-decker-deactivator.php';
	Decker_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_decker' );
register_deactivation_hook( __FILE__, 'deactivate_decker' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-decker.php';

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
