<?php
/**
 * Browser-agent semantic enhancements for Decker interfaces.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues progressive semantic enhancements on Decker pages.
 */
class Decker_Agent_UI {

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	private const SCRIPT_HANDLE = 'decker-agent-semantics';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
	}

	/**
	 * Enqueue semantic DOM enhancements on Decker pages only.
	 *
	 * @return void
	 */
	public function enqueue_script(): void {
		if ( ! get_query_var( 'decker_page' ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugin_dir_url( DECKER_PLUGIN_FILE ) . 'public/assets/js/agent-semantics.js',
			array(),
			DECKER_VERSION,
			true
		);
	}
}
