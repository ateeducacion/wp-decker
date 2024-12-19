<?php
/**
 * Class Decker_Notification_Handler_Test
 *
 * @package Decker
 */

/**
 * Test notification handling functionality
 */
class Decker_Notification_Handler_Test extends WP_UnitTestCase {
    private $notification_handler;
    private $test_user;
    private $test_task;

    public function setUp() {
        parent::setUp();
        
        // Create a test user
        $this->test_user = $this->factory->user->create(array(
            'role' => 'author',
            'user_email' => 'test@example.com'
        ));

        // Create a test task
        $this->test_task = $this->factory->post->create(array(
            'post_type' => 'decker_task',
            'post_title' => 'Test Task',
            'post_status' => 'publish'
        ));

        // Enable email notifications in settings
        update_option('decker_settings', array(
            'enable_email_notifications' => true
        ));

        $this->notification_handler = new Decker_Notification_Handler();
    }

    public function tearDown() {
        wp_delete_post($this->test_task, true);
        wp_delete_user($this->test_user);
        delete_option('decker_settings');
        parent::tearDown();
    }

    public function test_notifications_disabled() {
        update_option('decker_settings', array(
            'enable_email_notifications' => false
        ));

        // Mock the mailer
        $mock_mailer = $this->getMockBuilder('Decker_Mailer')
                           ->getMock();
        
        $mock_mailer->expects($this->never())
                    ->method('send_email');

        $this->notification_handler->mailer = $mock_mailer;

        // Trigger notifications
        do_action('decker_task_assigned', $this->test_task, $this->test_user);
        do_action('decker_task_completed', $this->test_task, 1);
        do_action('decker_task_comment_added', $this->test_task, 1, 1);
    }

    public function test_task_assigned_notification() {
        // Set user preferences
        update_user_meta($this->test_user, 'decker_notification_preferences', array(
            'notify_assigned' => true
        ));

        // Mock the mailer
        $mock_mailer = $this->getMockBuilder('Decker_Mailer')
                           ->getMock();
        
        $mock_mailer->expects($this->once())
                    ->method('send_email')
                    ->with(
                        $this->equalTo('test@example.com'),
                        $this->stringContains('New Task Assigned'),
                        $this->stringContains('Test Task')
                    );

        $this->notification_handler->mailer = $mock_mailer;

        // Trigger assignment notification
        do_action('decker_task_assigned', $this->test_task, $this->test_user);
    }

    public function test_task_completed_notification() {
        // Set user preferences
        update_user_meta($this->test_user, 'decker_notification_preferences', array(
            'notify_completed' => true
        ));

        // Assign task to test user
        update_post_meta($this->test_task, 'assigned_to', $this->test_user);

        // Mock the mailer
        $mock_mailer = $this->getMockBuilder('Decker_Mailer')
                           ->getMock();
        
        $mock_mailer->expects($this->once())
                    ->method('send_email')
                    ->with(
                        $this->equalTo('test@example.com'),
                        $this->stringContains('Task Completed'),
                        $this->stringContains('Test Task')
                    );

        $this->notification_handler->mailer = $mock_mailer;

        // Trigger completion notification
        do_action('decker_task_completed', $this->test_task, 1);
    }

    public function test_task_comment_notification() {
        // Set user preferences
        update_user_meta($this->test_user, 'decker_notification_preferences', array(
            'notify_comments' => true
        ));

        // Assign task to test user
        update_post_meta($this->test_task, 'assigned_to', $this->test_user);

        // Create a test comment
        $comment_id = $this->factory->comment->create(array(
            'comment_post_ID' => $this->test_task,
            'comment_content' => 'Test comment'
        ));

        // Mock the mailer
        $mock_mailer = $this->getMockBuilder('Decker_Mailer')
                           ->getMock();
        
        $mock_mailer->expects($this->once())
                    ->method('send_email')
                    ->with(
                        $this->equalTo('test@example.com'),
                        $this->stringContains('New Comment'),
                        $this->stringContains('Test Task')
                    );

        $this->notification_handler->mailer = $mock_mailer;

        // Trigger comment notification
        do_action('decker_task_comment_added', $this->test_task, $comment_id, 1);
    }

    public function test_no_self_notifications() {
        // Set current user as the test user
        wp_set_current_user($this->test_user);

        // Mock the mailer
        $mock_mailer = $this->getMockBuilder('Decker_Mailer')
                           ->getMock();
        
        $mock_mailer->expects($this->never())
                    ->method('send_email');

        $this->notification_handler->mailer = $mock_mailer;

        // Trigger notifications for actions by the same user
        do_action('decker_task_assigned', $this->test_task, $this->test_user);
        do_action('decker_task_completed', $this->test_task, $this->test_user);
        do_action('decker_task_comment_added', $this->test_task, 1, $this->test_user);
    }
}
