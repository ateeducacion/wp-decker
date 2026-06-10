<?php
/**
 * Class DeckerAjaxHandlersTest
 *
 * Characterization tests for Decker_Ajax_Handlers::load_tasks_by_date().
 *
 * Pins the current observable behavior of the wp_ajax_load_tasks_by_date
 * endpoint: nonce rejection, date-format validation, permission gating and
 * the happy-path task HTML, all surfaced through wp_send_json_* (which raises
 * WPDieException in the test harness).
 *
 * @package Decker
 */

class DeckerAjaxHandlersTest extends Decker_Test_Base {

	/**
	 * Handler under test.
	 *
	 * @var Decker_Ajax_Handlers
	 */
	protected $handler;

	/**
	 * Editor user (no edit_users capability).
	 *
	 * @var int
	 */
	protected $editor;

	/**
	 * Administrator user (has edit_users capability).
	 *
	 * @var int
	 */
	protected $admin;

	/**
	 * Board term used for created tasks.
	 *
	 * @var WP_Term
	 */
	protected $board;

	/**
	 * Set up test environment.
	 */
	public function set_up() {
		parent::set_up();

		// load_tasks_by_date() ends in wp_send_json_*(); make that catchable.
		$this->enable_wp_send_json_capture();

		// Ensure the custom post type and taxonomy are registered.
		do_action( 'init' );

		$this->handler = new Decker_Ajax_Handlers();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Create the board as administrator.
		wp_set_current_user( $this->admin );
		$this->board = self::factory()->board->create_and_get(
			array(
				'name' => 'Ajax Board',
				'slug' => 'ajax-board',
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		$this->disable_wp_send_json_capture();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Invokes load_tasks_by_date() and returns the decoded JSON response.
	 *
	 * The endpoint always ends in wp_send_json_*, which raises WPDieException
	 * in the test harness, so the JSON is captured from the output buffer.
	 *
	 * @return array Decoded JSON response.
	 */
	private function call_load_tasks_by_date() {
		ob_start();
		try {
			$this->handler->load_tasks_by_date();
		} catch ( WPDieException $e ) {
			$e->getMessage();
		}
		return json_decode( ob_get_clean(), true );
	}

	/**
	 * A missing nonce must be rejected before any other processing.
	 */
	public function test_load_tasks_by_date_rejects_missing_nonce() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertFalse( $json['success'], 'Missing nonce must fail.' );
		$this->assertSame( 'Invalid security token', $json['data'], 'Missing nonce error message mismatch.' );
	}

	/**
	 * An invalid nonce must be rejected.
	 */
	public function test_load_tasks_by_date_rejects_invalid_nonce() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => 'this-is-not-a-valid-nonce',
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertFalse( $json['success'], 'Invalid nonce must fail.' );
		$this->assertSame( 'Invalid security token', $json['data'], 'Invalid nonce error message mismatch.' );
	}

	/**
	 * A malformed date (valid nonce) must be rejected by the format check.
	 */
	public function test_load_tasks_by_date_rejects_malformed_date() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => '01-02-2025',
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertFalse( $json['success'], 'Malformed date must fail.' );
		$this->assertSame( 'Invalid date format', $json['data'], 'Malformed date error message mismatch.' );
	}

	/**
	 * Characterizes the lenient handling of an impossible-but-well-formatted date.
	 *
	 * '2025-13-40' passes the YYYY-MM-DD regex, and DateTime::createFromFormat()
	 * does NOT reject it: PHP overflows the components (month 13 -> the next year,
	 * day 40 -> the next month), returning a valid object. The request therefore
	 * succeeds rather than hitting the "Invalid date" branch.
	 */
	public function test_load_tasks_by_date_accepts_overflowing_date() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => '2025-13-40',
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertTrue( $json['success'], 'A well-formatted overflowing date is accepted (rolled over).' );
		$this->assertIsString( $json['data'], 'A successful response returns the rendered task HTML.' );
	}

	/**
	 * A well-formatted date for the current user must pass the format check.
	 *
	 * An editor requesting their OWN tasks satisfies the first clause of the
	 * permission check (current user id === requested user id).
	 */
	public function test_load_tasks_by_date_accepts_valid_date_for_self() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertTrue( $json['success'], 'Valid request for self must succeed.' );
	}

	/**
	 * An under-privileged user requesting ANOTHER user's tasks is denied.
	 *
	 * Editors lack the edit_users capability, so requesting tasks for a
	 * different user id fails both clauses of user_has_permission().
	 */
	public function test_load_tasks_by_date_denies_other_user_without_capability() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->admin,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertFalse( $json['success'], 'Editor must not load another user\'s tasks.' );
		$this->assertSame( 'Permission denied', $json['data'], 'Permission denied error message mismatch.' );
	}

	/**
	 * A privileged user (edit_users) may request another user's tasks.
	 */
	public function test_load_tasks_by_date_allows_other_user_with_capability() {
		wp_set_current_user( $this->admin );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertTrue( $json['success'], 'Admin must load another user\'s tasks.' );
	}

	/**
	 * The happy path returns task HTML for a date the user marked a task on.
	 */
	public function test_load_tasks_by_date_returns_task_html_for_marked_date() {
		wp_set_current_user( $this->editor );

		$task_id = self::factory()->task->create(
			array(
				'board'      => $this->board->term_id,
				'post_title' => 'Marked task for ajax',
			)
		);

		update_post_meta( $task_id, 'assigned_users', array( $this->editor ) );

		$target_date = gmdate( 'Y-m-d' );
		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => $target_date,
				),
			)
		);

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => $target_date,
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertTrue( $json['success'], 'Happy path must succeed.' );
		$this->assertStringContainsString( 'task-row', $json['data'], 'Response must contain a task row.' );
		$this->assertStringContainsString( 'data-task-id="' . $task_id . '"', $json['data'], 'Response must reference the task id.' );
		$this->assertStringContainsString( 'Marked task for ajax', $json['data'], 'Response must contain the task title.' );
		$this->assertStringNotContainsString( 'No tasks found for this date.', $json['data'], 'Happy path must not render the empty row.' );
	}

	/**
	 * When no task is marked for the date, the empty-row HTML is returned.
	 */
	public function test_load_tasks_by_date_returns_empty_row_when_no_tasks() {
		wp_set_current_user( $this->editor );

		$_POST = array(
			'nonce'   => wp_create_nonce( 'load_tasks_by_date_nonce' ),
			'date'    => gmdate( 'Y-m-d' ),
			'user_id' => $this->editor,
		);

		$json = $this->call_load_tasks_by_date();

		$this->assertTrue( $json['success'], 'Empty result must still succeed.' );
		$this->assertStringContainsString( 'No tasks found for this date.', $json['data'], 'Empty result must render the empty row.' );
		$this->assertStringNotContainsString( 'task-row', $json['data'], 'Empty result must not render a task row.' );
	}
}
