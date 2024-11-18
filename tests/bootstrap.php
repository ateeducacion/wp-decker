<?php
/**
 * PHPUnit bootstrap file
 *
 * @package Decker
 */

// First we need to load the composer autoloader, so we can use WP Mock
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use Yoast\WPTestUtils\WPIntegration;


// Bootstrap WP_Mock to initialize built-in features
WP_Mock::setUsePatchwork( true );
WP_Mock::bootstrap();


// Simular funciones de WordPress necesarias para el entorno de pruebas
WP_Mock::userFunction(
	'get_option',
	array(
		'return' => 'default_value',
	)
);

WP_Mock::userFunction(
	'update_option',
	array(
		'return' => true,
	)
);

WP_Mock::userFunction(
	'add_option',
	array(
		'return' => true,
	)
);

WP_Mock::userFunction(
	'plugin_dir_path',
	array(
		'return' => function ( $file ) {
			return dirname( $file ) . '/';
		},
	)
);

WP_Mock::userFunction(
	'register_activation_hook',
	array(
		'return' => true,
	)
);

WP_Mock::userFunction(
	'register_deactivation_hook',
	array(
		'return' => true,
	)
);

WP_Mock::userFunction(
	'has_action',
	array(
		'return' => true,
	)
);


// If your project does not use autoloading via Composer, include your files now

// require_once dirname( __DIR__ ) . '/decker.php';

// require_once dirname(__DIR__) . '/decker.php';



/**
 * Manualmente cargar el plugin.
 */
// function _manually_load_plugin() {
// require dirname( __DIR__ ) . '/decker.php';
// }


// Utilizar 'tests_add_filter' para cargar manualmente el plugin.
// tests_add_filter('muplugins_loaded', '_manually_load_plugin');


require_once dirname(__DIR__) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

WPIntegration\bootstrap_it();