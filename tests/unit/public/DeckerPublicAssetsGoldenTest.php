<?php
/**
 * Golden-snapshot test for the public asset queues.
 *
 * The expected values below were captured from the asset pipeline BEFORE it was
 * extracted into Decker_Public_Assets, so this file is the committed proof that
 * the extraction did not reorder, drop or add an asset.
 *
 * Only plugin-owned handles are pinned. The WordPress core bundles pulled in by
 * wp_enqueue_editor() / wp_enqueue_media() are deliberately excluded: those are
 * guarded by did_action(), so whether they appear depends on what ran earlier in
 * the PHP process rather than on this code.
 *
 * @package Decker
 */

class DeckerPublicAssetsGoldenTest extends Decker_Test_Base {

	/**
	 * Instance under test.
	 *
	 * @var Decker_Public
	 */
	private $decker_public;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			define( 'WP_TESTS_RUNNING', true );
		}

		$this->decker_public = new Decker_Public( 'decker', '1.0.0' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		parent::tear_down();
	}

	/**
	 * Handles WordPress core enqueues on its own; not this code's business.
	 *
	 * @var array<int, string>
	 */
	private const CORE_HANDLES = array(
		'jquery',
		'heartbeat',
		'wp-api',
		'editor',
		'quicktags',
		'wplink',
		'jquery-ui-autocomplete',
		'media-upload',
		'media-editor',
		'media-audiovideo',
		'buttons',
		'editor-buttons',
		'media-views',
		'imgareaselect',
	);

	/**
	 * Run the pipeline for one page/editor combination on an empty registry.
	 *
	 * @param string $decker_page Value for the decker_page query var.
	 * @param string $editor      Task editor type stored in the settings.
	 */
	private function enqueue_for( $decker_page, $editor ) {
		update_option(
			'decker_settings',
			array(
				'task_editor_type'      => $editor,
				'collaborative_editing' => '0',
			)
		);

		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;

		set_query_var( 'decker_page', $decker_page );

		$this->decker_public->enqueue_scripts();
	}

	/**
	 * The plugin-owned handles of a queue, in enqueue order.
	 *
	 * @param array<int, string> $queue Raw queue.
	 * @return array<int, string> Queue without the WordPress core bundles.
	 */
	private function plugin_handles( array $queue ) {
		return array_values( array_diff( $queue, self::CORE_HANDLES ) );
	}

	/**
	 * Expected script and style queues for every page and editor combination.
	 *
	 * @return array<string, array{0:string,1:string,2:array<int,string>,3:array<int,string>}>
	 */
	public function golden_queue_provider() {
		return array(
			'task / quill' => array(
				'task',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'task / classic' => array(
				'task',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
			'board / quill' => array(
				'board',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'dragula-min',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'board / classic' => array(
				'board',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'dragula-min',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
			'calendar / quill' => array(
				'calendar',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'index-global-min',
					'event-calendar',
					'event-modal',
					'event-card',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'calendar / classic' => array(
				'calendar',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'index-global-min',
					'event-calendar',
					'event-modal',
					'event-card',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
			'tasks / quill' => array(
				'tasks',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'jquery-datatables-min',
					'datatables-searchbuilder-min',
					'datatables-select-min',
					'datatables-buttons-min',
					'buttons-html5-min',
					'buttons-print-min',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'jquery-datatables-min',
					'searchbuilder-datatables-min',
					'select-datatables-min',
					'buttons-datatables-min',
					'decker-ai',
				),
			),
			'tasks / classic' => array(
				'tasks',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'tinymce-checklist',
					'jquery-datatables-min',
					'datatables-searchbuilder-min',
					'datatables-select-min',
					'datatables-buttons-min',
					'buttons-html5-min',
					'buttons-print-min',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'jquery-datatables-min',
					'searchbuilder-datatables-min',
					'select-datatables-min',
					'buttons-datatables-min',
					'decker-ai',
				),
			),
			'knowledge-base / quill' => array(
				'knowledge-base',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'knowledge-base',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'knowledge-base / classic' => array(
				'knowledge-base',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'knowledge-base',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
			'event-manager / quill' => array(
				'event-manager',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'event-modal',
					'event-card',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'event-manager / classic' => array(
				'event-manager',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'event-modal',
					'event-card',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
			'analytics / quill' => array(
				'analytics',
				'quill',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'quill-min',
					'quill-htmleditbutton-min',
					'quill-cursors-min',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'quill-snow-min',
					'quill-cursors',
					'decker-ai',
				),
			),
			'analytics / classic' => array(
				'analytics',
				'tinymce',
				array(
					'config',
					'bootstrap-bundle-min',
					'tablesort-min',
					'simplebar-min',
					'sortable-min',
					'choices-min',
					'sweetalert2-all-min',
					'chart-umd-min',
					'app',
					'sidebar-preferences',
					'decker-public',
					'task-comments-popover',
					'task-labels-popover',
					'task-modal',
					'tinymce-checklist',
					'task-card',
					'decker-heartbeat',
					'global-search',
					'decker-ai',
				),
				array(
					'bootstrap-min',
					'remixicon-min',
					'all-min',
					'choices-min',
					'sweetalert2-min',
					'attex',
					'app',
					'decker-public',
					'decker-ai',
				),
			),
		);
	}

	/**
	 * Every page/editor combination enqueues exactly these assets, in this order.
	 *
	 * @dataProvider golden_queue_provider
	 *
	 * @param string             $decker_page      Page under test.
	 * @param string             $editor           Task editor type.
	 * @param array<int, string> $expected_scripts Ordered plugin script handles.
	 * @param array<int, string> $expected_styles  Ordered plugin style handles.
	 */
	public function test_asset_queues_match_the_golden_snapshot( $decker_page, $editor, $expected_scripts, $expected_styles ) {
		$this->enqueue_for( $decker_page, $editor );

		$this->assertSame(
			$expected_scripts,
			$this->plugin_handles( wp_scripts()->queue ),
			"Script queue changed for '{$decker_page}' with the {$editor} editor."
		);

		$this->assertSame(
			$expected_styles,
			$this->plugin_handles( wp_styles()->queue ),
			"Style queue changed for '{$decker_page}' with the {$editor} editor."
		);
	}

	/**
	 * Every enqueued script is chained to the plugin script enqueued before it.
	 *
	 * The asset loop wires each script to the previous one to force execution
	 * order. This checks the whole chain, not a sample of it.
	 *
	 * @dataProvider golden_queue_provider
	 *
	 * @param string             $decker_page      Page under test.
	 * @param string             $editor           Task editor type.
	 * @param array<int, string> $expected_scripts Ordered plugin script handles.
	 */
	public function test_full_dependency_chain( $decker_page, $editor, $expected_scripts ) {
		$this->enqueue_for( $decker_page, $editor );

		$registered = wp_scripts()->registered;
		$previous   = null;

		foreach ( $expected_scripts as $handle ) {
			$this->assertArrayHasKey( $handle, $registered, "Script '{$handle}' is not registered." );

			if ( null !== $previous ) {
				$this->assertSame(
					array( $previous ),
					$registered[ $handle ]->deps,
					"Script '{$handle}' should depend on exactly '{$previous}'."
				);
			}

			$previous = $handle;
		}
	}

	/**
	 * Every plugin asset resolves to the expected source and plugin version.
	 *
	 * @dataProvider golden_queue_provider
	 *
	 * @param string             $decker_page      Page under test.
	 * @param string             $editor           Task editor type.
	 * @param array<int, string> $expected_scripts Ordered plugin script handles.
	 * @param array<int, string> $expected_styles  Ordered plugin style handles.
	 */
	public function test_sources_and_versions( $decker_page, $editor, $expected_scripts, $expected_styles ) {
		$this->enqueue_for( $decker_page, $editor );

		$plugin_url = plugin_dir_url( dirname( __DIR__, 3 ) . '/decker.php' );

		foreach ( array( wp_scripts(), wp_styles() ) as $index => $registry ) {
			$handles = 0 === $index ? $expected_scripts : $expected_styles;

			foreach ( $handles as $handle ) {
				$asset = $registry->registered[ $handle ];

				$this->assertNotEmpty( $asset->src, "Asset '{$handle}' has no source." );
				$this->assertSame(
					DECKER_VERSION,
					$asset->ver,
					"Asset '{$handle}' should carry the plugin version."
				);

				// Every asset is either bundled with the plugin or served from a known CDN.
				$this->assertMatchesRegularExpression(
					'#^(' . preg_quote( $plugin_url, '#' ) . '|https://cdn\.|https://cdnjs\.)#',
					$asset->src,
					"Asset '{$handle}' has an unexpected source: {$asset->src}"
				);
			}
		}
	}
}
