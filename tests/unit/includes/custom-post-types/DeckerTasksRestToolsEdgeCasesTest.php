<?php
/**
 * Error-path tests for the task search, clone and merge REST routes.
 *
 * The happy paths live in DeckerTasksCloneTest, DeckerTasksMergeTest and
 * DeckerTasksRestTest; these cover what the endpoints do when the task does
 * not exist, when the caller may use the route but not the specific task, and
 * when the underlying operation fails.
 *
 * @package Decker
 */

class DeckerTasksRestToolsEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Administrator used to seed fixtures.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Board the fixtures are attached to.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );

		$this->board_id = self::factory()->board->create(
			array(
				'name' => 'Tools Board',
				'slug' => 'tools-board',
			)
		);
	}

	/**
	 * Dispatch a REST request against the decker namespace.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $params Request parameters.
	 * @return WP_REST_Response The response.
	 */
	private function dispatch( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * An empty search term is rejected with a 400.
	 */
	public function test_search_rejects_an_empty_term() {
		$response = $this->dispatch( 'GET', '/decker/v1/tasks/search', array( 'search' => '' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( 'Search term is required.', $response->get_data()['message'] );
	}

	/**
	 * Search results carry a human-readable label for every stack value.
	 */
	public function test_search_labels_every_stack_including_unknown_values() {
		$done_id = self::factory()->task->create(
			array(
				'post_title' => 'Searchable Done Task',
				'board'      => $this->board_id,
				'stack'      => 'done',
			)
		);
		$odd_id  = self::factory()->task->create(
			array(
				'post_title' => 'Searchable Odd Task',
				'board'      => $this->board_id,
			)
		);
		update_post_meta( $odd_id, 'stack', 'nonsense' );

		$response = $this->dispatch( 'GET', '/decker/v1/tasks/search', array( 'search' => 'Searchable' ) );
		$this->assertSame( 200, $response->get_status() );

		$labels = wp_list_pluck( $response->get_data()['tasks'], 'stack_label', 'id' );

		$this->assertSame( 'Done', $labels[ $done_id ] );
		$this->assertSame( 'Unknown', $labels[ $odd_id ] );

		// Each result carries its board name.
		$boards = wp_list_pluck( $response->get_data()['tasks'], 'board', 'id' );
		$this->assertSame( 'Tools Board', $boards[ $done_id ] );
	}

	/**
	 * A task with no board falls back to the "No Board" label in search results.
	 */
	public function test_search_result_without_a_board_falls_back_to_no_board() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Boardless Searchable Task',
				'board'      => $this->board_id,
			)
		);
		wp_delete_object_term_relationships( $task_id, 'decker_board' );

		$response = $this->dispatch( 'GET', '/decker/v1/tasks/search', array( 'search' => 'Boardless' ) );

		$result = $response->get_data()['tasks'][0];
		$this->assertSame( 'No Board', $result['board'] );
		$this->assertSame( '', $result['board_slug'] );
	}

	/**
	 * Cloning a post that is not a task returns 404.
	 */
	public function test_clone_rejects_a_non_task_post() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$response = $this->dispatch( 'POST', '/decker/v1/tasks/' . $page_id . '/clone' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * Cloning a missing id returns 404.
	 */
	public function test_clone_rejects_a_missing_task() {
		$response = $this->dispatch( 'POST', '/decker/v1/tasks/999999/clone' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A caller who may use the route but not edit that task gets 403.
	 */
	public function test_clone_rejects_a_caller_without_edit_rights_on_the_task() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Someone Elses Task',
				'board'      => $this->board_id,
				'author'     => $this->admin,
			)
		);

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$response = $this->dispatch( 'POST', '/decker/v1/tasks/' . $task_id . '/clone' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );

		// Nothing was cloned.
		$this->assertCount(
			0,
			get_posts(
				array(
					'post_type'   => 'decker_task',
					'post_status' => 'any',
					's'           => '(copy)',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Merging without a destination is rejected with a 400.
	 */
	public function test_merge_requires_a_destination() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Merge Source',
				'board'      => $this->board_id,
			)
		);

		$response = $this->dispatch(
			'POST',
			'/decker/v1/tasks/' . $task_id . '/merge',
			array( 'destination_task_id' => 0 )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * A caller without edit rights on both tasks is refused.
	 */
	public function test_merge_rejects_a_caller_without_edit_rights() {
		$source      = self::factory()->task->create(
			array(
				'post_title' => 'Merge Source',
				'board'      => $this->board_id,
				'author'     => $this->admin,
			)
		);
		$destination = self::factory()->task->create(
			array(
				'post_title' => 'Merge Destination',
				'board'      => $this->board_id,
				'author'     => $this->admin,
			)
		);

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$response = $this->dispatch(
			'POST',
			'/decker/v1/tasks/' . $source . '/merge',
			array( 'destination_task_id' => $destination )
		);

		$this->assertSame( 403, $response->get_status() );

		// The source is untouched.
		$this->assertSame( 'publish', get_post_status( $source ) );
	}

	/**
	 * A failing merge surfaces the underlying error status and message.
	 */
	public function test_failed_merge_surfaces_the_underlying_error() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Self Merge',
				'board'      => $this->board_id,
			)
		);

		// Merging a task into itself is rejected by Decker_Task_Merge.
		$response = $this->dispatch(
			'POST',
			'/decker/v1/tasks/' . $task_id . '/merge',
			array( 'destination_task_id' => $task_id )
		);

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertNotEmpty( $response->get_data()['message'] );

		// The task is still active.
		$this->assertSame( 'publish', get_post_status( $task_id ) );
	}

	/**
	 * Merging into a missing destination is refused and leaves the source alone.
	 */
	public function test_merge_into_a_missing_destination_is_refused() {
		$source = self::factory()->task->create(
			array(
				'post_title' => 'Merge Source',
				'board'      => $this->board_id,
			)
		);

		$response = $this->dispatch(
			'POST',
			'/decker/v1/tasks/' . $source . '/merge',
			array( 'destination_task_id' => 999999 )
		);

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( 'publish', get_post_status( $source ) );
	}
}
