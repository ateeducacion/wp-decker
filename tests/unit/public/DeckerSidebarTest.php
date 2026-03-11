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
				'name' => 'Board With Counts',
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
			'Board With Counts</span><span class="decker-sidebar-board-badges"><span class="badge bg-secondary">1</span><span class="badge decker-badge-orange">1</span></span>',
			$output
		);
		$this->assertStringContainsString(
			'>Empty Board</span></a></li>',
			$output
		);
		$this->assertStringNotContainsString(
			'Empty Board</span><span class="decker-sidebar-board-badges">',
			$output
		);
	}
}
