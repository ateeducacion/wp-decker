<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Main plugin test case.
 */
class DeckerTest extends Decker_Test_Base {
	protected $decker;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

           // Force the initialization of taxonomies and roles
		do_action( 'init' );

               // Create an administrator user for the tests
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

               // Instantiate the plugin
		$this->decker = new Decker();
	}

	public function test_plugin_initialization() {
		$this->assertInstanceOf( Decker::class, $this->decker );
		$this->assertEquals( 'decker', $this->decker->get_plugin_name() );
		$this->assertEquals( DECKER_VERSION, $this->decker->get_version() );
	}

	public function test_plugin_dependencies() {
           // Verify that the loader exists and is properly instantiated
		$loader = $this->get_private_property( $this->decker, 'loader' );
		$this->assertInstanceOf( 'Decker_Loader', $loader );

           // Verify that the required properties are set
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'plugin_name' ) );
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'version' ) );
	}

	/**
	 * Helper method to access private properties
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * Test current_user_has_at_least_minimum_role.
	 */
	public function test_current_user_has_at_least_minimum_role() {
               // Configure Decker settings
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'editor' ) );

           // Create users with standard roles
		$admin      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

               // Test with an administrator
		wp_set_current_user( $admin );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Administrator should have editor access.' );

               // Test with an editor
		wp_set_current_user( $editor );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Editor should have editor access.' );

               // Test with a subscriber
		wp_set_current_user( $subscriber );
		$this->assertFalse( Decker::current_user_has_at_least_minimum_role(), 'Subscriber should not have editor access.' );

               // Restore the current user
		wp_set_current_user( 0 );
	}

	public function tear_down() {
               // Clean up data
		wp_delete_user( $this->admin_user_id );
		delete_option( 'decker_settings' );
		parent::tear_down();
	}
}
