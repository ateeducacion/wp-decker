<?php
/**
 * The runtime and development Composer loaders must coexist in either order.
 *
 * @package Decker
 */
class DeckerBundledDavTest extends Decker_Test_Base {
	/** @dataProvider load_orders */
	public function test_bundled_dav_loads_in_an_isolated_process( $files ) {
		$root = dirname( __DIR__, 3 ) . '/';
		$code = '';
		foreach ( $files as $file ) {
			$code .= 'require ' . var_export( $root . $file, true ) . ';';
		}
		$code .= 'echo class_exists( Sabre\\DAV\\Server::class ) ? "DAV loaded" : "DAV missing";';
		$output = array();
		$exit_code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $exit_code );
		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
		$this->assertSame( 'DAV loaded', implode( "\n", $output ) );
	}
	public function load_orders() {
		$runtime = 'admin/vendor/dav/vendor/autoload.php';
		$development = 'vendor/autoload.php';
		return array(
			'bundle only' => array( array( $runtime ) ),
			'development first' => array( array( $development, $runtime ) ),
			'bundle first' => array( array( $runtime, $development ) ),
		);
	}
}
