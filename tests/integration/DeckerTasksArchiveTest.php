<?php
/**
 * Test archiving tasks functionality.
 *
 * @package Decker
 */
class DeckerTasksArchiveTest extends WP_UnitTestCase {

	private $task_id;
	private $user_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create a user and set as current user.
		$this->user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user_id );

		$board_id = self::factory()->term->create( array( 'taxonomy' => 'decker_board' ) );

		// Create a task.
		$this->task_id = self::factory()->post->create(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
				'tax_input'    => array(
					'decker_board' => array( $board_id ),
				),
				'meta_input'   => array(
					'stack' => 'to-do',
				),
			)
		);

		// Ensure the task exists.
		$this->assertTrue( get_post( $this->task_id ) instanceof WP_Post, 'Task was not created successfully.' );
	}

	/**
	 * Test direct archiving of a task.
	 */
	public function test_direct_archive_task() {
		$task_instance = new Decker_Tasks();

		// Archive the task.
		$updated = wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			),
			true
		);

		$this->assertNotWPError( $updated, 'Failed to archive the task.' );

		$task = get_post( $this->task_id );
		$this->assertEquals( 'archived', $task->post_status, 'Task was not archived successfully.' );
	}

	/**
	 * Test REST API archiving of a task.
	 */
	public function test_archive_task_by_rest() {
		$task_instance = new Decker_Tasks();

		// Simulate REST API request.
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/archive' );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_url_params( array( 'id' => $this->task_id ) ); // Explicitly set 'id' parameter

		$response = $task_instance->archive_task_callback( $request );

		if ( $response->get_status() !== 200 ) {
			$this->fail( 'REST API response error: ' . json_encode( $response->get_data() ) );
		}

		$this->assertEquals( 200, $response->get_status(), 'Unexpected REST API response status.' );

		// Verify the task is archived.
		$task = get_post( $this->task_id );
		$this->assertEquals( 'archived', $task->post_status, 'Task was not archived via REST API.' );
	}

	/**
	 * Test if archived tasks are excluded from the default task query.
	 */
	public function test_archived_tasks_excluded_from_query() {
		$task_instance = new Decker_Tasks();

		// Archive the task.
		wp_update_post(
			array(
				'ID'          => $this->task_id,
				'post_status' => 'archived',
			)
		);

		// Query tasks (should exclude archived by default).
		$query = new WP_Query(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'publish',
			)
		);

		$this->assertNotContains( $this->task_id, wp_list_pluck( $query->posts, 'ID' ), 'Archived task was incorrectly included in the query.' );
	}
}
