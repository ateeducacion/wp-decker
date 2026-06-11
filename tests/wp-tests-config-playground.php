<?php
/**
 * WordPress test config used when running the suite under WordPress Playground.
 *
 * The database constants are placeholders: Playground serves WordPress on SQLite
 * via a preloaded $wpdb, so DB_HOST/DB_NAME/etc. are never used to open a MySQL
 * connection. ABSPATH points at the WordPress install Playground boots in-process.
 *
 * @package Decker
 */

define( 'ABSPATH', '/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Decker Tests' );
define( 'WP_PHP_BINARY', 'php' );

// Reuse the prefix Playground installs WordPress with.
$table_prefix = 'wp_';

// Unused under SQLite, but required to be defined by WordPress.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
