<?php
/**
 * Class DeckerSidebarTest
 *
 * @package Decker
 */

class DeckerSidebarTest extends Decker_Test_Base {

	/**
	 * Test board badges render only non-zero to-do and in-progress counts.
	 */
	public function test_left_sidebar_renders_board_status_badges() {
		do_action( 'init' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( 1 );

		$board_with_counts = self::factory()->board->create_and_get(
			array(
				'name' => 'Infrastructure and Development',
				'slug' => 'board-with-counts',
			)
		);
		$empty_board       = self::factory()->board->create_and_get(
			array(
				'name' => 'Empty Board',
				'slug' => 'empty-board',
			)
		);

		wp_set_current_user( $editor );

		self::factory()->task->create(
			array(
				'board' => $board_with_counts->term_id,
				'stack' => 'to-do',
			)
		);
		self::factory()->task->create(
			array(
				'board' => $board_with_counts->term_id,
				'stack' => 'in-progress',
			)
		);

		$hidden_task_id = self::factory()->task->create(
			array(
				'board' => $empty_board->term_id,
				'stack' => 'to-do',
			)
		);
		update_post_meta( $hidden_task_id, 'hidden', '1' );

		BoardManager::reset_instance();

		$_GET['decker_page'] = 'board';
		$_GET['slug']        = $board_with_counts->slug;
		set_query_var( 'decker_page', 'board' );

		ob_start();
		include_once plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/layouts/left-sidebar.php';
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'decker-sidebar-board-link',
			$output
		);
		$this->assertStringContainsString(
			'decker-sidebar-board-title',
			$output
		);
		$this->assertStringContainsString(
			'Infrastructure and Development',
			$output
		);
		$this->assertStringContainsString(
			'decker-sidebar-board-badges',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/decker-sidebar-board-status decker-sidebar-board-status-todo/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/decker-sidebar-board-status decker-sidebar-board-status-in-progress/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/ri-checkbox-blank-circle-line text-secondary/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/ri-progress-3-line text-warning/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/data-bs-original-title="To-Do"/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/data-bs-original-title="In Progress"/',
			$output
		);
		$this->assertMatchesRegularExpression(
			'/<sup class="decker-sidebar-board-status-count">1<\/sup>/',
			$output
		);
		$this->assertStringContainsString(
			'Empty Board',
			$output
		);
		$this->assertSame( 1, preg_match_all( '/decker-sidebar-board-badges/', $output ) );
		$this->assertMatchesRegularExpression(
			'/Infrastructure and Development.*decker-sidebar-board-badges/s',
			$output
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<span class="decker-sidebar-board-title">Empty Board<\/span>(?:(?!<\/a>).)*decker-sidebar-board-badges/s',
			$output
		);
	}
}
