<?php
/**
 * Characterization test for Decker_Tasks::display_user_date_meta_box().
 *
 * Pins the echoed markup and script markers before the method is split into
 * render helpers.
 *
 * @package Decker
 */

class DeckerTasksMetaBoxLockInTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Lock the markup and script markers emitted by the meta box.
	 */
	public function test_display_user_date_meta_box_output_markers() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => '2025-02-03',
				),
			)
		);

		$user = get_userdata( $this->editor );

		ob_start();
		( new Decker_Tasks() )->display_user_date_meta_box( get_post( $task_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="user-date-relations-list"', $html );
		$this->assertStringContainsString( 'data-user-id="' . $this->editor . '"', $html );
		$this->assertStringContainsString( 'data-date="2025-02-03"', $html );
		$this->assertStringContainsString( $user->display_name, $html );
		$this->assertStringContainsString( 'id="add-user-date-relation"', $html );
		$this->assertStringContainsString( 'user_date_relations', $html );
	}
}
