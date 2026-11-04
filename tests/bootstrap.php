<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Starter_Plugin
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}
require 'vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/decker.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";


// Include the custom factory classes.
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-decker-board.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-decker-label.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-decker-task.php';

// Include the custom base test class.
require_once __DIR__ . '/includes/class-wp-unittest-decker-test-base.php';

// tests_add_filter( 'after_setup_theme', function() {

// // Register the custom factories with the global WordPress factory.
// $wp_factory = WP_UnitTestCase::factory();


// $wp_factory->board = new WP_UnitTest_Factory_For_Decker_Board( $wp_factory );
// $wp_factory->label = new WP_UnitTest_Factory_For_Decker_Label( $wp_factory );
// $wp_factory->task = new WP_UnitTest_Factory_For_Decker_Task( $wp_factory );

// if ( isset( $wp_factory ) && $wp_factory instanceof WP_UnitTest_Factory ) {
// $wp_factory->register( 'board', 'WP_UnitTest_Factory_For_Decker_Board' );
// $wp_factory->register( 'label', 'WP_UnitTest_Factory_For_Decker_Label' );
// $wp_factory->register( 'task', 'WP_UnitTest_Factory_For_Decker_Task' );
// } else {
// error_log( 'WP_UnitTest_Factory global is not available. Factories not registered.' );
// exit(1);
// }
// });
