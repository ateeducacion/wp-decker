<?php
/**
 * Class Test_Decker_Admin
 *
 * @package Decker
 */

require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';


class Test_Decker_Admin extends WP_UnitTestCase {
	protected $admin;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

		// Mock admin context
		set_current_screen( 'edit-post' );

		// Create admin user and log in
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->admin = new Decker_Admin( 'decker', '1.0.0' );
	}

	public function test_constructor() {
		$this->assertInstanceOf( Decker_Admin::class, $this->admin );
		$this->assertEquals( 100, has_action( 'admin_bar_menu', array( $this->admin, 'add_admin_bar_link' ) ) );
		$this->assertEquals( 10, has_filter( 'plugin_action_links_' . plugin_basename( DECKER_PLUGIN_FILE ), array( $this->admin, 'add_settings_link' ) ) );
	}

	public function test_add_settings_link() {
		$links = array();
		$new_links = $this->admin->add_settings_link( $links );

		$this->assertIsArray( $new_links );
		$this->assertCount( 1, $new_links );
		$this->assertStringContainsString( 'options-general.php?page=decker_settings', $new_links[0] );
		$this->assertStringContainsString( 'Settings', $new_links[0] );
	}

	public function test_add_admin_bar_link() {
		// Create a mock that matches WP_Admin_Bar's actual structure
		$admin_bar = $this->getMockBuilder( 'WP_Admin_Bar' )
			->disableOriginalConstructor()
			->onlyMethods( array( 'add_node' ) )
			->getMock();

		// Set up expectations for add_node method
		$admin_bar->expects( $this->once() )
			->method( 'add_node' )
			->with(
				$this->callback(
					function ( $args ) {
						return $args['id'] === 'decker_frontend_link' &&
						  strpos( $args['title'], 'Go to Frontend' ) !== false &&
						  $args['href'] === home_url( '/?decker_page=priority' );
					}
				)
			);

		// Test with admin capabilities
		$this->admin->add_admin_bar_link( $admin_bar );
	}

	public function test_enqueue_styles() {
		// Limpia cualquier enqueued style previo
		wp_dequeue_style( 'decker' );

		// Test con hook no coincidente
		$this->admin->enqueue_styles( 'wrong_hook' );
		$this->assertFalse( wp_style_is( 'decker', 'enqueued' ) );

		// Test con hook coincidente
		$this->admin->enqueue_styles( 'settings_page_decker_settings' );
		$this->assertTrue( wp_style_is( 'decker', 'enqueued' ) );
	}

	public function test_enqueue_scripts() {
		// Limpia cualquier enqueued script previo
		wp_dequeue_script( 'decker' );

		// Test con hook no coincidente
		$this->admin->enqueue_scripts( 'wrong_hook' );
		$this->assertFalse( wp_script_is( 'decker', 'enqueued' ) );

		// Test con hook coincidente
		$this->admin->enqueue_scripts( 'settings_page_decker_settings' );
		$this->assertTrue( wp_script_is( 'decker', 'enqueued' ) );
	}


	public function test_load_dependencies() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'load_dependencies' );
		$method->setAccessible( true );

		// Call the method again to test multiple loads
		$method->invoke( $this->admin );

		$this->assertTrue( class_exists( 'Decker_Admin_Settings' ) );
		$this->assertTrue( class_exists( 'Decker_Admin_Export' ) );
		$this->assertTrue( class_exists( 'Decker_Admin_Import' ) );
	}

	public function tear_down() {
		parent::tear_down();
		wp_set_current_user( 0 );
	}
}
