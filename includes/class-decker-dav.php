<?php
/**
 * Site-local CalDAV endpoint lifecycle.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/** Runs before interactive template redirects; does not bypass external CAS. */
class Decker_Dav {
	/** Register endpoint and one-time date migration. */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'migrate' ), 20 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'lock_post' ), 1 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'lock_meta' ), 1, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'lock_delete' ), 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'lock_delete' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'dispatch' ), -100 );
	}
	/** Request-wide site lock serializes precondition checks with web writes.
	 *
	 * @throws Sabre\DAV\Exception\ServiceUnavailable Lock unavailable.
	 */
	public static function lock() {
		global $wpdb;
		static $held = array();
		$key = 'decker-calendar-' . md5( DB_NAME . $wpdb->prefix );
		if ( isset( $held[ $key ] ) ) {
			return; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL advisory locks have no WordPress API.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $key ) ) ) {
			throw new Sabre\DAV\Exception\ServiceUnavailable( 'Calendar is busy.' ); }
		$held[ $key ] = true;
		register_shutdown_function(
			static function () use ( $wpdb, $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the connection-owned advisory lock.
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			}
		);
	}
	/** Lock calendar post writes before WordPress changes the row.
	 *
	 * @param array $data Post data.
	 * @return array
	 */
	public static function lock_post( $data ) {
		if ( 'decker_event' === $data['post_type'] ) {
			self::lock(); }
		return $data;
	}
	/** Lock direct metadata patches too.
	 *
	 * @param mixed  $check Filter result.
	 * @param int    $id Post ID.
	 * @param string $key Meta key.
	 * @return mixed
	 */
	public static function lock_meta( $check, $id, $key ) {
		if ( 'decker_event' === get_post_type( $id ) ) {
			self::lock(); }
		return $check;
	}
	/** Lock removals before the collection changes.
	 *
	 * @param int $id Post ID.
	 */
	public static function lock_delete( $id ) {
		if ( 'decker_event' === get_post_type( $id ) ) {
			self::lock(); }
	}

	/** Check membership, Decker role and the existing event capability.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return is_user_logged_in() && current_user_can( 'edit_posts' ) && Decker::current_user_has_at_least_minimum_role() && ( ! is_multisite() || is_user_member_of_blog( get_current_user_id() ) );
	}
	/** URL includes the actual site subdirectory.
	 *
	 * @return string
	 */
	public static function url() {
		return home_url( '/decker-dav/' ); }
	/** DAVx⁵ setup link with server and login prefilled; never the token.
	 *
	 * @param string $login WordPress username.
	 * @return string
	 */
	public static function davx5_url( $login ) {
		$parts = wp_parse_url( self::url() );
		$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return 'davx5://' . rawurlencode( $login ) . '@' . $host . $parts['path'];
	}
	/** Unsigned Apple configuration profile for one CalDAV account.
	 *
	 * The password is omitted on purpose: iOS asks for the token on install.
	 *
	 * @param WP_User $user Account owner.
	 * @return string Property list XML.
	 */
	public static function apple_profile( $user ) {
		$parts = wp_parse_url( self::url() );
		$seed = md5( self::url() . '|' . $user->ID );
		$uuid = static function ( $salt ) use ( $seed ) {
			$hex = md5( $seed . $salt );
			return strtoupper( substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 ) );
		};
		$id = implode( '.', array_reverse( explode( '.', $parts['host'] ) ) ) . '.decker.caldav.' . $user->ID;
		$name = esc_xml( __( 'Decker calendar', 'decker' ) );
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
			. '<plist version="1.0"><dict><key>PayloadContent</key><array><dict>'
			. '<key>CalDAVAccountDescription</key><string>' . $name . '</string>'
			. '<key>CalDAVHostName</key><string>' . esc_xml( $parts['host'] ) . '</string>'
			. '<key>CalDAVPort</key><integer>' . ( isset( $parts['port'] ) ? (int) $parts['port'] : 443 ) . '</integer>'
			. '<key>CalDAVPrincipalURL</key><string>' . esc_xml( self::url() . 'principals/' . rawurlencode( $user->user_login ) . '/' ) . '</string>'
			. '<key>CalDAVUseSSL</key><true/>'
			. '<key>CalDAVUsername</key><string>' . esc_xml( $user->user_login ) . '</string>'
			. '<key>PayloadDisplayName</key><string>' . $name . '</string>'
			. '<key>PayloadIdentifier</key><string>' . esc_xml( $id ) . '.account</string>'
			. '<key>PayloadType</key><string>com.apple.caldav.account</string>'
			. '<key>PayloadUUID</key><string>' . $uuid( 'account' ) . '</string>'
			. '<key>PayloadVersion</key><integer>1</integer>'
			. '</dict></array>'
			. '<key>PayloadDisplayName</key><string>' . $name . '</string>'
			. '<key>PayloadIdentifier</key><string>' . esc_xml( $id ) . '</string>'
			. '<key>PayloadRemovalDisallowed</key><false/>'
			. '<key>PayloadType</key><string>Configuration</string>'
			. '<key>PayloadUUID</key><string>' . $uuid( 'profile' ) . '</string>'
			. '<key>PayloadVersion</key><integer>1</integer>'
			. '</dict></plist>' . "\n";
	}
	/** Migrate inclusive all-day ends exactly once per post and site. */
	public static function migrate() {
		if ( get_option( 'decker_calendar_dates_v2' ) ) {
			return; }
		self::lock();
		if ( get_option( 'decker_calendar_dates_v2' ) ) {
			return; }
		foreach ( get_posts(
			array(
				'post_type' => 'decker_event',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		) as $post ) {
			if ( get_post_meta( $post->ID, '_decker_dates_v2', true ) ) {
				continue; }
			if ( get_post_meta( $post->ID, 'event_allday', true ) && ! get_post_meta( $post->ID, '_decker_ical', true ) ) {
				$end = get_post_meta( $post->ID, 'event_end', true );
				$date = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $end, 0, 10 ), new DateTimeZone( 'UTC' ) );
				if ( $date && $date->format( 'Y-m-d' ) === substr( $end, 0, 10 ) ) {
					update_post_meta( $post->ID, 'event_end', $date->modify( '+1 day' )->format( 'Y-m-d' ) );
				}
			}
			update_post_meta( $post->ID, '_decker_dates_v2', 1 );
		}
		update_option( 'decker_calendar_dates_v2', 1, false );
	}
	/** Stop with 503 naming the PHP extensions sabre/dav cannot run without.
	 *
	 * Sabre cannot even serialize its own errors without them, so this runs first.
	 *
	 * @param array $required Extension names.
	 */
	public static function require_extensions( $required = array( 'xmlwriter', 'xmlreader', 'dom', 'mbstring' ) ) {
		$missing = array_diff( $required, array_filter( $required, 'extension_loaded' ) );
		if ( $missing ) {
			wp_die( esc_html( 'CalDAV is unavailable: this PHP installation is missing the ' . implode( ', ', $missing ) . ' extension(s).' ), '', array( 'response' => 503 ) );
		}
	}
	/** Build a server without scheduling, sync-token or browser plugins.
	 *
	 * @return Sabre\DAV\Server
	 */
	public static function server() {
		foreach ( array( 'auth', 'principals', 'backend' ) as $class ) {
			require_once __DIR__ . '/class-decker-dav-' . $class . '.php';
		}
		$principals = new Decker_Dav_Principals();
		$server = new Sabre\DAV\Server( array( new Sabre\DAVACL\PrincipalCollection( $principals ), new Sabre\CalDAV\CalendarRoot( $principals, new Decker_Dav_Backend() ) ) );
		$server->setBaseUri( wp_parse_url( self::url(), PHP_URL_PATH ) );
		$server->addPlugin( new Sabre\DAV\Auth\Plugin( new Decker_Dav_Auth(), 'Decker calendar' ) );
		$server->addPlugin( new Sabre\DAVACL\Plugin() );
		$server->addPlugin( new Sabre\CalDAV\Plugin() );
		return $server;
	}
	/** Serve only the exact DAV path boundary, ignoring WordPress cookies.
	 *
	 * @param WP $wp Request.
	 */
	public static function dispatch( $wp ) {
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		$base = wp_parse_url( self::url(), PHP_URL_PATH );
		if ( rtrim( $base, '/' ) !== $path && 0 !== strpos( $path, $base ) ) {
			return; }
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		if ( ! is_ssl() ) {
			status_header( 403 );
			exit;
		}
		self::require_extensions();
		wp_set_current_user( 0 );
		$server = self::server();
		$server->on( 'beforeMethod:*', array( __CLASS__, 'lock' ), 20 );
		$server->exec();
		exit;
	}
}
