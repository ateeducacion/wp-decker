<?php
/**
 * Class Test_Decker_Task_Attachments
 *
 * @package Decker
 */

class DeckerTaskAttachmentsTest extends WP_UnitTestCase {
    private $editor;
    private $subscriber;
    private $task_id;
    private $board_id;
    private $test_file;
    private $uploaded_files = array();

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Create users for testing
        $this->editor = self::factory()->user->create(array('role' => 'editor'));
        $this->subscriber = self::factory()->user->create(array('role' => 'subscriber'));

        // Create a board for the task
        wp_set_current_user($this->editor);
        $board = wp_insert_term('Test Board', 'decker_board');
        $this->board_id = $board['term_id'];

        // Create a test task
        $this->task_id = wp_insert_post(array(
            'post_type' => 'decker_task',
            'post_title' => 'Test Task',
            'post_status' => 'publish',
            'post_author' => $this->editor,
            'tax_input' => array(
                'decker_board' => array($this->board_id)
            ),
            'meta_input' => array(
                'stack' => 'to-do'
            )
        ));

        // Create a test file
        $this->test_file = wp_upload_bits('test.txt', null, 'test content');
    }

    /**
     * Test that an editor can add attachments to a task.
     */
    public function test_editor_can_add_attachments() {
        wp_set_current_user($this->editor);

        $attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
        $this->uploaded_files[] = $attachment_id;

        $this->assertNotEquals(0, $attachment_id);
        $this->assertEquals($this->task_id, get_post($attachment_id)->post_parent);
        $this->assertTrue(current_user_can('edit_post', $attachment_id));
    }

    /**
     * Test that a subscriber can add attachments to a task.
     */
    public function test_subscriber_can_add_attachments() {
        wp_set_current_user($this->subscriber);

        $attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
        $this->uploaded_files[] = $attachment_id;

        $this->assertNotEquals(0, $attachment_id);
        $this->assertEquals($this->task_id, get_post($attachment_id)->post_parent);
        $this->assertTrue(current_user_can('edit_post', $attachment_id));
    }

    /**
     * Test attachment permissions.
     */
    public function test_attachment_permissions() {
        // Editor uploads an attachment
        wp_set_current_user($this->editor);
        $editor_attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
        $this->uploaded_files[] = $editor_attachment_id;

        // Subscriber uploads an attachment
        wp_set_current_user($this->subscriber);
        $subscriber_attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
        $this->uploaded_files[] = $subscriber_attachment_id;

        // Test editor permissions
        wp_set_current_user($this->editor);
        $this->assertTrue(current_user_can('delete_post', $editor_attachment_id));
        $this->assertFalse(current_user_can('delete_post', $subscriber_attachment_id));

        // Test subscriber permissions
        wp_set_current_user($this->subscriber);
        $this->assertTrue(current_user_can('delete_post', $subscriber_attachment_id));
        $this->assertFalse(current_user_can('delete_post', $editor_attachment_id));
    }

    /**
     * Test attachment metadata.
     */
    public function test_attachment_metadata() {
        wp_set_current_user($this->editor);
        
        $attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
        $this->uploaded_files[] = $attachment_id;

        $attachment = get_post($attachment_id);
        $metadata = wp_get_attachment_metadata($attachment_id);

        $this->assertEquals('text/plain', get_post_mime_type($attachment_id));
        $this->assertEquals($this->task_id, $attachment->post_parent);
        $this->assertEquals($this->editor, $attachment->post_author);
    }

    /**
     * Test attachment listing.
     */
    public function test_attachment_listing() {
        wp_set_current_user($this->editor);

        // Upload multiple attachments
        $attachment_ids = array();
        for ($i = 0; $i < 3; $i++) {
            $attachment_id = $this->create_attachment($this->test_file['file'], $this->task_id);
            $attachment_ids[] = $attachment_id;
            $this->uploaded_files[] = $attachment_id;
        }

        // Get attachments for the task
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'post_parent' => $this->task_id,
            'numberposts' => -1
        ));

        $this->assertCount(3, $attachments);
        foreach ($attachments as $attachment) {
            $this->assertTrue(in_array($attachment->ID, $attachment_ids));
        }
    }

    /**
     * Helper function to create an attachment.
     */
    private function create_attachment($file, $parent_post_id) {
        $filetype = wp_check_filetype(basename($file), null);
        
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file)),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_parent' => $parent_post_id
        );

        return wp_insert_attachment($attachment, $file, $parent_post_id);
    }

    /**
     * Clean up after each test.
     */
    public function tear_down() {
        // Clean up uploaded files
        foreach ($this->uploaded_files as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }

        // Delete the test file
        if (file_exists($this->test_file['file'])) {
            unlink($this->test_file['file']);
        }

        wp_delete_post($this->task_id, true);
        wp_delete_term($this->board_id, 'decker_board');
        wp_delete_user($this->editor);
        wp_delete_user($this->subscriber);
        parent::tear_down();
    }
}
