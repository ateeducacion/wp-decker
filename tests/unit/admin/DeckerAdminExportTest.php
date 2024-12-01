<?php
/**
 * Class Test_Decker_Admin_Export
 *
 * @package Decker
 */

class DeckerAdminExportTest extends WP_UnitTestCase {
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

	// /**
	// * Test export post type functionality
	// */
	// public function test_export_post_type() {
	// Set up nonce
	// $_POST['decker_task_nonce'] = wp_create_nonce( 'decker_task_nonce' );

	// Create a test post
	// $post_id = $this->factory->post->create(
	// array(
	// 'post_type' => 'decker_task',
	// 'post_title' => 'Test Task',
	// 'post_content' => 'Test Content',
	// )
	// );

	// Clean up nonce
	// unset( $_POST['decker_task_nonce'] );

	// Add some test meta
	// add_post_meta( $post_id, 'test_meta_key', 'test_meta_value' );

	// $reflection = new ReflectionClass( $this->export_instance );
	// $method = $reflection->getMethod( 'export_post_type' );
	// $method->setAccessible( true );

	// $exported_posts = $method->invoke( $this->export_instance, 'decker_task' );

	// $this->assertIsArray( $exported_posts );
	// $this->assertNotEmpty( $exported_posts );

	// $exported_post = $exported_posts[0];
	// $this->assertEquals( $post_id, $exported_post['ID'] );
	// $this->assertEquals( 'Test Task', $exported_post['post_title'] );
	// $this->assertArrayHasKey( 'post_meta', $exported_post );
	// }

	// /**
	// * Test taxonomy terms export functionality
	// */
	// public function test_export_taxonomy_terms() {
	// Set up nonces for board creation
	// $_POST['decker_board_nonce'] = wp_create_nonce( 'decker_board_nonce' );
	// $_POST['decker_board_color_nonce'] = wp_create_nonce( 'decker_board_color_nonce' );

	// Create a test term
	// $term_id = $this->factory->term->create(
	// array(
	// 'taxonomy' => 'decker_board',
	// 'name' => 'Test Board',
	// 'slug' => 'test-board',
	// 'description' => 'Test Description',
	// )
	// );

	// Clean up nonces
	// unset( $_POST['decker_board_nonce'] );
	// unset( $_POST['decker_board_color_nonce'] );

	// Add some term meta
	// add_term_meta( $term_id, 'test_term_meta', 'test_value' );

	// $reflection = new ReflectionClass( $this->export_instance );
	// $method = $reflection->getMethod( 'export_taxonomy_terms' );
	// $method->setAccessible( true );

	// $exported_terms = $method->invoke( $this->export_instance, 'decker_board' );

	// $this->assertIsArray( $exported_terms );
	// $this->assertNotEmpty( $exported_terms );

	// $exported_term = $exported_terms[0];
	// $this->assertEquals( $term_id, $exported_term['term_id'] );
	// $this->assertEquals( 'Test Board', $exported_term['name'] );
	// $this->assertArrayHasKey( 'term_meta', $exported_term );
	// }

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
