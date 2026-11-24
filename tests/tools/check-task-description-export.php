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
	// No wp-load.php, wp-config.php, site hooks or database connection.
	foreach ( array( 'compat-utf8.php', 'compat.php', 'utf8.php' ) as $library ) {
		if ( is_file( ABSPATH . WPINC . '/' . $library ) ) require_once ABSPATH . WPINC . '/' . $library;
	}
	foreach ( array( 'plugin.php', 'functions.php', 'formatting.php', 'http.php', 'kses.php', 'version.php' ) as $library ) require_once ABSPATH . WPINC . '/' . $library;
	// The export is UTF-8; recent KSES calls esc_url(), which reads this option.
	add_filter( 'pre_option_blog_charset', static fn () => 'UTF-8' );
	// Recent KSES versions use the HTML API; load definitions only when available.
	if ( is_file( ABSPATH . WPINC . '/class-wp-token-map.php' ) ) require_once ABSPATH . WPINC . '/class-wp-token-map.php';
	foreach ( array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $library ) {
		$path = ABSPATH . WPINC . '/html-api/' . $library;
		if ( is_file( $path ) ) require_once $path;
	}
	require $plugin_root . '/includes/class-decker.php';
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
		if ( $core_root ) {
			$safe = wp_kses( $output, Decker::get_allowed_tags() );
			if ( ! Decker_Task_Description_Html::same_safe_html( $output, $safe ) ) throw new UnexpectedValueException( 'WordPress post policy would discard historical content; review required.' );
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
