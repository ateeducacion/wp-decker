<?php
/**
 * Tests that task detail meta round-trips through the REST API.
 *
 * Regression coverage for the endpoint silently dropping `stack`,
 * `max_priority` and `duedate` because they were not registered for REST.
 *
 * @package Decker
 */
class DeckerTaskRestMetaTest extends Decker_Test_Base {

	private $user_id;
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		// WP_UnitTestCase::tear_down() unregisters every meta key after each
		// test, so re-fire `init` to restore the plugin's REST meta registration
		// (in production `init` runs once and the meta stays registered).
		do_action( 'init' );

		$this->board_id = self::factory()->board->create( array( 'name' => 'REST Meta Board' ) );
	}

	/**
	 * Creating a task over REST persists its detail meta and returns it.
	 */
	public function test_meta_is_written_and_returned_on_create() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'title'        => 'REST Meta Task',
				'status'       => 'publish',
				'decker_board' => array( $this->board_id ),
				'meta'         => array(
					'stack'        => 'in-progress',
					'max_priority' => true,
					'duedate'      => '2026-12-31',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 201, $response->get_status(), 'Task was not created via REST.' );

		$data    = $response->get_data();
		$task_id = $data['id'];

		// Persisted to post meta.
		$this->assertEquals( 'in-progress', get_post_meta( $task_id, 'stack', true ) );
		$this->assertEquals( '1', get_post_meta( $task_id, 'max_priority', true ) );
		$this->assertEquals( '2026-12-31', get_post_meta( $task_id, 'duedate', true ) );

		// Exposed in the REST response (max_priority is a boolean over REST).
		$this->assertEquals( 'in-progress', $data['meta']['stack'] );
		$this->assertTrue( $data['meta']['max_priority'] );
		$this->assertEquals( '2026-12-31', $data['meta']['duedate'] );

		// The Task model reads the same values.
		$task = new Task( $task_id );
		$this->assertEquals( 'in-progress', $task->stack );
		$this->assertTrue( $task->max_priority );
	}

	/**
	 * Updating the stack over REST persists the new value.
	 */
	public function test_stack_can_be_updated_via_rest() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$request = new WP_REST_Request( 'POST', sprintf( '/wp/v2/tasks/%d', $task_id ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'meta' => array( 'stack' => 'done' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// The new value is persisted. Reorder hooks run raw SQL on stack
		// changes, so drop the in-request meta cache before asserting.
		clean_post_cache( $task_id );
		$this->assertEquals( 'done', get_post_meta( $task_id, 'stack', true ) );
	}

	/**
	 * An invalid stack value is rejected by the REST enum schema and the stored
	 * value is left unchanged.
	 */
	public function test_invalid_stack_is_rejected() {
		$task_id = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$request = new WP_REST_Request( 'POST', sprintf( '/wp/v2/tasks/%d', $task_id ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'meta' => array( 'stack' => 'not-a-real-stack' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );

		clean_post_cache( $task_id );
		$this->assertEquals( 'to-do', get_post_meta( $task_id, 'stack', true ) );
	}
}
