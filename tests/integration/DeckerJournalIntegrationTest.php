<?php

class DeckerJournalIntegrationTest extends Decker_Test_Base {

	private $editor_user_id;

	public function set_up() {
		parent::set_up();
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_user_id );
	}

	public function test_cpt_is_registered() {
		$this->assertTrue( post_type_exists( 'decker_journal' ) );
	}

	public function test_cpt_properties() {
		$cpt = get_post_type_object( 'decker_journal' );
		$this->assertNotNull( $cpt );
		$this->assertTrue( $cpt->public );
		$this->assertTrue( $cpt->show_in_rest );
		$this->assertEquals( 'decker-journals', $cpt->rest_base );
		$this->assertEquals( 'decker_journal', $cpt->capability_type );
		$this->assertContains( 'decker_board', $cpt->taxonomies );
		$this->assertContains( 'decker_label', $cpt->taxonomies );
		$this->assertTrue( post_type_supports( 'decker_journal', 'title' ) );
		$this->assertTrue( post_type_supports( 'decker_journal', 'editor' ) );
		$this->assertTrue( post_type_supports( 'decker_journal', 'author' ) );
		$this->assertTrue( post_type_supports( 'decker_journal', 'revisions' ) );
	}

	public function test_meta_fields_are_registered() {
		$this->assertTrue( registered_meta_key_exists( 'post', 'journal_date', 'decker_journal' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', 'attendees', 'decker_journal' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', 'derived_tasks', 'decker_journal' ) );
	}

	public function test_rest_api_crud_round_trip() {
		$board = $this->factory->board->create();
		$label = $this->factory->label->create();
		$task = $this->factory->task->create();

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker-journals' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array(
			'title'   => 'Test Journal Entry',
			'status'  => 'publish',
			'decker_board' => $board,
			'decker_label' => array( $label ),
			'meta' => array(
				'journal_date' => '2025-08-30',
				'attendees' => array( 'Fran', 'Humberto' ),
				'topic' => 'Test Topic',
				'agreements' => array( 'Agreement 1', 'Agreement 2' ),
				'derived_tasks' => array(
					array(
						'description' => 'Derived Task 1',
						'responsible_team' => 'Team A',
						'task_post_id' => $task,
						'task_link' => get_permalink( $task ),
					),
				),
				'notes' => array(
					array( 'text' => 'Note 1', 'checked' => false ),
					array( 'text' => 'Note 2', 'checked' => true ),
				),
				'related_task_ids' => array( $task ),
			),
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$post_id = $data['id'];

		// Verify data was saved correctly by fetching the post
		$request = new WP_REST_Request( 'GET', '/wp/v2/decker-journals/' . $post_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$read_data = $response->get_data();

		$this->assertEquals( 'Test Journal Entry', $read_data['title']['raw'] );
		$this->assertEquals( '2025-08-30', $read_data['meta']['journal_date'] );
		$this->assertEquals( array( 'Fran', 'Humberto' ), $read_data['meta']['attendees'] );
		$this->assertEquals( 'Test Topic', $read_data['meta']['topic'] );
		$this->assertEquals( 'Derived Task 1', $read_data['meta']['derived_tasks'][0]['description'] );
		$this->assertTrue( $read_data['meta']['notes'][1]['checked'] );
		$this->assertContains( $board, $read_data['decker_board'] );
		$this->assertContains( $label, $read_data['decker_label'] );
		$this->assertContains( $task, $read_data['meta']['related_task_ids'] );
	}

	public function test_uniqueness_validation() {
		$board = $this->factory->board->create();
		$params = array(
			'title'   => 'First Journal',
			'status'  => 'publish',
			'decker_board' => $board,
			'meta' => array( 'journal_date' => '2025-09-01' ),
		);
		$request = new WP_REST_Request( 'POST', '/wp/v2/decker-journals' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 201, $response->get_status() );

		// Try to create a second one with the same board and date
		$params['title'] = 'Second Journal';
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'duplicate_journal', $data['code'] );
	}

	public function test_board_required_validation() {
		$request = new WP_REST_Request( 'POST', '/wp/v2/decker-journals' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array(
			'title'   => 'No Board Journal',
			'status'  => 'publish',
			'meta' => array( 'journal_date' => '2025-09-02' ),
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertEquals( 'board_required', $data['code'] );
	}

	public function test_capabilities() {
		wp_set_current_user( 0 ); // Log out
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/decker-journals' );
		$request->set_header( 'Content-Type', 'application/json' );
		$params = array( 'title' => 'Subscriber Journal' );
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_default_content() {
		$editor = new Decker_Journal_Editor();
		$post = new stdClass();
		$post->post_type = 'decker_journal';
		$content = $editor->set_default_editor_content( '', $post );
		$this->assertStringContainsString( '# ' . date('d/m/Y'), $content );
		$this->assertStringContainsString( '**Asistentes:**', $content );
	}
}
