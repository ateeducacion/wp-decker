<?php
/**
 * Class DeckerAdminExportTest
 *
 * @package Decker
 */

class DeckerAdminExportTest extends WP_UnitTestCase {
    /**
     * @var Decker_Admin_Export
     */
    private $export_instance;

    /**
     * Set up test environment
     */
    public function set_up(): void {
        parent::set_up();
        $this->export_instance = new Decker_Admin_Export();
    }
	// /**
	// * @var Decker_Admin_Export
	// */
	// private $export_instance;

	// /**
	// * Set up test environment
	// */
	// public function set_up(): void {
	// parent::set_up();
	// $this->export_instance = new Decker_Admin_Export();
	// }

	// /**
	// * Data provider for hook testing
	// */
	// public function provide_expected_hooks() {
	// return array(
	// 'export_filters' => array( 'export_filters', 'add_export_option', 10 ),
	// 'export_wp' => array( 'export_wp', 'process_export', 10 ),
	// );
	// }

	// /**
	// * Test constructor hooks
	// *
	// * @dataProvider provide_expected_hooks
	// */
	// public function test_constructor_hooks( $hook, $callback, $priority ) {
	// $this->assertEquals(
	// $priority,
	// has_action( $hook, array( $this->export_instance, $callback ) ),
	// "Hook '$hook' not properly registered"
	// );
	// }

	// /**
	// * Data provider for custom post types
	// */
	// public function provide_expected_post_types() {
	// return array(
	// 'decker_task' => array( 'decker_task', true ),
	// 'invalid_type' => array( 'invalid_post_type', false ),
	// 'post' => array( 'post', false ),
	// );
	// }

	// /**
	// * Test custom post types getter
	// *
	// * @dataProvider provide_expected_post_types
	// */
	// public function test_get_custom_post_types( $post_type, $should_exist ) {
	// $reflection = new ReflectionClass( $this->export_instance );
	// $method = $reflection->getMethod( 'get_custom_post_types' );
	// $method->setAccessible( true );

	// $post_types = $method->invoke( $this->export_instance );

	// $this->assertIsArray( $post_types, 'Post types should be returned as array' );
	// if ( $should_exist ) {
	// $this->assertContains( $post_type, $post_types, "Post type '$post_type' should exist" );
	// } else {
	// $this->assertNotContains( $post_type, $post_types, "Post type '$post_type' should not exist" );
	// }
	// }

	// /**
	// * Test custom taxonomies getter
	// */
	// public function test_get_custom_taxonomies() {
	// $reflection = new ReflectionClass( $this->export_instance );
	// $method = $reflection->getMethod( 'get_custom_taxonomies' );
	// $method->setAccessible( true );

	// $taxonomies = $method->invoke( $this->export_instance );
	// $this->assertIsArray( $taxonomies );
	// $this->assertContains( 'decker_board', $taxonomies );
	// $this->assertContains( 'decker_action', $taxonomies );
	// $this->assertContains( 'decker_label', $taxonomies );
	// }

	// /**
	// * Test export option HTML output
	// */
	// public function test_add_export_option() {
	// ob_start();
	// $this->export_instance->add_export_option();
	// $output = ob_get_clean();

	// $this->assertStringContainsString( '<input type="radio" name="content" value="decker"', $output );
	// $this->assertStringContainsString( 'Decker', $output );
	// }

    /**
     * Test export post type functionality
     */
    public function test_export_post_type() {
        // Create a test post
        $post_id = $this->factory->post->create([
            'post_type' => 'decker_task',
            'post_title' => 'Test Task',
            'post_content' => 'Test Content',
            'post_status' => 'publish'
        ]);

        // Add test meta data
        add_post_meta($post_id, 'priority', 'high');
        add_post_meta($post_id, 'due_date', '2024-12-20');

        // Create and assign a board
        $board_term = wp_insert_term('Test Board', 'decker_board');
        wp_set_object_terms($post_id, [$board_term['term_id']], 'decker_board');

        // Create and assign a label
        $label_term = wp_insert_term('Test Label', 'decker_label');
        wp_set_object_terms($post_id, [$label_term['term_id']], 'decker_label');

        // Use reflection to access private method
        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('export_post_type');
        $method->setAccessible(true);

        // Export the post type
        $exported_posts = $method->invoke($this->export_instance, 'decker_task');

        // Assertions
        $this->assertIsArray($exported_posts);
        $this->assertCount(1, $exported_posts);

        $exported_post = $exported_posts[0];
        
        // Check basic post data
        $this->assertEquals($post_id, $exported_post['ID']);
        $this->assertEquals('Test Task', $exported_post['post_title']);
        $this->assertEquals('Test Content', $exported_post['post_content']);

        // Check meta data
        $this->assertArrayHasKey('post_meta', $exported_post);
        $this->assertEquals('high', $exported_post['post_meta']['priority'][0]);
        $this->assertEquals('2024-12-20', $exported_post['post_meta']['due_date'][0]);

        // Check taxonomies
        $this->assertNotEmpty($exported_post['decker_board']);
        $this->assertEquals('Test Board', $exported_post['decker_board'][0]->name);
        
        $this->assertNotEmpty($exported_post['decker_label']);
        $this->assertEquals('Test Label', $exported_post['decker_label'][0]->name);
    }

