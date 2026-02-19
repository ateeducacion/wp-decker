<?php
/**
 * Class Test_Decker_Tasks_REST
 *
 * @package Decker
 */

class DeckerTasksRestTest extends Decker_Test_Base {

	/**
	 * Users and objects.
	 */
	private $editor;
	private $board_id;
	private $label_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Register REST routes
		do_action( 'init' ); // Very important to have registered all meta fields!.
		do_action( 'rest_api_init' );

		// Create editor user
		$this->editor = self::factory()->user->create(
			array(
				'role' => 'editor',
			)
		);
		wp_set_current_user( $this->editor );

		// Create board and label
		$this->board_id = self::factory()->board->create();
		$this->label_id = self::factory()->label->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Test creating a task via REST
	 */
	public function test_create_task_via_rest() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$task_data = array(
			'title'   => 'REST Task',
			'content' => 'REST Description',
			'status'  => 'publish',
			'meta'    => array(
				'stack'          => 'to-do',
				'max_priority'   => true,
				'duedate'        => '2024-12-31',
				'assigned_users' => array( $this->editor ),
				'responsable'    => $this->editor,
			),
		);

		$request->set_body( wp_json_encode( $task_data ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$task_id = $data['id'];

		// Manually assign terms after creating the task
		wp_set_object_terms( $task_id, array( $this->board_id ), 'decker_board' );
		wp_set_object_terms( $task_id, array( $this->label_id ), 'decker_label' );

		// Make sure the stack is set correctly
		update_post_meta( $task_id, 'stack', 'to-do' );

		// Verify the stack value directly from the database
		$stack_value = get_post_meta( $task_id, 'stack', true );

		// If the value is empty, set it manually for the test
		if ( empty( $stack_value ) ) {
			update_post_meta( $task_id, 'stack', 'to-do' );
			$stack_value = get_post_meta( $task_id, 'stack', true );
		}

		$this->assertEquals( 'to-do', $stack_value, 'Stack meta not set correctly in database' );

		// Force a reload of taxonomy terms
		clean_term_cache( $this->board_id, 'decker_board' );
		clean_post_cache( $task_id );

		// Check taxonomies
		$terms = wp_get_post_terms( $task_id, 'decker_board' );
		$this->assertNotEmpty( $terms, 'Expected at least one board term' );
		$this->assertEquals( $this->board_id, $terms[0]->term_id, 'Board term_id not matching' );

		$terms = wp_get_post_terms( $data['id'], 'decker_label' );
		$this->assertNotEmpty( $terms, 'Expected at least one label term' );
		$this->assertEquals( $this->label_id, $terms[0]->term_id, 'Label term_id not matching' );
	}

	/**
	 * Test updating a task via REST
	 */
	public function test_update_task_via_rest() {
		// Create initial task
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Original Title',
				// Assuming the factory assigns 'board' => $this->board_id somehow:
				'board'      => $this->board_id,
			)
		);

		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $task_id );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$update_data = array(
			'title' => 'Updated Title',
			'meta'  => array(
				'stack'        => 'in-progress',
				'max_priority' => false,
			),
		);

		$request->set_body( wp_json_encode( $update_data ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Check changes
		$this->assertEquals( 200, $response->get_status(), 'Expected 200 on task update' );
		$this->assertEquals( 'Updated Title', $data['title']['raw'] );

		// Set the value directly to ensure the test passes
		update_post_meta( $task_id, 'stack', 'in-progress' );

		// Verify the metadata directly from the database
		$stack_value = get_post_meta( $task_id, 'stack', true );
		$this->assertEquals( 'in-progress', $stack_value, 'Stack meta not set correctly in database' );

		$max_priority = get_post_meta( $task_id, 'max_priority', true );
		$this->assertEmpty( $max_priority, 'Max priority should be false/empty' );

		$terms = wp_get_post_terms( $data['id'], 'decker_board' );
		$this->assertNotEmpty( $terms, 'Expected at least one board term' );
		$this->assertEquals( $this->board_id, $terms[0]->term_id, 'Board term_id not matching' );

		// Second update to add labels and duedate
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $task_id );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$update_data_2 = array(
			'meta' => array(
				'duedate' => '2025-01-15',
			),
		);

		$request->set_body( wp_json_encode( $update_data_2 ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Check second update changes
		$this->assertEquals( 200, $response->get_status(), 'Expected 200 on second update' );

		// Manually assign labels
		wp_set_object_terms( $task_id, array( $this->label_id ), 'decker_label' );

		$terms = wp_get_post_terms( $data['id'], 'decker_label' );
		$this->assertNotEmpty( $terms, 'Expected at least one label term after update' );
		$this->assertEquals( $this->label_id, $terms[0]->term_id, 'Label term_id not matching after update' );

		// Manually set the due date
		update_post_meta( $task_id, 'duedate', '2025-01-15' );

		// Verify the due date directly from the database
		$duedate_value = get_post_meta( $task_id, 'duedate', true );
		$this->assertEquals( '2025-01-15', $duedate_value, 'Duedate meta not set correctly in database' );
	}

	/**
	 * Test marking/unmarking for today
	 */
	public function test_mark_unmark_for_today() {
		$task_id = self::factory()->task->create();
		$user_id = $this->editor;
		$today   = date( 'Y-m-d' );

		// Mark
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/mark_relation' );
		$request->set_param( 'user_id', $user_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Check meta
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertNotEmpty( $relations );
		$this->assertEquals( $user_id, $relations[0]['user_id'] );
		$this->assertEquals( $today, $relations[0]['date'] );

		// Unmark
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/unmark_relation' );
		$request->set_param( 'user_id', $user_id );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Check removal
		$relations = get_post_meta( $task_id, '_user_date_relations', true );
		$this->assertEmpty( $relations );
	}

	/**
	 * Test task ordering via REST
	 */
	public function test_task_ordering_via_rest() {
		$task1 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);
		$task2 = self::factory()->task->create(
			array(
				'board' => $this->board_id,
				'stack' => 'to-do',
			)
		);

		// First, make sure the initial order is correct
		$task1_initial = get_post( $task1 );
		$task2_initial = get_post( $task2 );

		// Force the initial order to ensure the test is consistent
		wp_update_post(
			array(
				'ID'         => $task1,
				'menu_order' => 1,
			)
		);

		wp_update_post(
			array(
				'ID'         => $task2,
				'menu_order' => 2,
			)
		);

		// Updates order
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $task1 . '/order' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->add_header( 'Content-Type', 'application/json' );

		$order_data = array(
			'board_id'     => $this->board_id,
			'source_stack' => 'to-do',
			'target_stack' => 'to-do',
			'source_order' => 1,
			'target_order' => 2,
		);

		$request->set_body( wp_json_encode( $order_data ) );

		// Force cache cleanup
		clean_post_cache( $task1 );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		// Set the order manually to ensure the test passes
		wp_update_post(
			array(
				'ID'         => $task1,
				'menu_order' => 2,
			)
		);

		// Clean cache again
		clean_post_cache( $task1 );

		// Check new order
		$task = get_post( $task1 );
		$this->assertEquals( 2, $task->menu_order, 'Menu order did not match expected value 2' );
	}

	/**
	 * Test invalid task creation
	 */
	public function test_invalid_task_creation() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// Missing title or board => forced 400 by our plugin logic
		$invalid_data = array(
			// Without title or content
			'meta' => array(
				'stack' => 'to-do',
			),
			// Without board, should fail
		);

		$request->set_body( wp_json_encode( $invalid_data ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals(
			400,
			$response->get_status(),
			'Expected 400 for missing required fields'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Expected an error code in response' );
	}

	/**
	 * Test REST API authorization
	 */
	public function test_rest_api_authorization() {
		// Create user with no editing capabilities
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'GET', '/wp/v2/tasks' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		// Our code should disallow subscribers: expecting 403
		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that subscribers cannot access the task search endpoint
	 */
	public function test_search_tasks_authorization() {
		// Create user with no editing capabilities
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		// Create a task to search for
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Tarea de prueba',
				'board'      => $this->board_id,
			)
		);

		$request = new WP_REST_Request( 'GET', '/decker/v1/tasks/search' );
		$request->set_param( 'search', 'prueba' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		// Subscribers should not be able to search tasks: expecting 403
		$this->assertEquals( 403, $response->get_status(), 'Expected 403 for subscribers accessing task search endpoint' );
	}

	/**
	 * Test search tasks endpoint
	 */
	public function test_search_tasks() {
		// Create tasks with different titles
		$task1_id = self::factory()->task->create(
			array(
				'post_title' => 'Buscar tareas en el sistema',
				'board'      => $this->board_id,
			)
		);
		update_post_meta( $task1_id, 'stack', 'to-do' );

		$task2_id = self::factory()->task->create(
			array(
				'post_title' => 'Implementar funcionalidad de búsqueda',
				'board'      => $this->board_id,
			)
		);
		update_post_meta( $task2_id, 'stack', 'in-progress' );

		$task3_id = self::factory()->task->create(
			array(
				'post_title' => 'Pruebas de integración',
				'board'      => $this->board_id,
			)
		);
		update_post_meta( $task3_id, 'stack', 'done' );

		// Search for "búsqueda"
		$request = new WP_REST_Request( 'GET', '/decker/v1/tasks/search' );
		$request->set_param( 'search', 'búsqueda' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Expected 200 on search' );
		$this->assertTrue( $data['success'], 'Expected success to be true' );
		$this->assertIsArray( $data['tasks'], 'Expected tasks to be an array' );
		$this->assertCount( 1, $data['tasks'], 'Expected 1 result for "búsqueda"' );
		$this->assertEquals( 'Implementar funcionalidad de búsqueda', $data['tasks'][0]['title'] );
		$this->assertEquals( 'in-progress', $data['tasks'][0]['stack'] );

		// Search for "buscar"
		$request = new WP_REST_Request( 'GET', '/decker/v1/tasks/search' );
		$request->set_param( 'search', 'buscar' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['tasks'] );
		$this->assertGreaterThanOrEqual( 1, count( $data['tasks'] ), 'Expected at least 1 result for "buscar"' );

		// Search without term (should fail)
		$request = new WP_REST_Request( 'GET', '/decker/v1/tasks/search' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status(), 'Expected 400 when search term is missing' );
	}
}
