<?php
/**
 * Characterization tests for the public asset pipeline.
 *
 * These tests pin the observable output of the front-end asset registration:
 * which handles land in the queue for each Decker page, how the Decker script
 * chain is wired together, and the shape of the payloads handed to JavaScript.
 * They exist so the asset code can be restructured without silently dropping
 * or reordering an asset.
 *
 * @package Decker
 */

class DeckerPublicAssetsTest extends Decker_Test_Base {

	/**
	 * Instance under test.
	 *
	 * @var Decker_Public
	 */
	protected $decker_public;

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
	 * Reset the script and style registries and run the asset pipeline.
	 *
	 * @param string $decker_page Value for the decker_page query var.
	 * @param string $editor      Task editor type stored in the settings.
	 * @return void
	 */
	private function enqueue_for( $decker_page, $editor = 'quill' ) {
		update_option(
			'decker_settings',
			array(
				'task_editor_type'      => $editor,
				'collaborative_editing' => '0',
			)
		);

		// Start from an empty registry so each case is measured on its own.
		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;

		set_query_var( 'decker_page', $decker_page );

		$this->decker_public->enqueue_scripts();
	}

	/**
	 * Page-specific handles that must be enqueued for each Decker page.
	 *
	 * @return array<string, array{0:string, 1:array<int,string>, 2:array<int,string>}>
	 */
	public function page_specific_assets_provider() {
		return array(
			'board loads dragula'            => array(
				'board',
				array( 'dragula-min' ),
				array( 'jquery-datatables-min', 'index-global-min', 'knowledge-base' ),
			),
			'calendar loads fullcalendar'    => array(
				'calendar',
				array( 'index-global-min', 'event-calendar', 'event-modal', 'event-card' ),
				array( 'dragula-min', 'jquery-datatables-min', 'knowledge-base' ),
			),
			'tasks loads datatables'         => array(
				'tasks',
				array(
					'jquery-datatables-min',
					'datatables-searchbuilder-min',
					'datatables-select-min',
					'datatables-buttons-min',
					'buttons-html5-min',
					'buttons-print-min',
				),
				array( 'dragula-min', 'index-global-min', 'knowledge-base' ),
			),
			'knowledge-base loads its script' => array(
				'knowledge-base',
				array( 'knowledge-base' ),
				array( 'dragula-min', 'jquery-datatables-min', 'index-global-min' ),
			),
			'event-manager loads event ui'   => array(
				'event-manager',
				array( 'event-modal', 'event-card' ),
				array( 'dragula-min', 'jquery-datatables-min', 'event-calendar', 'knowledge-base' ),
			),
			'task loads no page extras'      => array(
				'task',
				array(),
				array( 'dragula-min', 'jquery-datatables-min', 'index-global-min', 'knowledge-base', 'event-modal' ),
			),
		);
	}

	/**
	 * Each Decker page enqueues its own scripts and none of the other pages'.
	 *
	 * @dataProvider page_specific_assets_provider
	 *
	 * @param string             $decker_page Page under test.
	 * @param array<int, string> $expected    Handles that must be present.
	 * @param array<int, string> $forbidden   Handles that must be absent.
	 */
	public function test_page_specific_scripts_are_enqueued( $decker_page, $expected, $forbidden ) {
		$this->enqueue_for( $decker_page );

		$queue = wp_scripts()->queue;

		foreach ( $expected as $handle ) {
			$this->assertContains(
				$handle,
				$queue,
				"Page '{$decker_page}' should enqueue '{$handle}'."
			);
		}

		foreach ( $forbidden as $handle ) {
			$this->assertNotContains(
				$handle,
				$queue,
				"Page '{$decker_page}' should not enqueue '{$handle}'."
			);
		}
	}

	/**
	 * The shared Decker assets are enqueued on every Decker page.
	 */
	public function test_common_assets_are_enqueued_on_every_page() {
		$common_scripts = array(
			'jquery',
			'heartbeat',
			'wp-api',
			'config',
			'bootstrap-bundle-min',
			'app',
			'sidebar-preferences',
			'decker-public',
			'task-comments-popover',
			'task-labels-popover',
			'task-modal',
			'task-card',
			'decker-heartbeat',
			'global-search',
			'decker-ai',
		);

		$common_styles = array(
			'bootstrap-min',
			'remixicon-min',
			'attex',
			'app',
			'decker-public',
			'decker-ai',
		);

		foreach ( array( 'task', 'board', 'calendar', 'tasks', 'knowledge-base', 'event-manager' ) as $page ) {
			$this->enqueue_for( $page );

			foreach ( $common_scripts as $handle ) {
				$this->assertContains( $handle, wp_scripts()->queue, "Page '{$page}' lost script '{$handle}'." );
			}

			foreach ( $common_styles as $handle ) {
				$this->assertContains( $handle, wp_styles()->queue, "Page '{$page}' lost style '{$handle}'." );
			}
		}
	}

