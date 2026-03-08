<?php
/**
 * Class Test_Decker_Tasks_Clone
 *
 * Tests for the clone task feature.
 *
 * @package Decker
 */

class DeckerTasksCloneTest extends Decker_Test_Base {

	/**
	 * Test users and objects.
	 */
	private $editor;
	private $board_id;
	private $label_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Ensure that post types and taxonomies are registered.
		do_action( 'init' );
		do_action( 'rest_api_init' );

		// Create an editor user.
		$this->editor = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $this->editor );

		// Create a board and label.
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
	 * Test that cloning a task copies meta and taxonomies correctly.
	 */
	public function test_clone_copies_meta_and_taxonomies() {
		$task_id = self::factory()->task->create(
			array(
				'post_title'     => 'Original Task',
				'post_content'   => 'Original description',
				'board'          => $this->board_id,
				'stack'          => 'in-progress',
				'max_priority'   => true,
				'duedate'        => '2025-12-31',
				'assigned_users' => array( $this->editor ),
				'labels'         => array( $this->label_id ),
				'hidden'         => true,
				'responsable'    => $this->editor,
			)
		);
		$this->assertNotWPError( $task_id );

		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );
		$this->assertIsInt( $new_task_id );
		$this->assertNotEquals( $task_id, $new_task_id );

		// Verify title has (copy) suffix.
		$new_post = get_post( $new_task_id );
		$this->assertEquals(
			'Original Task (copy)',
			$new_post->post_title,
			'Cloned task title should have (copy) suffix.'
		);

		// Verify content is copied.
		$this->assertEquals(
			'Original description',
			$new_post->post_content,
			'Cloned task content should match original.'
		);

		// Verify meta fields are copied.
		$this->assertEquals(
			'in-progress',
			get_post_meta( $new_task_id, 'stack', true ),
			'Cloned task stack should match.'
		);
		$this->assertEquals(
			'1',
			get_post_meta( $new_task_id, 'max_priority', true ),
			'Cloned task max_priority should match.'
		);
		$this->assertEquals(
			'2025-12-31',
			get_post_meta( $new_task_id, 'duedate', true ),
			'Cloned task duedate should match.'
		);

		$assigned = get_post_meta( $new_task_id, 'assigned_users', true );
		$this->assertContains(
			$this->editor,
			$assigned,
			'Cloned task should have same assigned users.'
		);

		$this->assertEquals(
			$this->editor,
			(int) get_post_meta( $new_task_id, 'responsable', true ),
			'Cloned task responsable should match.'
		);
		$this->assertEquals(
			'1',
			get_post_meta( $new_task_id, 'hidden', true ),
			'Cloned task hidden should match.'
		);

		// Verify taxonomy terms are copied.
		$boards = wp_get_post_terms(
			$new_task_id,
			'decker_board',
			array( 'fields' => 'ids' )
		);
		$this->assertContains(
			$this->board_id,
			$boards,
			'Cloned task should have same board.'
		);

		$labels = wp_get_post_terms(
			$new_task_id,
			'decker_label',
			array( 'fields' => 'ids' )
		);
		$this->assertContains(
			$this->label_id,
			$labels,
			'Cloned task should have same labels.'
		);
	}

	/**
	 * Test that the title suffix is applied correctly.
	 */
	public function test_clone_title_suffix() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'My Task',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );

		$new_post = get_post( $new_task_id );
		$this->assertEquals(
			'My Task (copy)',
			$new_post->post_title,
			'Cloned task title should have (copy) suffix.'
		);
	}

	/**
	 * Test that cloning does not copy nextcloud ID.
	 */
	public function test_clone_excludes_nextcloud_id() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'NC Task',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		// Manually set a nextcloud card ID.
		update_post_meta( $task_id, 'id_nextcloud_card', 12345 );

		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );

		$nc_id = get_post_meta( $new_task_id, 'id_nextcloud_card', true );
		$this->assertEquals(
			0,
			(int) $nc_id,
			'Cloned task should not copy nextcloud card ID.'
		);
	}

	/**
	 * Test that cloning does not copy user date relations.
	 */
	public function test_clone_excludes_user_date_relations() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Relation Task',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		// Manually set user date relations.
		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => '2025-01-01',
				),
			)
		);

		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );

		$relations = get_post_meta(
			$new_task_id,
			'_user_date_relations',
			true
		);
		$this->assertEmpty(
			$relations,
			'Cloned task should not copy _user_date_relations.'
		);
	}

	/**
	 * Test that cloning an invalid task returns error.
	 */
	public function test_clone_invalid_task_returns_error() {
		$result = Decker_Tasks::clone_task( 999999 );
		$this->assertWPError(
			$result,
			'Cloning a non-existent task should return WP_Error.'
		);
	}

	/**
	 * Test that the clone REST endpoint works.
	 */
	public function test_clone_task_via_rest() {
		$task_id = self::factory()->task->create(
			array(
				'post_title'   => 'REST Clone Test',
				'post_content' => 'Testing REST clone',
				'board'        => $this->board_id,
				'stack'        => 'to-do',
				'labels'       => array( $this->label_id ),
			)
		);
		$this->assertNotWPError( $task_id );

		$request = new WP_REST_Request(
			'POST',
			'/decker/v1/tasks/' . $task_id . '/clone'
		);
		$request->set_header(
			'X-WP-Nonce',
			wp_create_nonce( 'wp_rest' )
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals(
			200,
			$response->get_status(),
			'Expected 200 on clone'
		);
		$this->assertTrue(
			$data['success'],
			'Response should indicate success.'
		);
		$this->assertArrayHasKey(
			'new_task_id',
			$data,
			'Response should include new task ID.'
		);

		$new_post = get_post( $data['new_task_id'] );
		$this->assertEquals(
			'REST Clone Test (copy)',
			$new_post->post_title,
			'Cloned task title should have (copy) suffix.'
		);
	}

	/**
	 * Test that subscribers cannot clone tasks via REST.
	 */
	public function test_clone_rest_unauthorized() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Auth Test',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		// Switch to subscriber.
		$subscriber = self::factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request(
			'POST',
			'/decker/v1/tasks/' . $task_id . '/clone'
		);
		$request->set_header(
			'X-WP-Nonce',
			wp_create_nonce( 'wp_rest' )
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'Subscribers should not be able to clone tasks.'
		);
	}

	/**
	 * Test that cloned task has a different ID than the original.
	 */
	public function test_clone_creates_different_id() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'ID Test',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );
		$this->assertNotEquals(
			$task_id,
			$new_task_id,
			'Cloned task should have a different ID.'
		);
	}

	/**
	 * Test that the cloned task post status matches the original.
	 */
	public function test_clone_preserves_post_status() {
		$task_id = self::factory()->task->create(
			array(
				'post_title' => 'Status Test',
				'board'      => $this->board_id,
			)
		);
		$this->assertNotWPError( $task_id );

		$original = get_post( $task_id );
		$new_task_id = Decker_Tasks::clone_task( $task_id );
		$this->assertNotWPError( $new_task_id );

		$cloned = get_post( $new_task_id );
		$this->assertEquals(
			$original->post_status,
			$cloned->post_status,
			'Cloned task should have same post status.'
		);
	}
}
