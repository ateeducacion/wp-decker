<?php
/**
 * Tests for the Decker WordPress Abilities API adapter.
 *
 * @package Decker
 */

/**
 * Test the Decker abilities adapter and execution service.
 */
class DeckerAbilitiesTest extends Decker_Test_Base {

	/** @var Decker_Abilities */
	private $abilities;

	/** @var Decker_Ability_Service */
	private $service;

	/** @var int */
	private $editor_id;

	/** @var int */
	private $board_id;

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		do_action( 'init' );
		$this->abilities = new Decker_Abilities();
		$this->service   = new Decker_Ability_Service();
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Feature detection must match the installed WordPress API.
	 */
	public function test_feature_detection_is_safe() {
		$this->assertSame(
			function_exists( 'wp_register_ability_category' ) && function_exists( 'wp_register_ability' ),
			Decker_Abilities::is_available()
		);
	}

	/**
	 * Definitions must expose verified operations and strict schemas.
	 */
	public function test_definitions_are_strict_and_annotated() {
		$definitions = $this->abilities->get_ability_definitions();
		$this->assertSame(
			array(
				'decker/list-tasks',
				'decker/get-task',
				'decker/create-task',
				'decker/update-task',
				'decker/move-task',
				'decker/archive-task',
				'decker/list-boards',
				'decker/search-knowledge-base',
			),
			array_keys( $definitions )
		);

		foreach ( $definitions as $definition ) {
			$this->assertFalse( $definition['output_schema']['additionalProperties'] );
			$this->assertArrayHasKey( 'annotations', $definition['meta'] );
			if ( isset( $definition['input_schema'] ) ) {
				$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			}
		}
	}

	/**
	 * Schema validation must reject missing, invalid, and unknown values.
	 */
	public function test_create_schema_rejects_invalid_input() {
		$schema = $this->abilities->get_ability_definitions()['decker/create-task']['input_schema'];
		$this->assertWPError( rest_validate_value_from_schema( array( 'board_id' => $this->board_id ), $schema, 'input' ) );
		$this->assertWPError(
			rest_validate_value_from_schema(
				array( 'title' => 'Task', 'board_id' => $this->board_id, 'stack' => 'invalid' ),
				$schema,
				'input'
			)
		);
		$this->assertWPError(
			rest_validate_value_from_schema(
				array( 'title' => 'Task', 'board_id' => $this->board_id, 'unknown' => true ),
				$schema,
				'input'
			)
		);
	}

	/**
	 * Unauthenticated and low-role users must be rejected.
	 */
	public function test_permissions_reject_unauthorized_users() {
		wp_set_current_user( 0 );
		$this->assertWPError( $this->service->can_list_tasks() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->service->can_create_task() );
	}

	/**
	 * Authorized writes must delegate to the existing domain behavior.
	 */
	public function test_authorized_task_lifecycle() {
		wp_set_current_user( $this->editor_id );
		$created_ids = array();
		$listener    = static function ( $task_id ) use ( &$created_ids ) {
			$created_ids[] = (int) $task_id;
		};
		add_action( 'decker_task_created', $listener );

		$task = $this->service->create_task(
			array(
				'title'               => 'Ability task',
				'board_id'            => $this->board_id,
				'responsible_user_id' => $this->editor_id,
				'due_date'            => '2026-08-01',
			)
		);
		remove_action( 'decker_task_created', $listener );
		$this->assertNotWPError( $task );
		$this->assertSame( array( $task['id'] ), $created_ids );

		$task = $this->service->update_task( array( 'task_id' => $task['id'], 'title' => 'Updated task' ) );
		$this->assertSame( 'Updated task', $task['title'] );
		$this->assertSame( '2026-08-01', $task['due_date'] );

		$task = $this->service->move_task( array( 'task_id' => $task['id'], 'stack' => 'in-progress' ) );
		$this->assertSame( 'in-progress', $task['stack'] );

		$task = $this->service->archive_task( array( 'task_id' => $task['id'], 'archived' => true ) );
		$this->assertSame( 'archived', $task['status'] );
	}

	/**
	 * Hidden tasks must not leak to unrelated editors.
	 */
	public function test_hidden_tasks_are_not_exposed() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create( array( 'post_title' => 'Hidden task', 'board' => $this->board_id ) );
		update_post_meta( $task_id, 'hidden', '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->service->can_read_task( array( 'task_id' => $task_id ) ) );
		$result = $this->service->list_tasks( array( 'search' => 'Hidden task' ) );
		$this->assertSame( array(), $result['tasks'] );
	}

	/**
	 * Existing REST routes must remain available.
	 */
	public function test_existing_rest_routes_remain_available() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$routes = $wp_rest_server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/tasks', $routes );
		$this->assertArrayHasKey( '/decker/v1/tasks/search', $routes );
	}

	/**
	 * Supported WordPress versions must register abilities.
	 */
	public function test_abilities_register_when_api_is_available() {
		if ( ! Decker_Abilities::is_available() || ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'The test WordPress version does not include the Abilities API.' );
		}

		if ( ! wp_has_ability( 'decker/list-tasks' ) ) {
			$this->abilities->register_category();
			$this->abilities->register_abilities();
		}
		$this->assertTrue( wp_has_ability( 'decker/list-tasks' ) );
		$this->assertTrue( wp_has_ability( 'decker/create-task' ) );
	}
}
