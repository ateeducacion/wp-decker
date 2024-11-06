#!/bin/ash
set -ex

# Variables de entorno
DB_NAME=${DB_NAME:-wordpress}
DB_USER=${DB_USER:-wordpress}
DB_PASS=${DB_PASS:-wordpress}
DB_HOST=${DB_HOST:-mariadb}

# Configurar las pruebas
if [ ! -d "wordpress-tests-lib" ]; then
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ wordpress-tests-lib/includes
  svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ wordpress-tests-lib/data
fi

# Crear wp-config.php para pruebas
cat > wordpress-tests-lib/wp-tests-config.php <<EOL
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.com' );
define( 'WP_TESTS_EMAIL', 'admin@example.com' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
\$table_prefix  = 'wptests_';
define( 'WP_TESTS_TABLE_PREFIX', \$table_prefix );
EOL
