<?php
/**
 * Integration tests for server-side archived-task protection on saves.
 *
 * The task card UI renders archived tasks read-only, but the AJAX save
 * endpoint must also reject writes so a crafted request (or an editor
 * bypass such as programmatic Quicktags edits) cannot modify an archived
 * task.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskArchivedSaveProtectionTest
 */
class DeckerTaskArchivedSaveProtectionTest extends Decker_Test_Base {

	/**
	 * Editor user.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Board fixture.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Lock manager.
	 *
	 * @var Decker_Task_Locks
	 */
	private $locks;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'post_title' => 'Original title',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);

		$this->locks = new Decker_Task_Locks();

		// Make handle_save_decker_task() return its payload instead of dying.
		add_filter( 'decker_save_task_send_response', '__return_false' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'decker_save_task_send_response', '__return_false' );
		$_POST = array();
		wp_delete_user( $this->user_id );
		parent::tear_down();
	}

	/**
	 * Run the AJAX save handler against the current $_POST payload.
	 */
	private function ajax_save() {
		return ( new Decker_Task_Ajax_Save( new Decker_Tasks() ) )->handle_save_decker_task();
	}

	/**
	 * Build a valid save payload for the task under test.
	 *
	 * @param string      $title      The title to save.
	 * @param string|null $generation Optional lock generation token from the editor session.
	 * @return array The $_POST payload.
	 */
	private function save_payload( string $title, $generation = null ): array {
		$payload = array(
			'task_id' => $this->task_id,
			'title'   => $title,
			'stack'   => 'to-do',
			'board'   => $this->board_id,
		);

		if ( null !== $generation ) {
			$payload['lock_generation'] = $generation;
		}

		return $payload;
	}

	/**
	 * Saving an archived task through the AJAX handler is rejected.
	 */
	public function test_archived_task_save_is_rejected() {
		wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			)
		);

		$_POST = $this->save_payload( 'Attempted overwrite' );

		$resp = $this->ajax_save();

		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'decker_task_archived', $resp['code'] );
		$this->assertSame( 'Original title', get_post( $this->task_id )->post_title );
		$this->assertSame( 'archived', get_post_status( $this->task_id ) );
	}

	/**
	 * Control: the same payload saves a published task normally.
	 */
	public function test_published_task_save_still_works() {
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_id );

		$_POST = $this->save_payload( 'Updated title', $info['generation'] );

		$resp = $this->ajax_save();

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 'Updated title', get_post( $this->task_id )->post_title );
	}
}
