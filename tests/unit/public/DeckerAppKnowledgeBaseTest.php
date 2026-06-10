<?php
/**
 * Characterization tests for the Knowledge Base page template.
 *
 * These pin the observable markup contract that knowledge-base.js depends on
 * (class hooks and data-* payloads) and the recursive render behaviour of
 * decker_render_kb_node() so the template can be safely refactored.
 *
 * @package Decker
 */

class DeckerAppKnowledgeBaseTest extends Decker_Test_Base {

	/**
	 * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );
		wp_set_current_user( 1 );
		$_GET = array();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Render the Knowledge Base page into a string.
	 *
	 * @return string The captured page output.
	 */
	private function render_kb_page(): string {
		set_query_var( 'decker_page', 'knowledge-base' );

		// The template reads $class_disabled from the including scope (pre-existing).
		$class_disabled = '';

		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/app-knowledge-base.php';
		return ob_get_clean();
	}

	/**
	 * Create a KB article post.
	 *
	 * @param array $args Overrides for wp_insert_post.
	 * @return int The post ID.
	 */
	private function create_kb_article( $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'decker_kb',
					'post_status'  => 'publish',
					'post_title'   => 'Article',
					'post_content' => 'Content',
				),
				$args
			)
		);
	}

	/**
	 * The root node renders with collapsed children and a leaf renders a placeholder.
	 */
	public function test_kb_page_renders_root_node_with_children_collapsed() {
		$board = self::factory()->board->create_and_get();

		$parent_id = $this->create_kb_article( array( 'post_title' => 'Parent Article' ) );
		$child_id  = $this->create_kb_article(
			array(
				'post_title'  => 'Child Article',
				'post_parent' => $parent_id,
			)
		);

		wp_set_object_terms( $parent_id, array( $board->term_id ), 'decker_board' );
		wp_set_object_terms( $child_id, array( $board->term_id ), 'decker_board' );

		$output = $this->render_kb_page();

		$this->assertStringContainsString( 'data-article-id="' . $parent_id . '"', $output );
		$this->assertStringContainsString( 'id="children-of-' . $parent_id . '"', $output );
		$this->assertStringContainsString( 'class="btn btn-sm btn-outline-secondary kb-toggle"', $output );
		$this->assertStringContainsString( 'data-bs-target="#children-of-' . $parent_id . '"', $output );

		// The child <li> appears inside the parent's children UL.
		$this->assertMatchesRegularExpression(
			'/id="children-of-' . $parent_id . '".*data-article-id="' . $child_id . '"/s',
			$output
		);

		// The childless child renders the disabled placeholder, not a toggle.
		$this->assertMatchesRegularExpression(
			'/data-article-id="' . $child_id . '".*?<span class="btn btn-sm btn-outline-light disabled"/s',
			$output
		);
	}

	/**
	 * The node exposes the article payload in the data-* attributes parsed by JS.
	 */
	public function test_kb_node_exposes_article_payload_in_data_attributes() {
		$board = self::factory()->board->create_and_get(
			array(
				'name'  => 'Engineering',
				'slug'  => 'engineering',
				'color' => '#123456',
			)
		);
		$label = self::factory()->label->create_and_get(
			array(
				'name'  => 'RedLabel',
				'slug'  => 'red-label',
				'color' => '#ff0000',
			)
		);

		$article_id = $this->create_kb_article(
			array(
				'post_title'   => 'Payload Article',
				'post_content' => 'Some <b>bold</b> body',
			)
		);
		wp_set_object_terms( $article_id, array( $board->term_id ), 'decker_board' );
		wp_set_object_terms( $article_id, array( $label->term_id ), 'decker_label' );

		$output = $this->render_kb_page();

		// view-article-link anchor carries the data attributes.
		$this->assertStringContainsString( 'class="view-article-link"', $output );
		$this->assertStringContainsString( 'data-id="' . $article_id . '"', $output );
		$this->assertStringContainsString( 'data-title="' . esc_attr( 'Payload Article' ) . '"', $output );

		// Labels JSON (esc_attr'ed) contains label name and color.
		$this->assertStringContainsString( 'RedLabel', $output );
		$this->assertStringContainsString( '#ff0000', $output );

		// Board JSON contains the board name.
		$this->assertStringContainsString( 'Engineering', $output );

		// The <li> exposes parent/menu-order/board id.
		$this->assertStringContainsString( 'data-parent-id="0"', $output );
		$this->assertStringContainsString( 'data-board-id="' . $board->term_id . '"', $output );

		// Hidden search span carries the stripped content.
		$this->assertStringContainsString( 'class="kb-hidden-content d-none"', $output );
		$this->assertStringContainsString( esc_html( wp_strip_all_tags( 'Some <b>bold</b> body' ) ), $output );
	}

	/**
	 * A node with more than three labels renders the +N overflow badge and popover.
	 */
	public function test_kb_node_label_overflow_renders_popover_badge() {
		$labels = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$labels[] = self::factory()->label->create(
				array(
					'name' => 'Lbl' . $i,
					'slug' => 'lbl-' . $i,
				)
			);
		}

		$five_id = $this->create_kb_article( array( 'post_title' => 'Five Labels' ) );
		wp_set_object_terms( $five_id, $labels, 'decker_label' );

		$two_id = $this->create_kb_article( array( 'post_title' => 'Two Labels' ) );
		wp_set_object_terms( $two_id, array( $labels[0], $labels[1] ), 'decker_label' );

		$output = $this->render_kb_page();

		// Overflow badge and popover container for the 5-label article.
		$this->assertStringContainsString( '>+2</span>', $output );
		$this->assertStringContainsString( 'data-popover-target="#kb-popover-' . $five_id . '"', $output );
		$this->assertStringContainsString( '<div id="kb-popover-' . $five_id . '" class="d-none">', $output );

		// The 2-label article has no popover.
		$this->assertStringNotContainsString( 'kb-popover-' . $two_id, $output );
	}

	/**
	 * In view=all the board column header and grouped roots (incl. No Board) render.
	 */
	public function test_kb_page_all_view_groups_roots_by_board_and_shows_board_column() {
		$board_a = self::factory()->board->create_and_get(
			array(
				'name'  => 'Alpha Board',
				'slug'  => 'alpha-board',
				'color' => '#aa0000',
			)
		);
		$board_b = self::factory()->board->create_and_get(
			array(
				'name'  => 'Beta Board',
				'slug'  => 'beta-board',
				'color' => '#00bb00',
			)
		);

		$a_id = $this->create_kb_article( array( 'post_title' => 'Alpha Root' ) );
		wp_set_object_terms( $a_id, array( $board_a->term_id ), 'decker_board' );

		$b_id = $this->create_kb_article( array( 'post_title' => 'Beta Root' ) );
		wp_set_object_terms( $b_id, array( $board_b->term_id ), 'decker_board' );

		// Root article with no board.
		$this->create_kb_article( array( 'post_title' => 'Orphan Root' ) );

		$_GET['view'] = 'all';
		$output       = $this->render_kb_page();

		$this->assertStringContainsString( '<th class="col-1">Board</th>', $output );
		$this->assertStringContainsString( 'class="list-group kb-root" data-parent-id="0" data-board-id="' . $board_a->term_id . '"', $output );
		$this->assertStringContainsString( 'class="list-group kb-root" data-parent-id="0" data-board-id="' . $board_b->term_id . '"', $output );
		$this->assertStringContainsString( 'class="list-group kb-root" data-parent-id="0" data-board-id="0"', $output );
		$this->assertStringContainsString( 'No Board', $output );

		// In all view the toggle/placeholder title attribute is the board name.
		$this->assertMatchesRegularExpression( '/title="' . preg_quote( $board_a->name, '/' ) . '"/', $output );
	}

	/**
	 * In the default (non-all) view the placeholder title attribute is empty.
	 */
	public function test_kb_page_default_view_emits_empty_title_attribute() {
		$board = self::factory()->board->create_and_get();

		$article_id = $this->create_kb_article( array( 'post_title' => 'Lonely Root' ) );
		wp_set_object_terms( $article_id, array( $board->term_id ), 'decker_board' );

		$output = $this->render_kb_page();

		// The childless placeholder still emits an (empty) title attribute.
		$this->assertMatchesRegularExpression(
			'/<span class="btn btn-sm btn-outline-light disabled" aria-hidden="true" title=""/',
			$output
		);
	}

	/**
	 * Filtering by board excludes other boards and sets the current board id.
	 */
	public function test_kb_page_board_filter_excludes_other_boards_and_sets_current_board_id() {
		$board_a = self::factory()->board->create_and_get(
			array(
				'name' => 'Filter Alpha',
				'slug' => 'filter-alpha',
			)
		);
		$board_b = self::factory()->board->create_and_get(
			array(
				'name' => 'Filter Beta',
				'slug' => 'filter-beta',
			)
		);

		$a_id = $this->create_kb_article( array( 'post_title' => 'UniqueAlphaTitle' ) );
		wp_set_object_terms( $a_id, array( $board_a->term_id ), 'decker_board' );

		$b_id = $this->create_kb_article( array( 'post_title' => 'UniqueBetaTitle' ) );
		wp_set_object_terms( $b_id, array( $board_b->term_id ), 'decker_board' );

		$_GET['board'] = $board_a->slug;
		$output        = $this->render_kb_page();

		$this->assertStringContainsString( 'UniqueAlphaTitle', $output );
		$this->assertStringNotContainsString( 'UniqueBetaTitle', $output );
		$this->assertStringContainsString( 'Knowledge Base - ' . $board_a->name, $output );
		$this->assertStringContainsString( 'data-current-board-id="' . $board_a->term_id . '"', $output );

		// A non-existent slug applies no tax_query and yields current board id 0.
		$_GET['board'] = 'nonexistent-slug';
		$output        = $this->render_kb_page();

		$this->assertStringContainsString( 'UniqueAlphaTitle', $output );
		$this->assertStringContainsString( 'UniqueBetaTitle', $output );
		$this->assertStringContainsString( 'data-current-board-id="0"', $output );
	}

	/**
	 * The history link only appears when a revision exists.
	 */
	public function test_kb_node_history_link_rendered_only_when_revisions_exist() {
		$board = self::factory()->board->create_and_get();

		$with_rev = $this->create_kb_article( array( 'post_title' => 'Has Revision' ) );
		wp_set_object_terms( $with_rev, array( $board->term_id ), 'decker_board' );

		$without_rev = $this->create_kb_article( array( 'post_title' => 'No Revision' ) );
		wp_set_object_terms( $without_rev, array( $board->term_id ), 'decker_board' );

		// Force a revision on the first article.
		wp_update_post(
			array(
				'ID'           => $with_rev,
				'post_content' => 'Updated content to spawn a revision',
			)
		);
		wp_save_post_revision( $with_rev );

		$output = $this->render_kb_page();

		// Split the output into per-article segments to scope the assertions.
		$with_segment    = $this->segment_for_article( $output, $with_rev );
		$without_segment = $this->segment_for_article( $output, $without_rev );

		$this->assertStringContainsString( 'ri-history-line', $with_segment );
		$this->assertStringNotContainsString( 'ri-history-line', $without_segment );
	}

	/**
	 * Extract the markup segment belonging to a single article node.
	 *
	 * Starts at the per-node "<li class=\"kb-item ... data-article-id" boundary
	 * and ends at this node's own per-node "edit-container-{ID}" marker, which
	 * closes the node's own action markup (toggle, title, labels, people,
	 * action buttons and dropdown) right before its children list. Bounding on
	 * the per-node marker excludes both the children and any trailing page
	 * content (e.g. the KB view modal's inline JS, which references
	 * "ri-history-line" unconditionally), so history-link scoping stays reliable
	 * even for the last-rendered node.
	 *
	 * @param string $output     Full page output.
	 * @param int    $article_id Target article ID.
	 * @return string The segment for the node up to its own children boundary.
	 */
	private function segment_for_article( $output, $article_id ): string {
		$marker = '<li class="kb-item list-group-item" data-article-id="' . $article_id . '"';
		$start  = strpos( $output, $marker );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $output, $start + strlen( $marker ) );
		$end  = strpos( $rest, 'id="edit-container-' . $article_id . '"' );
		return false === $end ? $rest : substr( $rest, 0, $end );
	}

	/**
	 * decker_render_kb_node() keeps its signature and recursion contract.
	 */
	public function test_decker_render_kb_node_renders_recursive_children_directly() {
		$board = self::factory()->board->create_and_get();

		$parent_id = $this->create_kb_article( array( 'post_title' => 'Direct Parent' ) );
		$child_id  = $this->create_kb_article(
			array(
				'post_title'  => 'Direct Child',
				'post_parent' => $parent_id,
			)
		);
		wp_set_object_terms( $parent_id, array( $board->term_id ), 'decker_board' );
		wp_set_object_terms( $child_id, array( $board->term_id ), 'decker_board' );

		// Render the page once so the guarded functions are defined.
		$this->render_kb_page();

		$this->assertTrue( function_exists( 'decker_render_kb_node' ) );

		$parent           = get_post( $parent_id );
		$child            = get_post( $child_id );
		$parent->children = array( $child );

		ob_start();
		decker_render_kb_node( $parent, true );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-article-id="' . $parent_id . '"', $html );
		$this->assertMatchesRegularExpression(
			'/<ul[^>]*id="children-of-' . $parent_id . '".*data-article-id="' . $child_id . '"/s',
			$html
		);
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
		$this->assertStringContainsString( 'aria-controls="children-of-' . $parent_id . '"', $html );

		// A childless node alone emits no kb-toggle button.
		ob_start();
		decker_render_kb_node( $child, false );
		$child_html = ob_get_clean();

		$this->assertStringNotContainsString( 'kb-toggle', $child_html );
	}
}
