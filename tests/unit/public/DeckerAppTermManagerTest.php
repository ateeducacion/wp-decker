<?php
/**
 * Characterization tests for the term manager page template.
 *
 * The page lists boards or labels depending on ?type=, and each row carries the
 * hidden payload term-manager.js reads back into the edit modal. The CSRF and
 * capability guards on the same file are covered by
 * DeckerTermManagerSecurityTest; these cover the rendering side.
 *
 * @package Decker
 */

class DeckerAppTermManagerTest extends Decker_Test_Base {

	/**
	 * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		wp_set_current_user( 1 );
		BoardManager::reset_instance();
		LabelManager::reset_instance();

		$_GET  = array();
		$_POST = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Render the term manager page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_term_manager_page(): string {
		set_query_var( 'decker_page', 'term-manager' );

		// The board and label factories leave a valid decker_term_nonce behind in
		// $_POST; clear it so the include is seen as a plain GET of the page.
		$_POST = array();

		// The listing reads through the cached managers, which survive across
		// test classes; drop the cache so the fixtures show up.
		BoardManager::reset_instance();
		LabelManager::reset_instance();

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-term-manager.php';
		return ob_get_clean();
	}

	/**
	 * Without ?type= the page lists labels.
	 */
	public function test_default_view_lists_labels() {
		self::factory()->label->create(
			array(
				'name'  => 'Needs Review',
				'slug'  => 'needs-review',
				'color' => '#123abc',
			)
		);
		LabelManager::reset_instance();

		$output = $this->render_term_manager_page();

		$this->assertStringContainsString( 'Labels', $output );
		$this->assertStringContainsString( 'Add New Label', $output );
		$this->assertStringContainsString( '<td class="term-slug">needs-review</td>', $output );
		$this->assertStringContainsString( 'background-color: #123abc;">Needs Review</span>', $output );
		$this->assertStringContainsString( 'class="btn btn-sm btn-danger delete-term" data-type="label"', $output );

		// Board-only columns are absent on the label view.
		$this->assertStringNotContainsString( 'term-show-in-boards-display', $output );
		$this->assertStringNotContainsString( 'In KB', $output );
	}

	/**
	 * ?type=board lists boards with the visibility columns and hidden payload.
	 */
	public function test_board_view_lists_boards_with_visibility_columns() {
		$board_id = self::factory()->board->create(
			array(
				'name'  => 'Ops Board',
				'slug'  => 'ops-board',
				'color' => '#ff8800',
			)
		);
		wp_update_term( $board_id, 'decker_board', array( 'description' => 'Operations work' ) );
		update_term_meta( $board_id, 'term-show-in-boards', '1' );
		update_term_meta( $board_id, 'term-show-in-kb', '0' );
		BoardManager::reset_instance();

		$_GET['type'] = 'board';
		$output       = $this->render_term_manager_page();

		$this->assertStringContainsString( 'Add New Board', $output );
		$this->assertStringContainsString( 'In Boards', $output );
		$this->assertStringContainsString( 'In KB', $output );
		$this->assertStringContainsString( '<td class="term-slug">ops-board</td>', $output );

		// Visibility ticks and the hidden payload used by the edit modal.
		$this->assertStringContainsString( '<td class="term-show-in-boards-display text-center"><span class="text-success">✓</span></td>', $output );
		$this->assertStringContainsString( '<td class="term-show-in-kb-display text-center"><span class="text-danger">✗</span></td>', $output );
		$this->assertStringContainsString( '<span class="term-description d-none">Operations work</span>', $output );
		$this->assertStringContainsString( '<span class="term-show-in-boards d-none">1</span>', $output );
		$this->assertStringContainsString( '<span class="term-show-in-kb d-none">0</span>', $output );
		$this->assertStringContainsString( 'data-type="board" data-id="' . $board_id . '"', $output );
	}

	/**
	 * A term with no colour renders its plain name instead of a badge.
	 */
	public function test_term_without_colour_renders_plain_name() {
		self::factory()->label->create(
			array(
				'name'  => 'Plain Label',
				'slug'  => 'plain-label',
				'color' => '',
			)
		);
		LabelManager::reset_instance();

		$output = $this->render_term_manager_page();

		$this->assertStringContainsString( '<td class="term-name">Plain Label</td>', $output );
		$this->assertStringNotContainsString( '">Plain Label</span>', $output );
	}

	/**
	 * The table renders its header even when there is nothing to list.
	 */
	public function test_empty_list_still_renders_the_table_shell() {
		$output = $this->render_term_manager_page();

		$this->assertStringContainsString( 'id="termsTable"', $output );
		$this->assertStringContainsString( '<tbody>', $output );
		$this->assertStringNotContainsString( 'class="term-slug"', $output );
	}
}
