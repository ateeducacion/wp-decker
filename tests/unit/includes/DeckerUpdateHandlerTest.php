<?php
/**
 * Class DeckerUpdateHandlerTest
 *
 * @package Decker
 */

/**
 * Tests for decker_update_handler().
 */
class DeckerUpdateHandlerTest extends Decker_Test_Base {

	/**
	 * Base name of the plugin as seen by the update handler.
	 *
	 * @var string
	 */
	protected $plugin_file;

	public function set_up() {
		parent::set_up();

		$this->plugin_file = plugin_basename( DECKER_PLUGIN_FILE );

		// A pretty permalink structure ensures flush_rewrite_rules() runs its full
		// generation path (and fires 'generate_rewrite_rules'), so flushes are observable.
		global $wp_rewrite;
		update_option( 'permalink_structure', '/%postname%/' );
		$wp_rewrite->init();
	}

	public function tear_down() {
		global $wp_rewrite;
		update_option( 'permalink_structure', '' );
		$wp_rewrite->init();
		parent::tear_down();
	}

	/**
	 * Counts how many times flush_rewrite_rules() runs while the handler executes.
	 *
	 * @param array $options Upgrade options passed to the handler.
	 * @return int Number of flushes triggered.
	 */
	protected function count_flushes( array $options ) {
		$flushes = 0;
		$counter = static function () use ( &$flushes ) {
			$flushes++;
		};

		// flush_rewrite_rules() rebuilds the rules and fires 'generate_rewrite_rules' each time.
		add_action( 'generate_rewrite_rules', $counter );
		decker_update_handler( null, $options );
		remove_action( 'generate_rewrite_rules', $counter );

		return $flushes;
	}

	/**
	 * The single-plugin path (auto-updates, non-AJAX update page) must not throw and
	 * must trigger the update task when this plugin matches.
	 */
	public function test_single_plugin_update_triggers_flush() {
		$flushes = $this->count_flushes(
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => $this->plugin_file,
			)
		);

		$this->assertSame( 1, $flushes, 'Single-plugin update should trigger one rewrite flush.' );
	}

	/**
	 * A single-plugin update for a different plugin must be a safe no-op.
	 */
	public function test_single_plugin_update_for_other_plugin_is_noop() {
		$flushes = $this->count_flushes(
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => 'another-plugin/another-plugin.php',
			)
		);

		$this->assertSame( 0, $flushes, 'Update for an unrelated plugin should not flush.' );
	}

	/**
	 * The bulk path with a 'plugins' array must still trigger the update task.
	 */
	public function test_bulk_plugins_update_triggers_flush() {
		$flushes = $this->count_flushes(
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'plugins' => array( 'another-plugin/another-plugin.php', $this->plugin_file ),
			)
		);

		$this->assertSame( 1, $flushes, 'Bulk update including this plugin should trigger one rewrite flush.' );
	}

	/**
	 * A theme update must be ignored without warnings or flushes.
	 */
	public function test_theme_update_is_noop() {
		$flushes = $this->count_flushes(
			array(
				'action' => 'update',
				'type'   => 'theme',
				'themes' => array( 'twentytwentyfour' ),
			)
		);

		$this->assertSame( 0, $flushes, 'Theme update should be ignored by the plugin handler.' );
	}

	/**
	 * Empty / missing-key options must be a safe no-op without undefined index warnings.
	 */
	public function test_empty_options_is_noop() {
		$flushes = $this->count_flushes( array() );

		$this->assertSame( 0, $flushes, 'Empty options should be a safe no-op.' );
	}
}
