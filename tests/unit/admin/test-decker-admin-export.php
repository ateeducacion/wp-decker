<?php
/**
 * Class Test_Decker_Admin_Export
 *
 * @package Decker
 */

class Test_Decker_Admin_Export extends WP_UnitTestCase {
    /**
     * @var Decker_Admin_Export
     */
    private $export_instance;

    /**
     * Set up test environment
     */
    public function setUp(): void {
        parent::setUp();
        $this->export_instance = new Decker_Admin_Export();
    }

    /**
     * Test constructor hooks
     */
    public function test_constructor_hooks() {
        $this->assertEquals(10, has_action('export_filters', array($this->export_instance, 'add_export_option')));
        $this->assertEquals(10, has_action('export_wp', array($this->export_instance, 'process_export')));
    }

    /**
     * Test custom post types getter
     */
    public function test_get_custom_post_types() {
        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('get_custom_post_types');
        $method->setAccessible(true);

        $post_types = $method->invoke($this->export_instance);
        $this->assertIsArray($post_types);
        $this->assertContains('decker_task', $post_types);
    }

    /**
     * Test custom taxonomies getter
     */
    public function test_get_custom_taxonomies() {
        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('get_custom_taxonomies');
        $method->setAccessible(true);

        $taxonomies = $method->invoke($this->export_instance);
        $this->assertIsArray($taxonomies);
        $this->assertContains('decker_board', $taxonomies);
        $this->assertContains('decker_action', $taxonomies);
        $this->assertContains('decker_label', $taxonomies);
    }

    /**
     * Test export option HTML output
     */
    public function test_add_export_option() {
        ob_start();
        $this->export_instance->add_export_option();
        $output = ob_get_clean();

        $this->assertStringContainsString('<input type="radio" name="content" value="decker"', $output);
        $this->assertStringContainsString('Decker', $output);
    }

    /**
     * Test export post type functionality
     */
    public function test_export_post_type() {
        // Create a test post
        $post_id = $this->factory->post->create(array(
            'post_type' => 'decker_task',
            'post_title' => 'Test Task',
            'post_content' => 'Test Content'
        ));

        // Add some test meta
        add_post_meta($post_id, 'test_meta_key', 'test_meta_value');

        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('export_post_type');
        $method->setAccessible(true);

        $exported_posts = $method->invoke($this->export_instance, 'decker_task');

        $this->assertIsArray($exported_posts);
        $this->assertNotEmpty($exported_posts);
        
        $exported_post = $exported_posts[0];
        $this->assertEquals($post_id, $exported_post['ID']);
        $this->assertEquals('Test Task', $exported_post['post_title']);
        $this->assertArrayHasKey('post_meta', $exported_post);
    }

    /**
     * Test taxonomy terms export functionality
     */
    public function test_export_taxonomy_terms() {
        // Create a test term
        $term_id = $this->factory->term->create(array(
            'taxonomy' => 'decker_board',
            'name' => 'Test Board',
            'slug' => 'test-board',
            'description' => 'Test Description'
        ));

        // Add some term meta
        add_term_meta($term_id, 'test_term_meta', 'test_value');

        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('export_taxonomy_terms');
        $method->setAccessible(true);

        $exported_terms = $method->invoke($this->export_instance, 'decker_board');

        $this->assertIsArray($exported_terms);
        $this->assertNotEmpty($exported_terms);
        
        $exported_term = $exported_terms[0];
        $this->assertEquals($term_id, $exported_term['term_id']);
        $this->assertEquals('Test Board', $exported_term['name']);
        $this->assertArrayHasKey('term_meta', $exported_term);
    }

    /**
     * Test process export with mock data
     */
    public function test_process_export() {
        $_GET['content'] = 'decker';
        
        // Mock the create_backup method
        $mock = $this->getMockBuilder(Decker_Admin_Export::class)
                     ->setMethods(['create_backup'])
                     ->getMock();
        
        $mock->expects($this->once())
             ->method('create_backup');
             
        $mock->process_export(array());
    }

    /**
     * Clean up after each test
     */
    public function tearDown(): void {
        parent::tearDown();
        // Clean up created posts and terms
        $posts = get_posts(array('post_type' => 'decker_task', 'numberposts' => -1));
        foreach ($posts as $post) {
            wp_delete_post($post->ID, true);
        }
        
        $terms = get_terms(array('taxonomy' => 'decker_board', 'hide_empty' => false));
        foreach ($terms as $term) {
            wp_delete_term($term->term_id, 'decker_board');
        }
    }
}
