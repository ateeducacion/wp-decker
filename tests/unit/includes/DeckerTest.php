<?php
/**
 * Class Test_Decker
 *
 * @package Decker
 */

/**
 * Main plugin test case.
 */
class DeckerTest extends WP_UnitTestCase {
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

	public function test_comment_capabilities() {

		wp_set_current_user( $this->admin_user_id );

		// Set up nonce
		$_POST['decker_task_nonce'] = wp_create_nonce( 'save_decker_task' );

		// Create terms for boards and labels.
		$board_id = wp_insert_term( 'Board 1', 'decker_board' )['term_id'];

		// Create a test task and comment
		$task = $this->factory->post->create(
			array(
				'post_type' => 'decker_task',
				'post_author' => $this->admin_user_id,
				'tax_input'    => array(
					'decker_board' => array( $board_id ),
				),
				'meta_input'   => array(
					'stack' => 'to-do',
				),
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

		$this->assertFalse( $caps['edit_comment'] );

		// Test with different user
		$other_user_id = $this->factory->user->create( array( 'role' => 'suscriber' ) );
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

	/**
	 * Test current_user_has_at_least_minimum_role.
	 */
	public function test_current_user_has_at_least_minimum_role() {
		// Mock roles.
		$roles = array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array(
				'read' => true,
				'edit_posts' => true,
			),
			'administrator' => array(
				'read' => true,
				'edit_posts' => true,
				'manage_options' => true,
			),
		);

		// Mock WordPress roles.
		$wp_roles = wp_roles();
		$wp_roles->roles = $roles;
		$wp_roles->role_objects = array_map(
			function ( $capabilities ) {
				return (object) array( 'capabilities' => $capabilities );
			},
			$roles
		);

		// Mock decker settings.
		update_option( 'decker_settings', array( 'minimum_user_profile' => 'editor' ) );

		// Create users.
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Test administrator.
		wp_set_current_user( $admin );
		$current_user_roles = wp_get_current_user()->roles;
		// error_log( 'Current user roles (administrator): ' . implode( ', ', $current_user_roles ) );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Administrator should have editor access.' );

		// Test editor.
		wp_set_current_user( $editor );
		$current_user_roles = wp_get_current_user()->roles;
		// error_log( 'Current user roles (editor): ' . implode( ', ', $current_user_roles ) );
		$this->assertTrue( Decker::current_user_has_at_least_minimum_role(), 'Editor should have editor access.' );

		// Test subscriber.
		wp_set_current_user( $subscriber );
		$current_user_roles = wp_get_current_user()->roles;
		// error_log( 'Current user roles (subscriber): ' . implode( ', ', $current_user_roles ) );
		$this->assertFalse( Decker::current_user_has_at_least_minimum_role(), 'Subscriber should not have editor access.' );

		// Cleanup.
		wp_set_current_user( 0 );
	}


	public function tear_down() {
		// Clean up
		wp_delete_user( $this->admin_user_id );
		unset( $_POST['decker_task_nonce'] );
		parent::tear_down();
	}
}