	/**
	 * Selecting Quill loads the Quill bundle and not the classic editor shim.
	 */
	public function test_quill_editor_assets() {
		$this->enqueue_for( 'task', 'quill' );

		$scripts = wp_scripts()->queue;
		$styles  = wp_styles()->queue;

		$this->assertContains( 'quill-min', $scripts );
		$this->assertContains( 'quill-htmleditbutton-min', $scripts );
		$this->assertContains( 'quill-cursors-min', $scripts );
		$this->assertContains( 'quill-snow-min', $styles );
		$this->assertContains( 'quill-cursors', $styles );

		$this->assertNotContains( 'tinymce-checklist', $scripts );
	}

	/**
	 * Selecting the classic editor loads the checklist shim instead of Quill.
	 */
	public function test_classic_editor_assets() {
		$this->enqueue_for( 'task', 'tinymce' );

		$scripts = wp_scripts()->queue;

		$this->assertContains( 'tinymce-checklist', $scripts );
		$this->assertNotContains( 'quill-min', $scripts );
		$this->assertNotContains( 'quill-cursors-min', $scripts );
	}

	/**
	 * Collaborative editing forces Quill even when the classic editor is selected.
	 */
	public function test_collaborative_editing_forces_quill() {
		update_option(
			'decker_settings',
			array(
				'task_editor_type'      => 'tinymce',
				'collaborative_editing' => '1',
			)
		);

		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;

		set_query_var( 'decker_page', 'task' );
		$this->decker_public->enqueue_scripts();

		$this->assertContains( 'quill-min', wp_scripts()->queue );
		$this->assertNotContains( 'tinymce-checklist', wp_scripts()->queue );
	}

	/**
	 * Decker scripts are chained so each one depends on the previously enqueued script.
	 *
	 * The asset loop wires every script to the one before it to force execution
	 * order; losing that chain would let modules run before their dependencies.
	 */
	public function test_scripts_are_chained_by_dependency() {
		$this->enqueue_for( 'task' );

		$scripts = wp_scripts();

		// Spot-check the chain across the tail of the Decker-owned scripts.
		$chain = array(
			'decker-ai'        => 'global-search',
			'global-search'    => 'decker-heartbeat',
			'decker-heartbeat' => 'task-card',
		);

		foreach ( $chain as $handle => $expected_dependency ) {
			$this->assertArrayHasKey( $handle, $scripts->registered, "Missing handle '{$handle}'." );
			$this->assertContains(
				$expected_dependency,
				$scripts->registered[ $handle ]->deps,
				"Script '{$handle}' should depend on '{$expected_dependency}'."
			);
		}
	}

	/**
	 * All Decker assets are versioned with the plugin version.
	 */
	public function test_assets_are_versioned_with_plugin_version() {
		$this->enqueue_for( 'task' );

		$scripts = wp_scripts();

		foreach ( array( 'config', 'task-card', 'decker-public', 'global-search' ) as $handle ) {
			$this->assertSame(
				DECKER_VERSION,
				$scripts->registered[ $handle ]->ver,
				"Script '{$handle}' should carry the plugin version."
			);
		}
	}

	/**
	 * Decode the deckerVars payload attached to a handle.
	 *
	 * @param string $handle Script handle carrying the localized data.
	 * @param string $object JavaScript object name.
	 * @return array<string, mixed>
	 */
	private function get_localized_data( $handle, $object ) {
		$data = wp_scripts()->get_data( $handle, 'data' );

		$this->assertIsString( $data, "Handle '{$handle}' has no localized data." );
		$this->assertSame(
			1,
			preg_match( '/var ' . preg_quote( $object, '/' ) . ' = (.*);$/m', $data, $matches ),
			"Could not find '{$object}' in the data for '{$handle}'."
		);

		$decoded = json_decode( $matches[1], true );
		$this->assertIsArray( $decoded, "'{$object}' is not valid JSON." );

		return $decoded;
	}

