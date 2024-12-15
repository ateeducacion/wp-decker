<?php
/**
 * Class Test_Decker_Task_Comments
 *
 * @package Decker
 */

class DeckerTaskCommentsTest extends WP_UnitTestCase {
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
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a board for the task
		wp_set_current_user( $this->editor );
		$board = wp_insert_term( 'Test Board', 'decker_board' );
		$this->board_id = $board['term_id'];

		// Create a test task
		$this->task_id = wp_insert_post(
			array(
				'post_type' => 'decker_task',
				'post_title' => 'Test Task',
				'post_status' => 'publish',
				'post_author' => $this->editor,
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
	 * Test that an editor can add comments to a task.
	 */
	public function test_editor_can_add_comments() {
		wp_set_current_user( $this->editor );

		$comment_data = array(
			'comment_post_ID' => $this->task_id,
			'comment_content' => 'Test comment from editor',
			'user_id' => $this->editor,
			'comment_type' => 'decker_task_comment',
		);

		$comment_id = wp_insert_comment( $comment_data );

		$this->assertNotEquals( 0, $comment_id );
		$this->assertNotFalse( $comment_id );

		$comment = get_comment( $comment_id );
		$this->assertEquals( 'Test comment from editor', $comment->comment_content );
	}

	/**
	 * Test that a subscriber can add comments to a task.
	 */
	public function test_subscriber_can_add_comments() {
		wp_set_current_user( $this->subscriber );

		$comment_data = array(
			'comment_post_ID' => $this->task_id,
			'comment_content' => 'Test comment from subscriber',
			'user_id' => $this->subscriber,
			'comment_type' => 'decker_task_comment',
		);

		$comment_id = wp_insert_comment( $comment_data );

		$this->assertNotEquals( 0, $comment_id );
		$this->assertNotFalse( $comment_id );

		$comment = get_comment( $comment_id );
		$this->assertEquals( 'Test comment from subscriber', $comment->comment_content );
	}

	/**
	 * Test that users can only edit their own comments.
	 */
	public function test_users_can_only_edit_own_comments() {
		// Editor creates a comment
		wp_set_current_user( $this->editor );
		$editor_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Editor\'s comment',
				'user_id' => $this->editor,
				'comment_type' => 'decker_task_comment',
			)
		);

		// Subscriber creates a comment
		wp_set_current_user( $this->subscriber );
		$subscriber_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Subscriber\'s comment',
				'user_id' => $this->subscriber,
				'comment_type' => 'decker_task_comment',
			)
		);

		// Test editor can edit their own comment
		wp_set_current_user( $this->editor );
		$this->assertTrue( current_user_can( 'edit_comment', $editor_comment_id ) );

		// Test editor cannot edit subscriber's comment
		$this->assertFalse( current_user_can( 'edit_comment', $subscriber_comment_id ) );

		// Test subscriber can edit their own comment
		wp_set_current_user( $this->subscriber );
		$this->assertTrue( current_user_can( 'edit_comment', $subscriber_comment_id ) );

		// Test subscriber cannot edit editor's comment
		$this->assertFalse( current_user_can( 'edit_comment', $editor_comment_id ) );
	}

	/**
	 * Test comment deletion permissions.
	 */
	public function test_comment_deletion_permissions() {
		// Editor creates a comment
		wp_set_current_user( $this->editor );
		$editor_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Editor\'s comment',
				'user_id' => $this->editor,
				'comment_type' => 'decker_task_comment',
			)
		);

		// Test editor can delete their own comment
		$this->assertTrue( current_user_can( 'delete_comment', $editor_comment_id ) );

		// Subscriber creates a comment
		wp_set_current_user( $this->subscriber );
		$subscriber_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Subscriber\'s comment',
				'user_id' => $this->subscriber,
				'comment_type' => 'decker_task_comment',
			)
		);

		// Test subscriber cannot delete editor's comment
		$this->assertFalse( current_user_can( 'delete_comment', $editor_comment_id ) );

		// Test subscriber can delete their own comment
		$this->assertTrue( current_user_can( 'delete_comment', $subscriber_comment_id ) );
	}

	/**
	 * Test comment threading functionality.
	 */
	public function test_comment_threading() {
		wp_set_current_user( $this->editor );

		// Create parent comment
		$parent_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Parent comment',
				'user_id' => $this->editor,
				'comment_type' => 'decker_task_comment',
			)
		);

		// Create reply to parent comment
		$reply_comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->task_id,
				'comment_content' => 'Reply comment',
				'user_id' => $this->editor,
				'comment_parent' => $parent_comment_id,
				'comment_type' => 'decker_task_comment',
			)
		);

		$reply_comment = get_comment( $reply_comment_id );
		$this->assertEquals( $parent_comment_id, $reply_comment->comment_parent );

		// Test getting threaded comments
		$comments = get_comments(
			array(
				'post_id' => $this->task_id,
				'hierarchical' => 'threaded',
			)
		);

		$this->assertCount( 2, $comments );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_post( $this->task_id, true );
		wp_delete_term( $this->board_id, 'decker_board' );
		wp_delete_user( $this->editor );
		wp_delete_user( $this->subscriber );
		parent::tear_down();
	}
}
