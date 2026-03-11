<?php
/**
 * Class Test_Decker_Tasks_Merge
 *
 * @package Decker
 */

class DeckerTasksMergeTest extends Decker_Test_Base {

	/**
	 * Test editor user.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Board ID.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Uploaded attachment IDs.
	 *
	 * @var array<int>
	 */
	private $uploaded_files = array();

	/**
	 * Temporary upload paths.
	 *
	 * @var array<int, string>
	 */
	private $temp_files = array();

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );
		do_action( 'rest_api_init' );

		$this->editor = self::factory()->user->create(
			array( 'role' => 'editor' )
		);
		wp_set_current_user( $this->editor );
		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		foreach ( $this->uploaded_files as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( $this->temp_files as $temp_file ) {
			if ( file_exists( $temp_file ) ) {
				unlink( $temp_file );
			}
		}

		wp_delete_user( $this->editor );
		parent::tear_down();
	}

	/**
	 * Test that merge moves task data into the destination task.
	 */
	public function test_merge_moves_task_data_into_destination() {
		$second_user = self::factory()->user->create(
			array( 'role' => 'editor' )
		);

		$destination_task_id = self::factory()->task->create(
			array(
				'post_title'     => 'Destination task',
				'post_content'   => '<p>Destination description</p>',
				'board'          => $this->board_id,
				'assigned_users' => array( $this->editor ),
			)
		);

		$source_task_id = self::factory()->task->create(
			array(
				'post_title'     => 'Source task',
				'post_content'   => '<p>Source description</p>',
				'board'          => $this->board_id,
				'assigned_users' => array( $second_user ),
			)
		);

		update_post_meta(
			$destination_task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => '2025-01-01',
				),
			)
		);

		update_post_meta(
			$source_task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $second_user,
					'date'    => '2025-01-02',
				),
			)
		);

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $source_task_id,
				'comment_content'      => 'Source comment',
				'comment_author'       => 'Editor',
				'comment_author_email' => 'editor@example.com',
				'user_id'              => $this->editor,
				'comment_approved'     => 1,
				'comment_date'         => '2025-01-03 10:00:00',
				'comment_date_gmt'     => '2025-01-03 10:00:00',
			)
		);

		$attachment_id = $this->create_attachment_for_task( $source_task_id );

		$result = Decker_Tasks::merge_tasks(
			$source_task_id,
			$destination_task_id
		);

		$this->assertTrue( $result );

		$destination_post = get_post( $destination_task_id );
		$source_post      = get_post( $source_task_id );

		$this->assertStringContainsString(
			'Destination description',
			$destination_post->post_content
		);
		$this->assertStringContainsString(
			'Source description',
			$destination_post->post_content
		);
		$this->assertStringContainsString(
			'Merged from task: Source task (ID: ' . $source_task_id . ')',
			wp_strip_all_tags( $destination_post->post_content )
		);

		$assigned_users = get_post_meta(
			$destination_task_id,
			'assigned_users',
			true
		);
		sort( $assigned_users );
		$expected_users = array( $this->editor, $second_user );
		sort( $expected_users );
		$this->assertSame( $expected_users, $assigned_users );

		$relations = get_post_meta(
			$destination_task_id,
			'_user_date_relations',
			true
		);
		$this->assertCount( 2, $relations );

		$moved_comment = get_comment( $comment_id );
		$this->assertEquals(
			$destination_task_id,
			(int) $moved_comment->comment_post_ID
		);
		$this->assertEquals( 'Editor', $moved_comment->comment_author );
		$this->assertEquals(
			'2025-01-03 10:00:00',
			$moved_comment->comment_date
		);

		$moved_attachment = get_post( $attachment_id );
		$this->assertEquals(
			$destination_task_id,
			(int) $moved_attachment->post_parent
		);

		$this->assertEquals( 'archived', $source_post->post_status );
		$this->assertEquals(
			'[MERGED #' . $destination_task_id . '] Source task',
			$source_post->post_title
		);
		$this->assertEquals(
			$destination_task_id,
			(int) get_post_meta( $source_task_id, 'merged_into', true )
		);
	}

	/**
	 * Test that a merged source task cannot be merged again.
	 */
	public function test_merge_rejects_already_merged_source_task() {
		$destination_task_id = self::factory()->task->create(
			array(
				'post_title' => 'Destination task',
				'board'      => $this->board_id,
			)
		);
		$source_task_id      = self::factory()->task->create(
			array(
				'post_title' => 'Source task',
				'board'      => $this->board_id,
			)
		);

		update_post_meta( $source_task_id, 'merged_into', $destination_task_id );

		$result = Decker_Tasks::merge_tasks(
			$source_task_id,
			$destination_task_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'already_merged', $result->get_error_code() );
	}

	/**
	 * Test that the merge REST endpoint works.
	 */
	public function test_merge_task_via_rest() {
		$destination_task_id = self::factory()->task->create(
			array(
				'post_title' => 'REST destination',
				'board'      => $this->board_id,
			)
		);
		$source_task_id      = self::factory()->task->create(
			array(
				'post_title' => 'REST source',
				'board'      => $this->board_id,
			)
		);

		$request = new WP_REST_Request(
			'POST',
			'/decker/v1/tasks/' . $source_task_id . '/merge'
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'destination_task_id' => $destination_task_id,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['success'] );
		$this->assertEquals(
			$destination_task_id,
			(int) $data['destination_task_id']
		);
		$this->assertEquals( 'archived', get_post_status( $source_task_id ) );
	}

	/**
	 * Create an attachment for a task.
	 *
	 * @param int $task_id The task ID.
	 * @return int
	 */
	private function create_attachment_for_task( int $task_id ): int {
		$temp_file = wp_upload_bits(
			'merge-test.txt',
			null,
			'merge attachment'
		);

		$this->temp_files[] = $temp_file['file'];

		$filetype      = wp_check_filetype( basename( $temp_file['file'] ), null );
		$attachment_id = self::factory()->attachment->create(
			array(
				'file'           => $temp_file['file'],
				'post_mime_type' => $filetype['type'],
				'post_title'     => 'merge-test',
				'post_parent'    => $task_id,
				'post_author'    => $this->editor,
			)
		);

		$this->uploaded_files[] = $attachment_id;

		return $attachment_id;
	}
}