	/**
	 * The deckerVars payload keeps its documented top-level shape.
	 */
	public function test_decker_vars_payload_shape() {
		$this->enqueue_for( 'task' );

		$vars = $this->get_localized_data( 'task-card', 'deckerVars' );

		$this->assertSame(
			array(
				'ajax_url',
				'home_url',
				'nonces',
				'strings',
				'timeFormat24h',
				'disabled',
				'current_user_id',
				'task_editor_type',
				'use_quill_editor',
				'users',
				'locale',
				'taskPermalinkStructure',
				'ai',
			),
			array_keys( $vars )
		);

		$this->assertSame(
			array(
				'task_comment_nonce',
				'wp_rest_nonce',
				'upload_attachment_nonce',
				'delete_attachment_nonce',
				'save_decker_task_nonce',
			),
			array_keys( $vars['nonces'] )
		);

		$this->assertSame(
			array( 'enabled', 'provider', 'api_endpoint', 'server_available', 'strings', 'prompts' ),
			array_keys( $vars['ai'] )
		);

		// The strings bundle is large; pin its size so entries cannot be dropped silently.
		$this->assertCount( 77, $vars['strings'] );
	}

	/**
	 * The editor selection is reflected in the payload handed to JavaScript.
	 *
	 * Note that wp_localize_script() stringifies scalars, so the boolean flag
	 * reaches JavaScript as '1' / '' rather than true / false.
	 */
	public function test_decker_vars_reports_editor_selection() {
		$this->enqueue_for( 'task', 'quill' );
		$vars = $this->get_localized_data( 'task-card', 'deckerVars' );
		$this->assertSame( 'quill', $vars['task_editor_type'] );
		$this->assertSame( '1', $vars['use_quill_editor'] );

		$this->enqueue_for( 'task', 'tinymce' );
		$vars = $this->get_localized_data( 'task-card', 'deckerVars' );
		$this->assertSame( 'tinymce', $vars['task_editor_type'] );
		$this->assertSame( '', $vars['use_quill_editor'] );
	}

	/**
	 * Each consumer script receives its own localized payload.
	 */
	public function test_per_script_localized_payloads() {
		$this->enqueue_for( 'task' );

		$task_modal = $this->get_localized_data( 'task-modal', 'jsdata_task' );
		$this->assertArrayHasKey( 'ajaxUrl', $task_modal );
		$this->assertArrayHasKey( 'nonce', $task_modal );
		$this->assertArrayHasKey( 'loadingMessage', $task_modal );

		$heartbeat = $this->get_localized_data( 'decker-heartbeat', 'DeckerData' );
		$this->assertArrayHasKey( 'ajaxUrl', $heartbeat );
		$this->assertArrayHasKey( 'nonce', $heartbeat );
		$this->assertArrayHasKey( 'labels', $heartbeat );

		$search = $this->get_localized_data( 'global-search', 'deckerSearchVars' );
		$this->assertArrayHasKey( 'restUrl', $search );
		$this->assertArrayHasKey( 'nonce', $search );
		$this->assertArrayHasKey( 'strings', $search );

		$public = $this->get_localized_data( 'decker-public', 'deckerData' );
		$this->assertArrayHasKey( 'userId', $public );
	}

	/**
	 * The event card payload is attached on pages that actually load the event UI.
	 */
	public function test_event_payload_only_on_event_pages() {
		$this->enqueue_for( 'task' );
		$this->assertFalse(
			wp_scripts()->get_data( 'event-modal', 'data' ),
			'The task page should not carry event-modal data.'
		);

		$this->enqueue_for( 'calendar' );
		$event_modal = $this->get_localized_data( 'event-modal', 'jsdata_event' );
		$this->assertArrayHasKey( 'ajaxUrl', $event_modal );
		$this->assertArrayHasKey( 'nonce', $event_modal );
	}

	/**
	 * The current user id is injected inline before the config script runs.
	 */
	public function test_user_id_is_inlined_before_config() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		wp_set_current_user( $user->ID );

		$this->enqueue_for( 'task' );

		$before = wp_scripts()->get_data( 'config', 'before' );

		$this->assertIsArray( $before );
		$this->assertStringContainsString( 'const userId = ' . $user->ID . ';', implode( "\n", array_filter( $before ) ) );
	}

	/**
	 * Nothing is enqueued when the request is not a Decker page.
	 */
	public function test_no_assets_outside_decker_pages() {
		global $wp_scripts, $wp_styles;
		$wp_scripts = null;
		$wp_styles  = null;

		set_query_var( 'decker_page', '' );

		$this->decker_public->enqueue_scripts();

		$this->assertNotContains( 'task-card', wp_scripts()->queue );
		$this->assertNotContains( 'decker-public', wp_scripts()->queue );
		$this->assertNotContains( 'decker-public', wp_styles()->queue );
	}
}
