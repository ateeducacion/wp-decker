<?php
/**
 * Class DeckerBundledMailParserTest
 *
 * Proves the mail parser copy under admin/vendor/ works on its own, the way it
 * has to in an installed plugin.
 *
 * This cannot be asserted from inside the suite: PHPUnit boots with Composer's
 * autoloader, which resolves `Erseco\*` from the development vendor/ directory
 * and hides any file the bundled copy fails to load. Every check here therefore
 * runs in a separate PHP process with no autoloader at all, which is exactly
 * the situation of a WordPress site that installed the ZIP.
 *
 * @package Decker
 */

class DeckerBundledMailParserTest extends Decker_Test_Base {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @var string
	 */
	private $plugin_dir;

	public function setUp(): void {
		parent::setUp();
		$this->plugin_dir = dirname( __DIR__, 3 ) . '/';
	}

	/**
	 * Run PHP code in a clean process, with only the plugin on disk.
	 *
	 * @param string $code PHP to execute.
	 * @return array{0:int,1:string} Exit code and combined output.
	 */
	private function run_isolated( string $code ): array {
		$script = tempnam( sys_get_temp_dir(), 'decker-bundled-' ) . '.php';
		file_put_contents( $script, "<?php\n" . $code );

		$output   = array();
		$exit_code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=E_ALL ' . escapeshellarg( $script ) . ' 2>&1', $output, $exit_code );

		unlink( $script );

		return array( $exit_code, implode( "\n", $output ) );
	}

	/**
	 * Preamble that stands in for the sliver of WordPress the loader touches.
	 *
	 * @return string PHP code.
	 */
	private function bootstrap_code(): string {
		return sprintf(
			'if ( ! defined( "ABSPATH" ) ) { define( "ABSPATH", "/tmp/" ); }
			function plugin_dir_path( $f ) { return rtrim( dirname( $f ), "/" ) . "/"; }
			require %s . "includes/class-decker-bundled-autoloader.php";
			Decker_Bundled_Autoloader::register();',
			var_export( $this->plugin_dir, true )
		);
	}

	/**
	 * Every class the bundled library declares must resolve without Composer.
	 *
	 * Message.php reaches for ParserOptions, ParserContext and Rfc2047 without
	 * requiring them, so a plain `require` of one file is not enough.
	 */
	public function test_every_bundled_class_loads_without_composer() {
		$classes = array(
			'Erseco\Message',
			'Erseco\MessagePart',
			'Erseco\ParserContext',
			'Erseco\ParserOptions',
			'Erseco\ParserLimitExceededException',
		);

		$checks = '';
		foreach ( $classes as $class ) {
			$checks .= sprintf(
				'echo %s, ":", class_exists( %s ) ? "yes" : "no", "\n";',
				var_export( $class, true ),
				var_export( $class, true )
			);
		}

		list( $exit_code, $output ) = $this->run_isolated( $this->bootstrap_code() . $checks );

		$this->assertSame( 0, $exit_code, "The isolated process failed:\n{$output}" );

		foreach ( $classes as $class ) {
			$this->assertStringContainsString(
				$class . ':yes',
				$output,
				"{$class} is not reachable from the bundled copy, so an installed plugin would fatal on it."
			);
		}
	}

	/**
	 * A real captured message parses from the bundled copy alone, attachments
	 * included. This is the path the plugin takes on every incoming email.
	 */
	public function test_a_real_message_parses_without_composer() {
		$fixture = $this->plugin_dir . 'tests/fixtures/raw_email_from_gmail_attachments.eml';
		$this->assertFileExists( $fixture );

		$code = $this->bootstrap_code() . sprintf(
			'$m = new Erseco\Message( file_get_contents( %s ) );
			echo "subject:", $m->getSubject(), "\n";
			echo "parts:", count( $m->getParts() ), "\n";
			foreach ( $m->getParts() as $p ) { echo "type:", $p->getContentType(), "\n"; }',
			var_export( $fixture, true )
		);

		list( $exit_code, $output ) = $this->run_isolated( $code );

		$this->assertSame( 0, $exit_code, "Parsing from the bundled copy failed:\n{$output}" );
		$this->assertStringContainsString( 'subject:test from gmail with attachments', $output );
		$this->assertStringContainsString( 'parts:4', $output );
		$this->assertStringContainsString( 'application/pdf', $output );
	}
}
