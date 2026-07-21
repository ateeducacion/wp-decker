<?php
/**
 * Class Test_Decker_Task_Comments
 *
 * @package Decker
 */

class DeckerTaskCommentsTest extends Decker_Test_Base {
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

		// Create users for testing using WordPress factory
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a board using our custom factory
		wp_set_current_user( $this->administrator );
		$board_result = self::factory()->board->create(
			array(
				'name'  => 'Test Comments Board',
				'color' => '#ff5733',
			)
		);

		// Verify board creation was successful
		if ( is_wp_error( $board_result ) ) {
			$this->fail( 'Failed to create board: ' . $board_result->get_error_message() );
		}
		$this->board_id = $board_result;

		// Create a test task using our custom factory
		$task_result = self::factory()->task->create(
			array(
				'post_title'  => 'Test Comments Task',
				'post_author' => $this->administrator,
				'board'       => $this->board_id,
				'stack'       => 'to-do',
			)
		);

		// Verify task creation was successful
		if ( is_wp_error( $task_result ) ) {
			$this->fail( 'Failed to create task: ' . $task_result->get_error_message() );
		}
		$this->task_id = $task_result;
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

			// Create a comment using WordPress factory
			$comment_id = self::factory()->comment->create(
				array(
					'comment_post_ID' => $this->task_id,
					'comment_content' => "Test comment from $role",
					'user_id'         => $user_id,
				)
			);

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
	 * A comment containing a URL must be rendered with a clickable link.
	 *
	 * render_comments() (used when the task is reloaded) must render URLs as
	 * clickable links, matching the REST API output used by the AJAX path.
	 * This guards against regressing back to the 'the_content' filter, which
	 * does not apply make_clickable() and leaves URLs as plain text. See #260.
	 */
	public function test_comment_url_is_rendered_as_clickable_link() {
		require_once dirname( __DIR__, 4 ) . '/public/layouts/partials/task-comments.php';

		wp_set_current_user( $this->administrator );

		$url        = 'https://example.com/path';
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => "Check this out $url please",
				'user_id'         => $this->administrator,
			)
		);

		$comment = get_comment( $comment_id );

		ob_start();
		render_comments( array( $comment ), 0, $this->administrator );
		$rendered = ob_get_clean();

		$this->assertStringContainsString(
			'href="' . $url . '"',
			$rendered,
			'Comment URL should be rendered as a clickable link on task reload.'
		);
		$this->assertStringContainsString( '<a ', $rendered, 'Rendered comment should contain an anchor tag.' );
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
