<?php
/**
 * Class DeckerSidebarTest
 *
 * @package Decker
 */

class DeckerSidebarTest extends Decker_Test_Base {

	/**
	 * Test the theme customizer no longer offers a per-browser board status toggle.
	 *
	 * The retired indicators must not appear in the appearance panel.
	 */
	public function test_right_sidebar_does_not_offer_a_board_status_toggle() {
		ob_start();
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/layouts/right-sidebar.php';
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'sidebar-board-status', $output );
		$this->assertStringNotContainsString( 'Show board status indicators', $output );
	}

	/**
	 * Seed two boards, one with a to-do and an in-progress task and one without.
	 */
	private function seed_boards_with_counts() {
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
	}

	/**
	 * Render the left sidebar.
	 *
	 * @return string The sidebar markup.
	 */
	private function render_left_sidebar() {
		ob_start();
		// Use include (not include_once): another test may have already loaded the
		// template in this process, which would make include_once a no-op and yield
		// empty output. The function declarations are function_exists-guarded, so
		// re-including only re-renders the markup.
		include plugin_dir_path( DECKER_PLUGIN_FILE ) . 'public/layouts/left-sidebar.php';
		return ob_get_clean();
	}

	/**
	 * Retired options cannot restore board badges, while board links remain.
	 */
	public function test_retired_board_setting_cannot_restore_badges() {
		$this->seed_boards_with_counts();
		update_option( 'decker_settings', array( 'sidebar_board_status' => '1' ) );
		$output = $this->render_left_sidebar();
		$this->assertStringNotContainsString( 'decker-sidebar-board-badges', $output );
		$this->assertStringContainsString( 'Infrastructure and Development', $output );
		$this->assertStringContainsString( 'Empty Board', $output );
	}

}