    /**
     * Test taxonomy terms export functionality
     */
    public function test_export_taxonomy_terms() {
        // Create a test board term
        $board_term = wp_insert_term('Test Board', 'decker_board', [
            'description' => 'Test Board Description',
            'slug' => 'test-board'
        ]);
        
        // Add board meta
        add_term_meta($board_term['term_id'], 'board_color', '#FF5733');
        add_term_meta($board_term['term_id'], 'board_order', '1');

        // Create a child board
        $child_board = wp_insert_term('Child Board', 'decker_board', [
            'description' => 'Child Board Description',
            'parent' => $board_term['term_id']
        ]);

        // Use reflection to access private method
        $reflection = new ReflectionClass($this->export_instance);
        $method = $reflection->getMethod('export_taxonomy_terms');
        $method->setAccessible(true);

        // Export the taxonomy terms
        $exported_terms = $method->invoke($this->export_instance, 'decker_board');

        // Assertions
        $this->assertIsArray($exported_terms);
        $this->assertCount(2, $exported_terms);

        // Find parent board in exported terms
        $parent_term = array_filter($exported_terms, function($term) use ($board_term) {
            return $term['term_id'] === $board_term['term_id'];
        });
        $parent_term = reset($parent_term);

        // Check basic term data
        $this->assertEquals($board_term['term_id'], $parent_term['term_id']);
        $this->assertEquals('Test Board', $parent_term['name']);
        $this->assertEquals('test-board', $parent_term['slug']);
        $this->assertEquals('Test Board Description', $parent_term['description']);

        // Check term meta
        $this->assertArrayHasKey('term_meta', $parent_term);
        $this->assertEquals('#FF5733', $parent_term['term_meta']['board_color'][0]);
        $this->assertEquals('1', $parent_term['term_meta']['board_order'][0]);

        // Find child board in exported terms
        $child_term = array_filter($exported_terms, function($term) use ($child_board) {
            return $term['term_id'] === $child_board['term_id'];
        });
        $child_term = reset($child_term);

        // Check child term data
        $this->assertEquals($child_board['term_id'], $child_term['term_id']);
        $this->assertEquals('Child Board', $child_term['name']);
        $this->assertEquals($board_term['term_id'], $child_term['parent']);
    }

	// /**
	// * Data provider for export process testing
	// */
	// public function provide_export_scenarios() {
	// return array(
	// 'valid_decker_export' => array( 'decker', true ),
	// 'invalid_content' => array( 'invalid', false ),
	// 'empty_content' => array( '', false ),
	// );
	// }

	// /**
	// * Test process export with various scenarios
	// *
	// * @dataProvider provide_export_scenarios
	// */
	// public function test_process_export( $content, $should_export ) {
	// $_GET['content'] = $content;

	// ob_start();
	// $this->export_instance->process_export( array() );
	// $output = ob_get_clean();

	// if ( $should_export ) {
	// $this->assertNotEmpty( $output );
	// $this->assertJson( $output );
	// $data = json_decode( $output, true );
	// $this->assertIsArray( $data );
	// } else {
	// $this->assertEmpty( $output );
	// }
	// }

	// /**
	// * Test export file contents
	// */
	// public function test_export_file_contents() {
	// Set up nonces
	// $_POST['decker_task_nonce'] = wp_create_nonce( 'decker_task_nonce' );
	// $_POST['decker_board_nonce'] = wp_create_nonce( 'decker_board_nonce' );
	// $_POST['decker_board_color_nonce'] = wp_create_nonce( 'decker_board_color_nonce' );

	// Create test task
	// $task_id = $this->factory->post->create(
	// array(
	// 'post_type' => 'decker_task',
	// 'post_title' => 'Test Export Task',
	// 'post_content' => 'Test Content',
	// )
	// );

	// Create test board with proper nonces
	// $_POST['decker_board_nonce'] = wp_create_nonce( 'decker_board_nonce' );
	// $_POST['decker_board_color_nonce'] = wp_create_nonce( 'decker_board_color_nonce' );

	// $board_id = $this->factory->term->create(
	// array(
	// 'taxonomy' => 'decker_board',
	// 'name' => 'Test Board',
	// )
	// );

	// Clean up nonces
	// unset( $_POST['decker_task_nonce'] );
	// unset( $_POST['decker_board_nonce'] );
	// unset( $_POST['decker_board_color_nonce'] );

	// Capture output
	// ob_start();
	// $this->export_instance->process_export( array() );
	// $output = ob_get_clean();

	// if ( ! empty( $output ) ) {
	// $exported_data = json_decode( $output, true );

	// $this->assertIsArray( $exported_data );
	// $this->assertArrayHasKey( 'decker_task', $exported_data );
	// $this->assertArrayHasKey( 'decker_board', $exported_data );

	// Verify task data
	// $exported_task = current( $exported_data['decker_task'] );
	// $this->assertEquals( 'Test Export Task', $exported_task['post_title'] );

	// Verify board data
	// $exported_board = current( $exported_data['decker_board'] );
	// $this->assertEquals( 'Test Board', $exported_board['name'] );
	// }
	// }

	// /**
	// * Clean up after each test
	// */
	// public function tear_down(): void {
	// parent::tear_down();
	// global $wp_filter;

	// Clean up created posts and terms
	// $posts = get_posts(
	// array(
	// 'post_type' => 'decker_task',
	// 'numberposts' => -1,
	// 'post_status' => 'any',
	// )
	// );
	// foreach ( $posts as $post ) {
	// wp_delete_post( $post->ID, true );
	// }

	// Clean up all custom taxonomies
	// foreach ( array( 'decker_board', 'decker_action', 'decker_label' ) as $taxonomy ) {
	// $terms = get_terms(
	// array(
	// 'taxonomy' => $taxonomy,
	// 'hide_empty' => false,
	// )
	// );
	// if ( ! is_wp_error( $terms ) ) {
	// foreach ( $terms as $term ) {
	// wp_delete_term( $term->term_id, $taxonomy );
	// }
	// }
	// }

	// Reset $_GET
	// unset( $_GET['content'] );

	// Clean up added filters/actions
	// foreach ( array( 'export_filters', 'export_wp' ) as $hook ) {
	// remove_all_actions( $hook );
	// }
	// }
}
