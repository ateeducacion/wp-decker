<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Main plugin test case.
 */
class Test_Decker extends WP_UnitTestCase {
	protected $decker;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

		// Mock that we're in WP Admin context
		set_current_screen( 'edit-post' );

		// Create admin user for testing
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->decker = new Decker();
	}

	public function test_plugin_initialization() {
		$this->assertInstanceOf( Decker::class, $this->decker );
		$this->assertEquals( 'decker', $this->decker->get_plugin_name() );
		$this->assertEquals( DECKER_VERSION, $this->decker->get_version() );
	}

	public function test_plugin_dependencies() {
		// Verify loader exists and is properly instantiated
		$loader = $this->get_private_property( $this->decker, 'loader' );
		$this->assertInstanceOf( 'Decker_Loader', $loader );

		// Verify required properties are set
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'plugin_name' ) );
		$this->assertNotEmpty( $this->get_private_property( $this->decker, 'version' ) );
	}

	public function test_hooks_registration() {
		$loader = $this->get_private_property( $this->decker, 'loader' );
		$actions = $this->get_private_property( $loader, 'actions' );
		$filters = $this->get_private_property( $loader, 'filters' );

		// Test actions registration
		$this->assertIsArray( $actions );
		$this->assertNotEmpty( $actions );

		// Verify specific actions are registered
		$this->assertContains(
			array(
				'hook' => 'init',
				'component' => $this->decker,
				'callback' => 'register_role',
				'priority' => 10,
				'accepted_args' => 1,
			),
			$actions
		);

		// Test filters registration
		$this->assertIsArray( $filters );

		// Verify specific filters are registered
		$this->assertContains(
			array(
				'hook' => 'map_meta_cap',
				'component' => $this->decker,
				'callback' => 'restrict_comment_editing_to_author',
				'priority' => 10,
				'accepted_args' => 3,
			),
			$filters
		);
	}

	public function test_role_registration() {
		// Test role creation
		$this->decker->register_role();
		$role = get_role( 'decker_role' );

		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertFalse( $role->has_cap( 'delete_posts' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
	}

	public function test_admin_capabilities() {
		// Test admin capabilities
		$this->decker->add_caps_to_admin();
		$admin_role = get_role( 'administrator' );

		$this->assertTrue( $admin_role->has_cap( 'manage_decker_tasks' ) );
	}

	public function test_comment_capabilities() {
		// Create a test task and comment
		$task = $this->factory->post->create(
			array(
				'post_type' => 'decker_task',
				'post_author' => $this->admin_user_id,
			)
		);

		$comment = $this->factory->comment->create(
			array(
				'comment_post_ID' => $task,
				'user_id' => $this->admin_user_id,
			)
		);

		// Test comment editing restriction
		$caps = $this->decker->restrict_comment_editing_to_author(
			array( 'edit_comment' => true ),
			array( 'edit_comment' ),
			array( $this->admin_user_id, 'edit_comment', $comment )
		);

		$this->assertTrue( $caps['edit_comment'] );

		// Test with different user
		$other_user_id = $this->factory->user->create( array( 'role' => 'decker_role' ) );
		$caps = $this->decker->restrict_comment_editing_to_author(
			array( 'edit_comment' => true ),
			array( 'edit_comment' ),
			array( $other_user_id, 'edit_comment', $comment )
		);

		$this->assertFalse( $caps['edit_comment'] );
	}

	/**
	 * Helper method to access private properties
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$property = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	public function tear_down() {
		// Clean up
		wp_delete_user( $this->admin_user_id );
		parent::tear_down();
	}
}
