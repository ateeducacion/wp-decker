<?php

class DeckerRestDailyTest extends Decker_Test_Base {

	protected $editor_user_id;

	public function set_up() {
		parent::set_up();
		$this->editor_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_user_id );
	}

	public function test_get_daily_endpoint() {
		$board = self::factory()->board->create();
		$date = '2025-09-15';

		$request = new WP_REST_Request( 'GET', '/decker/v1/daily' );
		$request->set_query_params( array( 'board' => $board, 'date' => $date ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'tasks', $data );
		$this->assertArrayHasKey( 'users', $data );
		$this->assertArrayHasKey( 'notes', $data );
	}

	public function test_post_daily_endpoint() {
		$board = self::factory()->board->create();
		$user = self::factory()->user->create();
		$date = '2025-09-15';
		$task = self::factory()->task->create( array( 'meta_input' => array( 'stack' => 'to-do' ) ) );
		$this->assertNotWPError( $task );
		wp_set_post_terms( $task, $board, 'decker_board' );
		add_post_meta( $task, '_user_date_relations', array( array( 'user_id' => $user, 'date' => $date ) ) );

		$request = new WP_REST_Request( 'POST', '/decker/v1/daily' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array(
			'board' => $board,
			'date'  => $date,
			'notes' => 'These are some notes.',
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertIsInt( $data['post_id'] );
	}

	public function test_post_daily_endpoint_no_tasks() {
		$board = self::factory()->board->create();
		$date = '2025-09-15';

		$request = new WP_REST_Request( 'POST', '/decker/v1/daily' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array(
			'board' => $board,
			'date'  => $date,
			'notes' => 'These notes should not be saved.',
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	public function test_get_permissions() {
		wp_set_current_user( 0 ); // Log out
		$request = new WP_REST_Request( 'GET', '/decker/v1/daily' );
		$request->set_query_params( array( 'board' => 1, 'date' => '2025-09-15' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 401, $response->get_status() ); // Not logged in
	}

	public function test_post_permissions() {
		wp_set_current_user( 0 );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/decker/v1/daily' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array( 'board' => 1, 'date' => '2025-09-15', 'notes' => 'test' );
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() ); // Not enough permissions
	}
}
