<?php
/**
 * Class DeckerTasksIntegrationTest
 *
 * @package Decker
 */

/**
 * @group decker
 */
class DeckerTasksIntegrationTest extends WP_UnitTestCase {
    private $editor;
    private $board_id;
    private $label_ids;
    private $assignee_ids;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Ensure that post types and taxonomies are registered
        do_action('init');
        
        // Verify taxonomies are registered
        if (!taxonomy_exists('decker_board')) {
            error_log('decker_board taxonomy does not exist!');
            throw new Exception('decker_board taxonomy is not registered');
        }
        if (!taxonomy_exists('decker_label')) {
            error_log('decker_label taxonomy does not exist!');
            throw new Exception('decker_label taxonomy is not registered');
        }

        // Create an editor user
        $this->editor = self::factory()->user->create([
            'role' => 'editor',
        ]);

        // Set current user as editor right away
        wp_set_current_user($this->editor);
        
        error_log('Setting up DeckerTasksIntegrationTest with editor ID: ' . $this->editor);
        error_log('Current user ID: ' . get_current_user_id());
        error_log('User capabilities: ' . print_r(get_userdata($this->editor)->allcaps, true));

        // Create test users for assignments
        $this->assignee_ids = [
            self::factory()->user->create(['role' => 'author']),
            self::factory()->user->create(['role' => 'author']),
        ];

        // Create a test board
        $board_term = wp_insert_term('Test Board', 'decker_board');
        if (is_wp_error($board_term)) {
            error_log('Failed to create board term: ' . $board_term->get_error_message());
            error_log('Error data: ' . print_r($board_term->get_error_data(), true));
            throw new Exception('Failed to create test board: ' . $board_term->get_error_message());
        }
        error_log('Successfully created board term with ID: ' . $board_term['term_id']);
        $this->board_id = $board_term['term_id'];

        // Create test labels
        $this->label_ids = [];
        
        error_log('Attempting to create Label 1...');
        $label1 = wp_insert_term('Label 1', 'decker_label');
        if (is_wp_error($label1)) {
            error_log('Failed to create Label 1: ' . $label1->get_error_message());
            error_log('Error data: ' . print_r($label1->get_error_data(), true));
            throw new Exception('Failed to create Label 1: ' . $label1->get_error_message());
        }
        error_log('Successfully created Label 1 with ID: ' . $label1['term_id']);
        
        error_log('Attempting to create Label 2...');
        $label2 = wp_insert_term('Label 2', 'decker_label');
        if (is_wp_error($label2)) {
            error_log('Failed to create Label 2: ' . $label2->get_error_message());
            error_log('Error data: ' . print_r($label2->get_error_data(), true));
            throw new Exception('Failed to create Label 2: ' . $label2->get_error_message());
        }
        error_log('Successfully created Label 2 with ID: ' . $label2['term_id']);
        
