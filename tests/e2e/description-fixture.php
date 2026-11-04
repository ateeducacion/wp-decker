<?php
/** Test-only CLI fixture loader; never import the private WXR into WordPress. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'DECKER_DESCRIPTION_E2E' ) ) {
	exit( 1 );
}
$file = $args[0] ?? '';
if ( ! preg_match( '/^(?:task-[0-9]+|empty-(?:checklist|list|paragraphs))\.html$/', $file ) ) {
	WP_CLI::error( 'Unknown anonymous fixture.' );
}
$html = file_get_contents( dirname( __DIR__ ) . '/fixtures/task-descriptions/' . $file );
if ( false === $html ) {
	WP_CLI::error( 'Anonymous fixture not found.' );
}
update_option( Decker_Task_Description::VERSION_OPTION, 1, false );
$board = wp_insert_term( 'Description fixture ' . wp_generate_uuid4(), 'decker_board' );
if ( is_wp_error( $board ) ) { WP_CLI::error( $board ); }
$id = wp_insert_post( array( 'post_type' => 'decker_task', 'post_title' => 'Anonymous description fixture', 'post_status' => 'publish', 'post_author' => get_current_user_id(), 'meta_input' => array( 'stack' => 'to-do', 'responsable' => get_current_user_id(), 'duedate' => '2030-06-15' ), 'tax_input' => array( 'decker_board' => array( (int) $board['term_id'] ) ) ), true );
if ( is_wp_error( $id ) ) { WP_CLI::error( $id ); }
global $wpdb;
$wpdb->update( $wpdb->posts, array( 'post_content' => $html ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
clean_post_cache( $id );
if ( $html !== get_post( $id )->post_content ) { WP_CLI::error( 'Historical fixture bytes changed before migration.' ); }
WP_CLI::line( 'DECKER_RESULT:' . wp_json_encode( array( 'id' => $id, 'board_id' => (int) $board['term_id'], 'characters' => mb_strlen( $html ), 'sha256' => hash( 'sha256', $html ) ) ) );
