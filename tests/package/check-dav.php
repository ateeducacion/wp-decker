<?php
/** Run with wp --skip-plugins=decker eval-file ... /path/to/extracted/decker. */
if ( empty( $args[0] ) ) { throw new RuntimeException( 'Provide the extracted plugin directory.' ); }
require $args[0] . '/decker.php';
( new Decker_Events() )->register_post_type();
wp_set_current_user( 1 );
Decker_Dav::server();
$reflection = new ReflectionClass( Sabre\DAV\Server::class );
if ( ! str_starts_with( $reflection->getFileName(), $args[0] . '/admin/vendor/dav/' ) ) {
	throw new RuntimeException( 'DAV was loaded outside the installable package.' );
}
$calendar = new Sabre\VObject\Component\VCalendar();
$calendar->add( 'VEVENT', array( 'SUMMARY' => 'Disposable package smoke test', 'DTSTART' => new DateTimeImmutable( '2026-11-04T10:00:00Z' ) ) );
$id = 0;
try {
	$backend = new Decker_Dav_Backend();
	$uri = wp_generate_uuid4() . '.ics';
	$etag = $backend->createCalendarObject( get_current_blog_id(), $uri, $calendar->serialize() );
	$object = $backend->getCalendarObject( get_current_blog_id(), $uri );
	$id = $object['id'];
	if ( $etag !== $object['etag'] || 'Disposable package smoke test' !== get_the_title( $id ) ) {
		throw new RuntimeException( 'Packaged event persistence failed.' );
	}
	$backend->deleteCalendarObject( get_current_blog_id(), $uri );
	if ( 'trash' !== get_post_status( $id ) ) { throw new RuntimeException( 'Packaged deletion failed.' ); }
	WP_CLI::success( 'Extracted ZIP loads its own dependencies and supports calendar CRUD.' );
} finally {
	if ( $id ) { wp_delete_post( $id, true ); }
}
