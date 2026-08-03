<?php
/**
 * Loader for the bundled mail parser library.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the `Erseco\` classes shipped under admin/vendor/ without Composer.
 *
 * Nobody runs Composer when they install a WordPress plugin, so the mail parser
 * travels inside the package. Since version 1.0.5 that library is split across
 * several files — Message.php reaches for ParserOptions, ParserContext and
 * Rfc2047 — and none of them require each other. In the repository Composer's
 * autoloader hides that, which is why the test suite never noticed; the
 * packaged plugin has no autoloader at all and used to fatal on the first
 * message parsed:
 *
 *     Uncaught Error: Class "Erseco\ParserOptions" not found
 *
 * Registering this loader is what makes the bundled copy self-sufficient. It
 * stands down when Composer already declared the classes, which is the case in
 * a development checkout.
 */
class Decker_Bundled_Autoloader {

	/**
	 * Namespace prefix served by the bundled library.
	 *
	 * @var string
	 */
	const NAMESPACE_PREFIX = 'Erseco\\';

	/**
	 * Register the loader unless Composer already provided the library.
	 *
	 * @return void
	 */
	public static function register() {
		if ( class_exists( self::NAMESPACE_PREFIX . 'Message', false ) ) {
			return;
		}

		if ( ! is_dir( self::base_dir() ) ) {
			return;
		}

		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load one class from the bundled library.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );
		$path     = self::base_dir() . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Directory holding the bundled library sources.
	 *
	 * @return string Absolute path with a trailing slash.
	 */
	private static function base_dir() {
		return plugin_dir_path( __DIR__ ) . 'admin/vendor/mime-mail-parser/src/';
	}
}
