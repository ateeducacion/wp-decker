<?php
/**
 * Tests for the Decker WordPress Abilities API adapter.
 *
 * @package Decker
 */

/**
 * Test the Decker abilities adapter and execution service.
 */
class DeckerAbilitiesTest extends Decker_Test_Base {

	/** @var Decker_Abilities */
	private $abilities;

	/** @var Decker_Ability_Service */
	private $service;

	/** @var int */
	private $editor_id;

	/** @var int */
	private $board_id;

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		do_action( 'init' );
		$this->abilities = new Decker_Abilities();
		$this->service   = new Decker_Ability_Service();
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		$this->board_id = self::factory()->board->create();
	}

	/**
	 * Feature detection must match the installed WordPress API.
	 */
	public function test_feature_detection_is_safe() {
		$this->assertSame(
			function_exists( 'wp_register_ability_category' ) && function_exists( 'wp_register_ability' ),
			Decker_Abilities::is_available()
		);
	}

	/**
	 * Definitions must expose verified operations and strict schemas.
	 */
	public function test_definitions_are_strict_and_annotated() {
		$definitions = $this->abilities->get_ability_definitions();
		$this->assertSame(
			array(
				'decker/list-tasks',
				'decker/get-task',
				'decker/create-task',
				'decker/update-task',
				'decker/move-task',
				'decker/archive-task',
				'decker/list-boards',
				'decker/search-knowledge-base',
				'decker/get-knowledge-article',
			),
			array_keys( $definitions )
		);

		foreach ( $definitions as $definition ) {
			$this->assertFalse( $definition['output_schema']['additionalProperties'] );
			$this->assertArrayHasKey( 'annotations', $definition['meta'] );
			if ( isset( $definition['input_schema'] ) ) {
				$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			}
		}
	}

	/**
	 * Schema validation must reject missing, invalid, and unknown values.
	 */
	public function test_create_schema_rejects_invalid_input() {
		$schema = $this->abilities->get_ability_definitions()['decker/create-task']['input_schema'];
		$this->assertWPError( rest_validate_value_from_schema( array( 'board_id' => $this->board_id ), $schema, 'input' ) );
		$this->assertWPError(
			rest_validate_value_from_schema(
				array( 'title' => 'Task', 'board_id' => $this->board_id, 'stack' => 'invalid' ),
				$schema,
				'input'
			)
		);
		$this->assertWPError(
			rest_validate_value_from_schema(
				array( 'title' => 'Task', 'board_id' => $this->board_id, 'unknown' => true ),
				$schema,
				'input'
			)
		);
	}

	/**
	 * Unauthenticated and low-role users must be rejected.
	 */
	public function test_permissions_reject_unauthorized_users() {
		wp_set_current_user( 0 );
		$this->assertWPError( $this->service->can_list_tasks() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( $this->service->can_create_task() );
	}

	/**
	 * Authorized writes must delegate to the existing domain behavior.
	 */
	public function test_authorized_task_lifecycle() {
		wp_set_current_user( $this->editor_id );
		$created_ids = array();
		$listener    = static function ( $task_id ) use ( &$created_ids ) {
			$created_ids[] = (int) $task_id;
		};
		add_action( 'decker_task_created', $listener );

		$task = $this->service->create_task(
			array(
				'title'               => 'Ability task',
				'board_id'            => $this->board_id,
				'responsible_user_id' => $this->editor_id,
				'due_date'            => '2026-08-01',
			)
		);
		remove_action( 'decker_task_created', $listener );
		$this->assertNotWPError( $task );
		$this->assertSame( array( $task['id'] ), $created_ids );

		$task = $this->service->update_task( array( 'task_id' => $task['id'], 'title' => 'Updated task' ) );
		$this->assertSame( 'Updated task', $task['title'] );
		$this->assertSame( '2026-08-01', $task['due_date'] );

		$task = $this->service->move_task( array( 'task_id' => $task['id'], 'stack' => 'in-progress' ) );
		$this->assertSame( 'in-progress', $task['stack'] );

		$task = $this->service->archive_task( array( 'task_id' => $task['id'], 'archived' => true ) );
		$this->assertSame( 'archived', $task['status'] );
	}

	/**
	 * Hidden tasks must not leak to unrelated editors.
	 */
	public function test_hidden_tasks_are_not_exposed() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create( array( 'post_title' => 'Hidden task', 'board' => $this->board_id ) );
		update_post_meta( $task_id, 'hidden', '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertWPError( $this->service->can_read_task( array( 'task_id' => $task_id ) ) );
		$result = $this->service->list_tasks( array( 'search' => 'Hidden task' ) );
		$this->assertSame( array(), $result['tasks'] );
	}

	/**
	 * Existing REST routes must remain available.
	 */
	public function test_existing_rest_routes_remain_available() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$routes = $wp_rest_server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/tasks', $routes );
		$this->assertArrayHasKey( '/decker/v1/tasks/search', $routes );
	}

	/**
	 * Supported WordPress versions must register abilities.
	 */
	public function test_abilities_register_when_api_is_available() {
		if ( ! Decker_Abilities::is_available() || ! function_exists( 'wp_has_ability' ) ) {
			$this->markTestSkipped( 'The test WordPress version does not include the Abilities API.' );
		}

		if ( ! wp_has_ability( 'decker/list-tasks' ) ) {
			$this->abilities->register_category();
			$this->abilities->register_abilities();
		}
		$this->assertTrue( wp_has_ability( 'decker/list-tasks' ) );
		$this->assertTrue( wp_has_ability( 'decker/create-task' ) );
	}

	/**
	 * Missing authentication is 401, not 403.
	 */
	public function test_authentication_error_uses_401_status() {
		wp_set_current_user( 0 );
		$error = $this->service->can_list_tasks();
		$this->assertWPError( $error );
		$this->assertSame( 'decker_authentication_required', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	/**
	 * Totals and pages must describe only the tasks the user can see, and pages
	 * must never be padded with or emptied by filtered-out tasks.
	 */
	public function test_pagination_counts_only_visible_tasks() {
		$token = 'PAGINATIONTOKEN';
		wp_set_current_user( $this->editor_id );
		for ( $i = 0; $i < 3; $i++ ) {
			self::factory()->task->create(
				array(
					'post_title' => "$token visible $i",
					'board'      => $this->board_id,
				)
			);
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$hidden_id = self::factory()->task->create(
				array(
					'post_title' => "$token hidden $i",
					'board'      => $this->board_id,
				)
			);
			update_post_meta( $hidden_id, 'hidden', '1' );
		}

		// A second editor, unrelated to the hidden tasks, may only see the three visible ones.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$page_one = $this->service->list_tasks(
			array(
				'search'   => $token,
				'page'     => 1,
				'per_page' => 2,
			)
		);
		$this->assertSame( 3, $page_one['total'] );
		$this->assertSame( 2, $page_one['total_pages'] );
		$this->assertCount( 2, $page_one['tasks'] );

		$page_two = $this->service->list_tasks(
			array(
				'search'   => $token,
				'page'     => 2,
				'per_page' => 2,
			)
		);
		$this->assertSame( 3, $page_two['total'] );
		$this->assertCount( 1, $page_two['tasks'] );
	}

	/**
	 * A user related to a hidden task can list it with include_hidden, matching get-task.
	 */
	public function test_related_user_can_list_own_hidden_task() {
		$assignee_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create(
			array(
				'post_title'     => 'HIDDENLISTTOKEN task',
				'board'          => $this->board_id,
				'assigned_users' => array( $assignee_id ),
			)
		);
		update_post_meta( $task_id, 'hidden', '1' );

		wp_set_current_user( $assignee_id );
		$this->assertNotWPError( $this->service->can_read_task( array( 'task_id' => $task_id ) ) );

		$default = $this->service->list_tasks( array( 'search' => 'HIDDENLISTTOKEN' ) );
		$this->assertSame( array(), $default['tasks'] );

		$with_hidden = $this->service->list_tasks( array( 'search' => 'HIDDENLISTTOKEN', 'include_hidden' => true ) );
		$this->assertSame( array( $task_id ), wp_list_pluck( $with_hidden['tasks'], 'id' ) );
	}

	/**
	 * Updates must not wipe the Nextcloud card link the domain method rewrites.
	 */
	public function test_update_preserves_nextcloud_card_id() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create( array( 'post_title' => 'Synced task', 'board' => $this->board_id ) );
		update_post_meta( $task_id, 'id_nextcloud_card', 4242 );

		$updated = $this->service->update_task( array( 'task_id' => $task_id, 'title' => 'Synced task edited' ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( '4242', (string) get_post_meta( $task_id, 'id_nextcloud_card', true ) );
	}

	/**
	 * An explicit empty label set must clear the task's labels.
	 */
	public function test_update_clears_labels_with_empty_array() {
		wp_set_current_user( $this->editor_id );
		$label_id = self::factory()->label->create();
		$task_id  = self::factory()->task->create(
			array(
				'post_title' => 'Labelled task',
				'board'      => $this->board_id,
				'labels'     => array( $label_id ),
			)
		);
		$this->assertContains( $label_id, wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) ) );

		$updated = $this->service->update_task( array( 'task_id' => $task_id, 'label_ids' => array() ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( array(), $updated['label_ids'] );
		$this->assertSame( array(), wp_get_post_terms( $task_id, 'decker_label', array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Unspecified fields keep their stored values across an update.
	 */
	public function test_update_preserves_unspecified_fields() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create(
			array(
				'post_title'  => 'Preserve task',
				'board'       => $this->board_id,
				'responsable' => $this->editor_id,
				'duedate'     => '2026-09-15',
			)
		);

		$updated = $this->service->update_task( array( 'task_id' => $task_id, 'title' => 'Preserve task edited' ) );
		$this->assertNotWPError( $updated );
		$this->assertSame( 'Preserve task edited', $updated['title'] );
		$this->assertSame( '2026-09-15', $updated['due_date'] );
		$this->assertSame( $this->editor_id, $updated['responsible_user_id'] );
	}

	/**
	 * Moving a task to another board must change its board.
	 */
	public function test_move_task_changes_board() {
		wp_set_current_user( $this->editor_id );
		$other_board = self::factory()->board->create();
		$task_id     = self::factory()->task->create( array( 'post_title' => 'Movable', 'board' => $this->board_id ) );

		$moved = $this->service->move_task( array( 'task_id' => $task_id, 'board_id' => $other_board ) );
		$this->assertNotWPError( $moved );
		$this->assertSame( $other_board, $moved['board_id'] );
	}

	/**
	 * Archiving is reversible: an explicit false restores the task.
	 */
	public function test_archive_task_can_restore() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create( array( 'post_title' => 'Archivable', 'board' => $this->board_id ) );

		$archived = $this->service->archive_task( array( 'task_id' => $task_id, 'archived' => true ) );
		$this->assertSame( 'archived', $archived['status'] );

		$restored = $this->service->archive_task( array( 'task_id' => $task_id, 'archived' => false ) );
		$this->assertSame( 'publish', $restored['status'] );
	}

	/**
	 * Invalid dates are rejected before any write.
	 */
	public function test_invalid_due_date_is_rejected() {
		wp_set_current_user( $this->editor_id );
		$error = $this->service->create_task(
			array(
				'title'    => 'Bad date',
				'board_id' => $this->board_id,
				'due_date' => '2026-13-40',
			)
		);
		$this->assertWPError( $error );
		$this->assertSame( 'decker_invalid_due_date', $error->get_error_code() );
	}

	/**
	 * A write must be rejected while another user holds an active edit lock.
	 */
	public function test_update_rejects_task_locked_by_another_user() {
		wp_set_current_user( $this->editor_id );
		$task_id = self::factory()->task->create( array( 'post_title' => 'Locked task', 'board' => $this->board_id ) );

		// Another editor takes an active lock; locking is enabled by default in tests.
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new Decker_Task_Locks() )->acquire_lock( $task_id, $other );

		$error = $this->service->update_task( array( 'task_id' => $task_id, 'title' => 'Should not save' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'decker_task_locked', $error->get_error_code() );
		$this->assertSame( 'Locked task', get_post_field( 'post_title', $task_id ) );
	}

	/**
	 * Knowledge-base search returns bounded summaries; retrieval returns full content.
	 */
	public function test_knowledge_base_search_summarizes_and_article_returns_full_content() {
		wp_set_current_user( $this->editor_id );
		$article_id = wp_insert_post(
			array(
				'post_type'    => 'decker_kb',
				'post_status'  => 'publish',
				'post_author'  => $this->editor_id,
				'post_title'   => 'KBTOKEN handbook',
				'post_content' => str_repeat( 'Detailed body content. ', 40 ),
			)
		);
		wp_set_object_terms( $article_id, $this->board_id, 'decker_board' );

		$search = $this->service->search_knowledge_base( array( 'search' => 'KBTOKEN' ) );
		$this->assertNotWPError( $search );
		$this->assertSame( array( $article_id ), wp_list_pluck( $search['articles'], 'id' ) );
		$this->assertArrayHasKey( 'excerpt', $search['articles'][0] );
		$this->assertArrayNotHasKey( 'content', $search['articles'][0] );

		$article = $this->service->get_knowledge_article( array( 'article_id' => $article_id ) );
		$this->assertNotWPError( $article );
		$this->assertSame( $article_id, $article['id'] );
		$this->assertStringContainsString( 'Detailed body content', $article['content'] );

		$missing = $this->service->get_knowledge_article( array( 'article_id' => 99999 ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'decker_article_not_found', $missing->get_error_code() );
	}
}
