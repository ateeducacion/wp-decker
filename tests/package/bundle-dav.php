<?php
/** Build the production-only DAV dependency bundle. */
if ( PHP_SAPI !== 'cli' ) { exit; }
$root = dirname( __DIR__, 2 );
$target = $root . '/admin/vendor/dav';
if ( ! is_dir( $target ) ) { mkdir( $target, 0755, true ); }
copy( $root . '/composer.json', $target . '/composer.json' );
copy( $root . '/composer.lock', $target . '/composer.lock' );
passthru( 'composer install --working-dir=' . escapeshellarg( $target ) . ' --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction', $code );
exit( $code );
