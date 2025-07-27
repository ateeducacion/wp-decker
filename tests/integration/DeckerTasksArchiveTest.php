<?php
/**
 * Test archiving tasks functionality.
 *
 * @package Decker
 */
class DeckerTasksArchiveTest extends Decker_Test_Base {

	private $task_id;
	private $user_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create a user and set as current user
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		// Create a task
		$this->task_id = self::factory()->task->create();

		// Ensure the task exists
		$this->assertTrue(
			get_post( $this->task_id ) instanceof WP_Post,
			'Task was not created successfully.'
		);
	}

	/**
	 * Test direct archiving of a task
	 */
	public function test_direct_archive_task() {
		// Archive the task
		$updated = wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			),
			true
		);

		$this->assertNotWPError( $updated, 'Failed to archive the task' );

		$task = get_post( $this->task_id );
		$this->assertEquals(
			'archived',
			$task->post_status,
			'Task was not archived successfully'
		);
	}

	/**
	 * Test REST API archiving/unarchiving
	 */
	public function test_archive_unarchive_via_rest() {
		// Test archiving
		$request = new WP_REST_Request(
			'POST',
			sprintf( '/wp/v2/tasks/%d', $this->task_id )
		);
		$request->set_body_params( array( 'status' => 'archived' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'archived', $data['status'] );

		// Test unarchiving
		$request = new WP_REST_Request(
			'POST',
			sprintf( '/wp/v2/tasks/%d', $this->task_id )
		);
		$request->set_body_params( array( 'status' => 'publish' ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertEquals( 'publish', $data['status'] );
	}

	/**
	 * Test if archived tasks are excluded from default query
	 */
	public function test_archived_tasks_excluded_from_query() {
		// Archive the task
		wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			)
		);

		// Query tasks (should exclude archived by default)
		$query = new WP_Query(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
			)
		);

		$this->assertNotContains(
			$this->task_id,
			wp_list_pluck( $query->posts, 'ID' ),
			'Archived task was incorrectly included in the query'
		);
	}
}
