<?php
/**
 * Class Test_Decker_Task_Attachments
 *
 * @package Decker
 */

class DeckerTaskAttachmentsTest extends Decker_Test_Base {
	private int $editor;
	private int $subscriber;
	private int $task_id;
	private int $board_id;
	private $test_file;
	private $uploaded_files = array();

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that taxonomies are registered.
		do_action( 'init' );

		// $editor_role = get_role( 'editor' );
		// error_log( print_r( $editor_role, true ) );

		// Create users for testing
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Create a board using the factory
		wp_set_current_user( $this->editor );
		$board_result = self::factory()->board->create(
			array(
				'name' => 'DeckerTaskAttachmentsTest Board',
				'color' => '#ff5733',
			)
		);

		// Verify board creation was successful
		if ( is_wp_error( $board_result ) ) {
			$this->fail( 'Failed to create board: ' . $board_result->get_error_message() );
		}
		$this->board_id = $board_result;

		// Create a test task using the factory
		$task_result = self::factory()->task->create(
			array(
				'post_title' => 'Test attachment Task',
				'post_author' => $this->editor,
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		// Verify task creation was successful
		if ( is_wp_error( $task_result ) ) {
			$this->fail( 'Failed to create task: ' . $task_result->get_error_message() );
		}
		$this->task_id = $task_result;

		// Create a test file using WordPress's upload functionality
		$this->test_file = wp_upload_bits( 'test.txt', null, 'test content' );
		if ( $this->test_file['error'] ) {
			$this->fail( 'Failed to create test file: ' . $this->test_file['error'] );
		}
	}

	/**
	 * Test that an editor can add attachments to a task.
	 */
	public function test_editor_can_add_attachments() {
		wp_set_current_user( $this->editor );

		$attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
		$this->uploaded_files[] = $attachment_id;

		$this->assertNotEquals( 0, $attachment_id );
		$this->assertEquals( $this->task_id, get_post( $attachment_id )->post_parent );
		$this->assertTrue( current_user_can( 'edit_post', $attachment_id ) );
	}

	/**
	 * Test that a subscriber cannot add attachments to a task.
	 */
	public function test_subscriber_can_add_attachments() {
		wp_set_current_user( $this->subscriber );

		$attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
		$this->uploaded_files[] = $attachment_id;

		// Changed assertion: subscriber should NOT be able to add attachments
		$this->assertEquals( 0, $attachment_id );
		$this->assertFalse( current_user_can( 'upload_files' ) );
	}

	/**
	 * Test attachment permissions.
	 */
	public function test_attachment_permissions() {
		// Editor uploads an attachment
		wp_set_current_user( $this->editor );
		$editor_attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
		$this->uploaded_files[] = $editor_attachment_id;

		// Subscriber uploads an attachment
		wp_set_current_user( $this->subscriber );
		$subscriber_attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
		$this->uploaded_files[] = $subscriber_attachment_id;

		// Test editor permissions
		wp_set_current_user( $this->editor );
		$this->assertTrue( current_user_can( 'delete_post', $editor_attachment_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $subscriber_attachment_id ) );

		// Test subscriber permissions
		wp_set_current_user( $this->subscriber );
		$this->assertFalse( current_user_can( 'delete_post', $subscriber_attachment_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $editor_attachment_id ) );
	}

	/**
	 * Test attachment metadata.
	 */
	public function test_attachment_metadata() {
		wp_set_current_user( $this->editor );

		$attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
		$this->uploaded_files[] = $attachment_id;

		$attachment = get_post( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		$this->assertEquals( 'text/plain', get_post_mime_type( $attachment_id ) );
		$this->assertEquals( $this->task_id, $attachment->post_parent );
		$this->assertEquals( $this->editor, $attachment->post_author );
	}

	/**
	 * Test attachment listing.
	 */
	public function test_attachment_listing() {
		wp_set_current_user( $this->editor );

		// Upload multiple attachments
		$attachment_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$attachment_id = $this->create_attachment( $this->test_file['file'], $this->task_id );
			$attachment_ids[] = $attachment_id;
			$this->uploaded_files[] = $attachment_id;
		}

		// Get attachments for the task
		$attachments = get_posts(
			array(
				'post_type' => 'attachment',
				'post_parent' => $this->task_id,
				'numberposts' => -1,
			)
		);

		$this->assertCount( 3, $attachments );
		foreach ( $attachments as $attachment ) {
			$this->assertTrue( in_array( $attachment->ID, $attachment_ids ) );
		}
	}

	/**
	 * Helper function to create an attachment using WordPress factory.
	 */
	private function create_attachment( $file, $parent_post_id ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return 0;
		}

		$filetype = wp_check_filetype( basename( $file ), null );

		return self::factory()->attachment->create(
			array(
				'file' => $file,
				'post_mime_type' => $filetype['type'],
				'post_title' => preg_replace( '/\.[^.]+$/', '', basename( $file ) ),
				'post_parent' => $parent_post_id,
				'post_author' => get_current_user_id(),
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {

		wp_set_current_user( $this->editor );

		// Clean up uploaded files
		foreach ( $this->uploaded_files as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		// Delete the test file
		if ( file_exists( $this->test_file['file'] ) ) {
			unlink( $this->test_file['file'] );
		}

		wp_delete_post( $this->task_id, true );
		wp_delete_term( $this->board_id, 'decker_board' );
		wp_delete_user( $this->editor );
		wp_delete_user( $this->subscriber );
		parent::tear_down();
	}
}
