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


// Verificar si existe la biblioteca PHPUnit Polyfills.
// if ( ! class_exists( '\Yoast\PHPUnitPolyfills\Autoload' ) ) {
//     // Intentar cargarlo desde Composer.
//     if ( file_exists( dirname( __DIR__ ) . '/../../../vendor/yoast/phpunit-polyfills/autoload.php' ) ) {
//         require_once dirname( __DIR__ ) . '/../../../vendor/yoast/phpunit-polyfills/autoload.php';
//     } elseif ( defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
//         require_once WP_TESTS_PHPUNIT_POLYFILLS_PATH . '/autoload.php';
//     } else {
//         echo "Error: PHPUnit Polyfills no está disponible. Verifica la instalación.\n";
//         exit( 1 );
//     }
// }

// // Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
// $_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
// if ( false !== $_phpunit_polyfills_path ) {
// 	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
// }

// require '/home/enesto/.composer/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';


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
