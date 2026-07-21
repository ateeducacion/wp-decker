<?php
/**
 * Characterization tests for task ordering behavior.
 *
 * Pins modify_task_order_before_save retrieval fallbacks, the REST
 * stack/order contract (including error branches), the board-change reorder
 * and the stack-change reorder before these methods are refactored.
 *
 * @package Decker
 */

class DeckerTasksOrderLockInTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Lock that modify_task_order_before_save assigns incremental menu_order via
	 * both the meta_input/tax_input path and the top-level decker_board/stack path.
	 */
	public function test_modify_task_order_before_save_assigns_incremental_menu_order() {
		$task1 = wp_insert_post(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
				'post_title'  => 'T1',
				'meta_input'  => array( 'stack' => 'to-do' ),
				'tax_input'   => array( 'decker_board' => array( $this->board_id ) ),
			)
		);
		$this->assertEquals( 1, get_post( $task1 )->menu_order );

		$task2 = wp_insert_post(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
				'post_title'  => 'T2',
				'meta_input'  => array( 'stack' => 'to-do' ),
				'tax_input'   => array( 'decker_board' => array( $this->board_id ) ),
			)
		);
		$this->assertEquals( 2, get_post( $task2 )->menu_order );

		// Top-level decker_board/stack keys (factory path).
		$task3 = wp_insert_post(
			array(
				'post_type'    => 'decker_task',
				'post_status'  => 'publish',
				'post_title'   => 'T3',
				'decker_board' => $this->board_id,
				'stack'        => 'to-do',
				'meta_input'   => array( 'stack' => 'to-do' ),
				'tax_input'    => array( 'decker_board' => array( $this->board_id ) ),
			)
		);
		$this->assertEquals( 3, get_post( $task3 )->menu_order );

		// Missing board: menu_order stays at default 0.
		$task4 = wp_insert_post(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
				'post_title'  => 'T4',
				'meta_input'  => array( 'stack' => 'to-do' ),
			)
		);
		$this->assertEquals( 0, get_post( $task4 )->menu_order );
	}

	/**
	 * Lock the REST stack/order contract and its error branches.
	 */
	public function test_update_task_stack_and_order_rest_contract() {
		$task = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		$completed = 0;
		$transition = 0;
		add_action(
			'decker_task_completed',
			function () use ( &$completed ) {
				$completed++;
			}
		);
		add_action(
			'decker_stack_transition',
			function () use ( &$transition ) {
				$transition++;
			}
		);

		// Valid move to done.
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task . '/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'board_id'     => $this->board_id,
					'source_stack' => 'to-do',
					'target_stack' => 'done',
					'source_order' => 1,
					'target_order' => 1,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 'success', $data['status'] );
		$this->assertEquals( 'done', get_post_meta( $task, 'stack', true ) );
		$this->assertEquals( 1, $transition );
		$this->assertEquals( 1, $completed );

		// Invalid stack value -> 400.
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task . '/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'board_id'     => $this->board_id,
					'source_stack' => 'to-do',
					'target_stack' => 'bogus',
					'source_order' => 1,
					'target_order' => 1,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'Invalid stack value.', $response->get_data()['message'] );

		// Invalid parameters (target_order 0) -> 400.
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task . '/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'board_id'     => $this->board_id,
					'source_stack' => 'to-do',
					'target_stack' => 'done',
					'source_order' => 1,
					'target_order' => 0,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'Invalid parameters.', $response->get_data()['message'] );

		// Non-task post -> 404.
		$plain = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $plain . '/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'board_id'     => $this->board_id,
					'source_stack' => 'to-do',
					'target_stack' => 'done',
					'source_order' => 1,
					'target_order' => 1,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );
		$this->assertEquals( 'Task not found.', $response->get_data()['message'] );
	}

	/**
	 * Lock the board-change reorder: moved task goes to end of new board, the
	 * old board compacts, and the changed transient is set.
	 */
	public function test_handle_board_change_reorder_moves_task_to_end_and_compacts_old_board() {
		$board_b = self::factory()->board->create();

		$a1 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$a2 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$b1 = self::factory()->task->create(
			array(
				'board' => $board_b,
				'stack' => 'to-do',
			)
		);
		$b2 = self::factory()->task->create(
			array(
				'board' => $board_b,
				'stack' => 'to-do',
			)
		);

		// Move a1 to board B.
		wp_set_object_terms( $a1, array( $board_b ), 'decker_board' );

		clean_post_cache( $a1 );
		clean_post_cache( $a2 );

		$this->assertEquals( 3, get_post( $a1 )->menu_order, 'Moved task should land at end of new board.' );
		$this->assertEquals( 1, get_post( $a2 )->menu_order, 'Remaining old-board task should compact to 1.' );
		$this->assertTrue( (bool) get_transient( "decker_board_changed_{$a1}" ) );
	}

	/**
	 * Lock the stack-change reorder on a direct meta update.
	 */
	public function test_handle_stack_change_reorder_on_meta_update() {
		$t1 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$t2 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$done = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'done',
			)
		);

		// Move t1 to done.
		update_post_meta( $t1, 'stack', 'done' );

		clean_post_cache( $t1 );
		clean_post_cache( $t2 );

		$this->assertEquals( 'done', get_post_meta( $t1, '_decker_prev_stack', true ) );
		$this->assertEquals( 2, get_post( $t1 )->menu_order, 'Moved task lands at end of done stack.' );
		$this->assertEquals( 1, get_post( $t2 )->menu_order, 'Remaining to-do task compacts to 1.' );
	}
}
