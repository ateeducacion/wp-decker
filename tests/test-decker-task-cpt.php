<?php
/**
 * Class Test_Decker_Task_CPT
 *
 * @package Decker
 */

class Test_Decker_Task_CPT extends WP_UnitTestCase {
    protected $post_type = 'decker_task';

    public function set_up() {
        parent::set_up();
        
        // Ensure the CPT is registered
        do_action('init');
    }

    public function test_cpt_exists() {
        $this->assertTrue(post_type_exists($this->post_type));
    }

    public function test_cpt_labels() {
        $post_type_object = get_post_type_object($this->post_type);
        
        $this->assertEquals('Decker Task', $post_type_object->labels->singular_name);
        $this->assertEquals('Decker Tasks', $post_type_object->labels->name);
    }

    public function test_create_task() {
        $task_data = array(
            'post_title' => 'Test Task',
            'post_content' => 'Task Description',
            'post_status' => 'publish',
            'post_type' => $this->post_type
        );

        $task_id = wp_insert_post($task_data);
        
        $this->assertNotEquals(0, $task_id);
        $this->assertEquals('Test Task', get_post($task_id)->post_title);
    }

    public function test_task_capabilities() {
        $post_type_object = get_post_type_object($this->post_type);
        
        $this->assertTrue($post_type_object->public);
        $this->assertTrue($post_type_object->show_ui);
        $this->assertTrue($post_type_object->show_in_menu);
        $this->assertTrue($post_type_object->show_in_rest);
    }
}
