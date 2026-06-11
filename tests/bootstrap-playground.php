<?php
/**
 * PHPUnit bootstrap for running Decker's integration suite under WordPress
 * Playground (WebAssembly, SQLite) with no Docker.
 *
 * Why this exists: the WordPress test library installs WP by spawning a child
 * process (`system( WP_PHP_BINARY . ' install.php' )`). That nested process does
 * not work inside Playground's WASM model (mounted files aren't visible to the
 * child, and opcache's file-cache flock deadlocks). Setting WP_TESTS_SKIP_INSTALL
 * makes the WP test bootstrap reuse the WordPress instance Playground already
 * booted in-process on SQLite, so no subprocess is needed.
 *
 * Run with:  make test-playground
 *
 * @package Decker
 */

// Reuse Playground's already-installed in-process WordPress; skip system(install.php).
putenv( 'WP_TESTS_SKIP_INSTALL=1' );
putenv( 'WP_TESTS_DIR=' . dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/' );
putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config-playground.php' );

// Force the minimal test theme so block themes (e.g. Twenty Twenty-Five) don't
// re-register block bindings on every init and trip WP_UnitTestCase's guard.
require_once dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/functions.php';
tests_add_filter( 'pre_option_stylesheet', static function () { return 'default'; } );
tests_add_filter( 'pre_option_template', static function () { return 'default'; } );

// Decker's own bootstrap uses a relative require for the polyfills autoloader.
chdir( dirname( __DIR__ ) );

// Hand off to the plugin's regular bootstrap (unchanged) — it now skips the
// install step and loads WordPress in-process.
require __DIR__ . '/bootstrap.php';
