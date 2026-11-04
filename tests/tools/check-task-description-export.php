<?php
/** Read-only corpus check; never bootstraps WordPress or imports the export. */
$plugin_root = dirname( __DIR__, 2 );
$core_root = isset( $argv[2] ) ? realpath( $argv[2] ) : false;
define( 'ABSPATH', $core_root ? $core_root . '/' : $plugin_root . '/' );
require $plugin_root . '/includes/class-decker-task-description-converter.php';
require dirname( __DIR__ ) . '/includes/class-decker-description-snapshot.php';

if ( empty( $argv[1] ) || ! is_readable( $argv[1] ) ) {
	file_put_contents( 'php://stderr', "Usage: php tests/tools/check-task-description-export.php /private/export.xml [/local/wordpress-core]\n" );
	exit( 1 );
}
$xml = simplexml_load_file( $argv[1], 'SimpleXMLElement', LIBXML_NONET );
if ( false === $xml ) {
	exit( 1 );
}
$namespaces = $xml->getDocNamespaces();
$report = array( 'total' => 0, 'changed' => 0, 'unchanged' => 0, 'verified_items' => 0, 'errors' => array() );
if ( isset( $argv[2] ) ) {
	if ( ! $core_root || ! is_file( $core_root . '/wp-includes/version.php' ) ) exit( 1 );
	define( 'WPINC', 'wp-includes' );
	// Definitions only: no wp-load.php, wp-config.php, plugins, database or hooks.
	foreach ( array( 'plugin.php', 'functions.php', 'formatting.php', 'http.php', 'kses.php', 'version.php' ) as $library ) require_once ABSPATH . WPINC . '/' . $library;
	require $plugin_root . '/includes/class-decker.php';
	require $plugin_root . '/includes/class-decker-task-description-migration.php';
	$policy_comparison = new ReflectionMethod( Decker_Task_Description_Migration::class, 'same_safe_html' );
	$report['policy_wordpress'] = $wp_version;
	$report['policy_php'] = PHP_VERSION;
	$report['policy_normalized'] = 0;
}
foreach ( $xml->channel->item as $item ) {
	$wp = $item->children( $namespaces['wp'] );
	if ( 'decker_task' !== (string) $wp->post_type ) {
		continue;
	}
	++$report['total'];
	$input = (string) $item->children( $namespaces['content'] )->encoded;
	try {
		$output = Decker_Task_Description_Converter::convert( $input );
		if ( $output !== Decker_Task_Description_Converter::convert( $output ) ) {
			throw new UnexpectedValueException( 'Idempotence failed.' );
		}
		$before = Decker_Description_Snapshot::items( $input );
		if ( $before !== Decker_Description_Snapshot::items( $output ) ) {
			throw new UnexpectedValueException( 'Individual item identity, hierarchy, inline content or state changed.' );
		}
		foreach ( array( 'text', 'code', 'resources' ) as $check ) {
			if ( Decker_Description_Snapshot::$check( $input ) !== Decker_Description_Snapshot::$check( $output ) ) {
				throw new UnexpectedValueException( 'Document preservation failed: ' . $check );
			}
		}
		if ( ! Decker_Description_Snapshot::preserves_inline_styles( $input, $output ) ) {
			throw new UnexpectedValueException( 'Existing inline CSS was lost.' );
		}
		if ( isset( $policy_comparison ) ) {
			$safe = wp_kses( $output, Decker::get_allowed_tags() );
			if ( ! $policy_comparison->invoke( null, $output, $safe ) ) throw new UnexpectedValueException( 'WordPress post policy would discard historical content; review required.' );
			if ( $safe !== $output ) ++$report['policy_normalized'];
		}
		$report['verified_items'] += count( $before );
		++$report[ $input === $output ? 'unchanged' : 'changed' ];
	} catch ( Throwable $error ) {
		$report['errors'][] = array( 'task_id' => (int) $wp->post_id, 'reason' => $error->getMessage() );
	}
}
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
exit( $report['errors'] ? 1 : 0 );
