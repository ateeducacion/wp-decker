<?php
/** Build the production-only DAV dependency bundle. */
if ( PHP_SAPI !== 'cli' ) { exit; }
$root = dirname( __DIR__, 2 );
$target = $root . '/admin/vendor/dav';
if ( ! is_dir( $target ) ) { mkdir( $target, 0755, true ); }
$config = json_decode( file_get_contents( $root . '/composer.json' ), true, 512, JSON_THROW_ON_ERROR );
// The bundle shares the lock file but must not share the root loader's class name.
$config['config']['autoloader-suffix'] = 'DeckerDav';
file_put_contents( $target . '/composer.json', json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
copy( $root . '/composer.lock', $target . '/composer.lock' );
passthru( 'composer install --working-dir=' . escapeshellarg( $target ) . ' --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction', $code );
exit( $code );
