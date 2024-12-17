<?php
/**
 * Class Test_Decker_Task_Comments
 *
 * @package Decker
 */

class DeckerTaskCommentsTest extends WP_UnitTestCase {
	private $administrator;
	private $editor;
	private $subscriber;
	private $task_id;
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create users for testing
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a board for the task
		wp_set_current_user( $this->administrator );
		$board = wp_insert_term( 'Test Board', 'decker_board' );
		$this->board_id = $board['term_id'];

		// Create a test task
		$this->task_id = wp_insert_post(
			array(
				'post_type' => 'decker_task',
				'post_title' => 'Test Task',
				'post_status' => 'publish',
				'post_author' => $this->administrator,
				'tax_input' => array(
					'decker_board' => array( $this->board_id ),
				),
				'meta_input' => array(
					'stack' => 'to-do',
				),
			)
		);
	}

	/**
	 * Test creating, editing, and deleting comments by each role.
	 */
	public function test_comments_by_roles() {
		$roles = array(
			'administrator' => $this->administrator,
			'editor'        => $this->editor,
			'subscriber'    => $this->subscriber,
		);

		foreach ( $roles as $role => $user_id ) {
			wp_set_current_user( $user_id );

			// Create a comment
			$comment_data = array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => "Test comment from $role",
				'user_id'         => $user_id,
				'comment_type'    => 'decker_task_comment',
			);

			$comment_id = wp_insert_comment( $comment_data );

			$this->assertNotEquals( 0, $comment_id, "Failed to create comment for role: $role" );
			$this->assertNotFalse( $comment_id, "Failed to create comment for role: $role" );

			$comment = get_comment( $comment_id );
			$this->assertEquals( "Test comment from $role", $comment->comment_content, "Incorrect content for role: $role" );

			// Edit the comment
			$comment->comment_content = "Edited comment by $role";
			wp_update_comment( (array) $comment );

			$updated_comment = get_comment( $comment_id );
			$this->assertEquals( "Edited comment by $role", $updated_comment->comment_content, "Failed to update comment for role: $role" );

			// Delete the comment
			$deleted = wp_delete_comment( $comment_id, true );
			$this->assertTrue( $deleted, "Failed to delete comment for role: $role" );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_set_current_user( $this->administrator );
		wp_delete_post( $this->task_id, true );
		wp_delete_term( $this->board_id, 'decker_board' );
		wp_delete_user( $this->editor );
		wp_delete_user( $this->subscriber );
		wp_delete_user( $this->administrator );
		parent::tear_down();
	}
}