        $this->label_ids = [
            $label1['term_id'],
            $label2['term_id']
        ];
        error_log('Label IDs array populated: ' . print_r($this->label_ids, true));
    }

    /**
     * Clean up after each test.
     */
    private $ajax_response = null;
    
    public function capture_ajax_response() {
        ob_start();
        try {
            do_action('wp_ajax_save_decker_task');
        } catch (WPAjaxDieContinueException $e) {
            // Expected exception
        }
        $output = ob_get_clean();
        $this->ajax_response = json_decode($output, true);
        error_log('AJAX Response captured: ' . print_r($this->ajax_response, true));
    }
    
    public function tear_down() {
        // Clean up users
        wp_delete_user($this->editor);
        foreach ($this->assignee_ids as $user_id) {
            wp_delete_user($user_id);
        }

        // Clean up terms
        wp_delete_term($this->board_id, 'decker_board');
        foreach ($this->label_ids as $label_id) {
            wp_delete_term($label_id, 'decker_label');
        }

        parent::tear_down();
    }

    /**
     * Simulate AJAX task creation
     */
    private function simulate_ajax_save($data) {
        error_log('Simulating AJAX save with data: ' . print_r($data, true));
        
        // Reset any previous response
        $this->ajax_response = null;
        
        // Setup the AJAX request environment
        $_POST = array_merge([
            'action' => 'save_decker_task',
            '_ajax_nonce' => wp_create_nonce('save_decker_task'),
        ], $data);
        $_REQUEST = $_POST;
        
        // Buffer output
        ob_start();
        
        try {
            // Call the AJAX handler directly
            do_action('wp_ajax_save_decker_task');
        } catch (WPAjaxDieContinueException $e) {
            // Expected
        }
        
        // Get the response
        $response = ob_get_clean();
        
        // Parse JSON response
        $this->ajax_response = json_decode($response, true);
        
        if ($this->ajax_response === null) {
            error_log('Failed to parse AJAX response: ' . $response);
            throw new Exception('Failed to parse AJAX response');
        }
        
        return $this->ajax_response;
    }

    /**
     * Test creating a new task via AJAX
     */
    public function test_create_task() {
        error_log('Starting test_create_task');
        
        // Verify post type is registered
        $post_types = get_post_types(['name' => 'decker_task'], 'objects');
        error_log('Registered post types: ' . print_r($post_types, true));
        
        if (!post_type_exists('decker_task')) {
            throw new Exception('decker_task post type is not registered');
        }
        
        $task_data = [
            'task_id' => '',
            'title' => 'New Test Task',
            'due_date' => '2024-12-31',
            'board' => $this->board_id,
            'stack' => 'to-do',
            'author' => $this->editor,
            'assignees' => $this->assignee_ids,
            'labels' => $this->label_ids,
            'description' => '<p>Test description</p>',
            'max_priority' => 1,
        ];

        $response = $this->simulate_ajax_save($task_data);
        
        $this->assertNotNull($response, 'AJAX response should not be null');
        $this->assertArrayHasKey('success', $response, 'AJAX response should have success key');
        $this->assertTrue($response['success'], 'AJAX request should succeed');
        $this->assertArrayHasKey('task_id', $response, 'AJAX response should include task ID');
        
        // Verify the created task
        $tasks = get_posts([
            'post_type' => 'decker_task',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        $this->assertCount(1, $tasks, 'Task should be created');
        $task = $tasks[0];

        // Verify task properties
        $this->assertEquals($task_data['title'], $task->post_title);
        $this->assertEquals($task_data['description'], $task->post_content);
        
        // Verify taxonomy assignments
        $assigned_board = wp_get_post_terms($task->ID, 'decker_board', ['fields' => 'ids']);
        $this->assertEquals([$this->board_id], $assigned_board);

        $assigned_labels = wp_get_post_terms($task->ID, 'decker_label', ['fields' => 'ids']);
        sort($assigned_labels);
        sort($this->label_ids);
        $this->assertEquals($this->label_ids, $assigned_labels);

        // Verify meta data
        $this->assertEquals($task_data['stack'], get_post_meta($task->ID, 'stack', true));
        $this->assertEquals($task_data['due_date'], get_post_meta($task->ID, 'due_date', true));
        $this->assertEquals(1, get_post_meta($task->ID, 'max_priority', true));

        // Verify assignees
        $assigned_users = get_post_meta($task->ID, 'assigned_users', true);
        sort($assigned_users);
        sort($task_data['assignees']);
        $this->assertEquals($task_data['assignees'], $assigned_users);
    }

    /**
     * Test updating an existing task
     */
    public function test_update_task() {
        error_log('Starting test_update_task');
        // First create a task
        $task_id = wp_insert_post([
            'post_title' => 'Original Title',
            'post_type' => 'decker_task',
            'post_status' => 'publish',
        ]);

        // Update task data
        $update_data = [
            'task_id' => $task_id,
            'title' => 'Updated Title',
            'stack' => 'in-progress',
            'board' => $this->board_id,
            'labels' => [$this->label_ids[0]], // Only assign one label
            'assignees' => [$this->assignee_ids[0]], // Only assign one user
            'description' => '<p>Updated description</p>',
            'max_priority' => 0,
        ];

        $this->simulate_ajax_save($update_data);

        // Verify updates
        $updated_task = get_post($task_id);
        $this->assertEquals($update_data['title'], $updated_task->post_title);
        $this->assertEquals($update_data['description'], $updated_task->post_content);
        $this->assertEquals($update_data['stack'], get_post_meta($task_id, 'stack', true));
        
        // Verify updated taxonomies
        $updated_labels = wp_get_post_terms($task_id, 'decker_label', ['fields' => 'ids']);
        $this->assertEquals($update_data['labels'], $updated_labels);

        // Verify updated assignees
        $updated_assignees = get_post_meta($task_id, 'assigned_users', true);
        $this->assertEquals($update_data['assignees'], $updated_assignees);
    }

    /**
     * Test task ordering within stacks
     */
    public function test_task_ordering() {
        error_log('Starting test_task_ordering');
        // Create multiple tasks in the same stack
        $tasks = [];
        for ($i = 1; $i <= 3; $i++) {
            $tasks[] = wp_insert_post([
                'post_title' => "Task $i",
                'post_type' => 'decker_task',
                'post_status' => 'publish',
                'menu_order' => $i,
                'meta_input' => [
                    'stack' => 'to-do',
                ],
            ]);
        }

        // Verify initial order
        $ordered_tasks = get_posts([
            'post_type' => 'decker_task',
            'meta_key' => 'stack',
            'meta_value' => 'to-do',
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $this->assertEquals($tasks, $ordered_tasks, 'Tasks should maintain their order');

        // Move middle task to a different stack
        $update_data = [
            'task_id' => $tasks[1],
            'stack' => 'in-progress',
        ];

        $this->simulate_ajax_save($update_data);

        // Verify to-do stack reordering
        $todo_tasks = get_posts([
            'post_type' => 'decker_task',
            'meta_key' => 'stack',
            'meta_value' => 'to-do',
            'orderby' => 'menu_order',
            'order' => 'ASC',
            'fields' => 'ids',
        ]);

        $this->assertEquals([$tasks[0], $tasks[2]], $todo_tasks, 'Remaining tasks should maintain relative order');

        // Verify task in new stack
        $in_progress_tasks = get_posts([
            'post_type' => 'decker_task',
            'meta_key' => 'stack',
            'meta_value' => 'in-progress',
            'fields' => 'ids',
        ]);

        $this->assertEquals([$tasks[1]], $in_progress_tasks, 'Task should be in new stack');
    }

    /**
     * Test deleting tasks
     */
    public function test_delete_tasks() {
        error_log('Starting test_delete_tasks');
        // Create tasks in different stacks
        $task_ids = [];
        $stacks = ['to-do', 'in-progress', 'done'];
        
        foreach ($stacks as $i => $stack) {
            $task_ids[$stack] = wp_insert_post([
                'post_title' => "Task in $stack",
                'post_type' => 'decker_task',
                'post_status' => 'publish',
                'menu_order' => $i + 1,
                'meta_input' => [
                    'stack' => $stack,
                ],
            ]);
        }

        // Delete task from in-progress
        wp_delete_post($task_ids['in-progress'], true);

        // Verify remaining tasks and their order
        foreach (['to-do', 'done'] as $stack) {
            $remaining_tasks = get_posts([
                'post_type' => 'decker_task',
                'meta_key' => 'stack',
                'meta_value' => $stack,
                'fields' => 'ids',
            ]);

            $this->assertEquals([$task_ids[$stack]], $remaining_tasks, "Task in $stack should remain");
        }

        // Verify deleted task
        $deleted_task = get_post($task_ids['in-progress']);
        $this->assertNull($deleted_task, 'Task should be completely deleted');
    }
}
