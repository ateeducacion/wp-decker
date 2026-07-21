<?php
/**
 * Edge case tests for the Task model.
 *
 * @package Decker
 */

/**
 * Covers boundary/invalid-input paths in the Task class that complement
 * the happy-path tests in DeckerTaskTest.
 */
class TaskEdgeCasesTest extends Decker_Test_Base {

	/**
	 * Editor user created for the test run.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Set up fixtures before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	// -----------------------------------------------------------------------
	// Constructor / resolve_input
	// -----------------------------------------------------------------------

	/**
	 * Passing a positive integer that does not correspond to any post returns
	 * a Task with ID 0 (the constructor silently returns when get_post() is
	 * null, leaving default values in place).
	 */
	public function test_construct_with_nonexistent_id_returns_empty_task() {
		$task = new Task( 999999 );

		$this->assertSame( 0, $task->ID );
	}

	/**
	 * Passing a WP_Post of the wrong post type throws an Exception.
	 */
	public function test_construct_with_wrong_post_type_throws_exception() {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post    = get_post( $page_id );

		$this->expectException( Exception::class );

		ob_start();
		try {
			new Task( $post );
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * No-arg constructor uses the current user as default author and
	 * returns a task with sensible zero-state defaults.
	 */
	public function test_construct_no_args_sets_defaults() {
		$task = new Task();

		$this->assertSame( 0, $task->ID );
		$this->assertSame( $this->editor_id, $task->author );
		$this->assertSame( 'to-do', $task->stack );
		$this->assertNull( $task->duedate );
		$this->assertNull( $task->board );
		$this->assertSame( array(), $task->labels );
		$this->assertFalse( $task->max_priority );
		$this->assertFalse( $task->hidden );
	}

	// -----------------------------------------------------------------------
	// pastelize_color
	// -----------------------------------------------------------------------

	/**
	 * A null color argument returns the default fallback grey.
	 */
	public function test_pastelize_color_with_null_returns_default() {
		$task = new Task();

		$this->assertSame( '#cccccc', $task->pastelize_color( null ) );
	}

	/**
	 * An empty string returns the default fallback grey.
	 */
	public function test_pastelize_color_with_empty_string_returns_default() {
		$task = new Task();

		$this->assertSame( '#cccccc', $task->pastelize_color( '' ) );
	}

	/**
	 * A 3-character shorthand hex is not a valid 6-character hex and returns
	 * the default fallback grey.
	 */
	public function test_pastelize_color_with_three_char_hex_returns_default() {
		$task = new Task();

		$this->assertSame( '#cccccc', $task->pastelize_color( '#f00' ) );
	}

	/**
	 * A valid 6-character hex color is averaged with white and returned.
	 */
	public function test_pastelize_color_with_valid_hex_blends_with_white() {
		$task = new Task();

		// #000000 averaged with #ffffff → #7f7f7f (128, 128, 128 → #808080).
		$result = $task->pastelize_color( '#000000' );

		$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $result );
		// Result must be lighter than the original colour.
		$brightness = hexdec( substr( $result, 1, 2 ) );
		$this->assertGreaterThan( 0, $brightness, 'Pastelised black must be lighter than pure black.' );
	}

	/**
	 * A hex colour without the leading hash is also handled correctly.
	 */
	public function test_pastelize_color_accepts_hex_without_hash() {
		$task = new Task();

		$result_with    = $task->pastelize_color( '#ffffff' );
		$result_without = $task->pastelize_color( 'ffffff' );

		$this->assertSame( $result_with, $result_without );
	}

	// -----------------------------------------------------------------------
	// get_formatted_date
	// -----------------------------------------------------------------------

	/**
	 * A task with no due date returns an empty string from get_formatted_date().
	 */
	public function test_get_formatted_date_returns_empty_when_no_duedate() {
		$task_id = self::factory()->task->create( array( 'duedate' => '' ) );
		delete_post_meta( $task_id, 'duedate' );

		$task = new Task( $task_id );

		$this->assertSame( '', $task->get_formatted_date() );
	}

	/**
	 * A task with a due date returns the date in Y-m-d format.
	 */
	public function test_get_formatted_date_returns_ymd_when_duedate_set() {
		$task_id = self::factory()->task->create(
			array( 'duedate' => '2025-06-15 00:00:00' )
		);

		$task = new Task( $task_id );

		$this->assertSame( '2025-06-15', $task->get_formatted_date() );
	}

	// -----------------------------------------------------------------------
	// get_relative_time
	// -----------------------------------------------------------------------

	/**
	 * A task with no due date returns the "No due date" string.
	 */
	public function test_get_relative_time_returns_no_due_date_string_when_unset() {
		$task_id = self::factory()->task->create();
		delete_post_meta( $task_id, 'duedate' );

		$task = new Task( $task_id );

		$this->assertSame( __( 'No due date', 'decker' ), $task->get_relative_time() );
	}

	/**
	 * A task due today returns the "Today" string.
	 */
	public function test_get_relative_time_returns_today_for_current_date() {
		$today   = ( new DateTime( 'today' ) )->format( 'Y-m-d 00:00:00' );
		$task_id = self::factory()->task->create( array( 'duedate' => $today ) );

		$task = new Task( $task_id );

		$this->assertSame( __( 'Today', 'decker' ), $task->get_relative_time() );
	}

	/**
	 * A task due yesterday returns the "Yesterday" string.
	 */
	public function test_get_relative_time_returns_yesterday_for_previous_date() {
		$yesterday = ( new DateTime( 'yesterday' ) )->format( 'Y-m-d 00:00:00' );
		$task_id   = self::factory()->task->create( array( 'duedate' => $yesterday ) );

		$task = new Task( $task_id );

		$this->assertSame( __( 'Yesterday', 'decker' ), $task->get_relative_time() );
	}

	/**
	 * A task due tomorrow returns the "Tomorrow" string.
	 */
	public function test_get_relative_time_returns_tomorrow_for_next_date() {
		$tomorrow = ( new DateTime( 'tomorrow' ) )->format( 'Y-m-d 00:00:00' );
		$task_id  = self::factory()->task->create( array( 'duedate' => $tomorrow ) );

		$task = new Task( $task_id );

		$this->assertSame( __( 'Tomorrow', 'decker' ), $task->get_relative_time() );
	}

	/**
	 * A task due far in the past returns an "… ago" style string.
	 */
	public function test_get_relative_time_returns_ago_string_for_past_dates() {
		$old_date = ( new DateTime( '-30 days' ) )->format( 'Y-m-d 00:00:00' );
		$task_id  = self::factory()->task->create( array( 'duedate' => $old_date ) );

		$task = new Task( $task_id );

		$this->assertStringContainsString( 'ago', $task->get_relative_time() );
	}

	/**
	 * A task due far in the future returns an "in …" style string.
	 */
	public function test_get_relative_time_returns_in_string_for_future_dates() {
		$future_date = ( new DateTime( '+30 days' ) )->format( 'Y-m-d 00:00:00' );
		$task_id     = self::factory()->task->create( array( 'duedate' => $future_date ) );

		$task = new Task( $task_id );

		$this->assertStringContainsString( 'in', $task->get_relative_time() );
	}

	// -----------------------------------------------------------------------
	// get_user_history_with_objects
	// -----------------------------------------------------------------------

	/**
	 * A task with no _user_date_relations meta returns an empty history array.
	 */
	public function test_get_user_history_with_objects_returns_empty_array_when_no_relations() {
		$task_id = self::factory()->task->create();
		delete_post_meta( $task_id, '_user_date_relations' );

		$task = new Task( $task_id );

		$this->assertSame( array(), $task->get_user_history_with_objects() );
	}

	/**
	 * Relations pointing to deleted/non-existent users are silently skipped.
	 */
	public function test_get_user_history_with_objects_skips_missing_users() {
		$task_id    = self::factory()->task->create();
		$ghost_uid  = 999999;

		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $ghost_uid,
					'date'    => '2025-01-01',
				),
			)
		);

		$task = new Task( $task_id );

		$this->assertSame( array(), $task->get_user_history_with_objects() );
	}

	/**
	 * Valid relations with existing users are returned with the correct shape.
	 */
	public function test_get_user_history_with_objects_returns_valid_relations() {
		$task_id = self::factory()->task->create();
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $user_id,
					'date'    => '2025-03-10',
				),
			)
		);

		$task    = new Task( $task_id );
		$history = $task->get_user_history_with_objects();

		$this->assertCount( 1, $history );
		$this->assertInstanceOf( WP_User::class, $history[0]['user'] );
		$this->assertSame( $user_id, $history[0]['user']->ID );
		$this->assertSame( '2025-03-10', $history[0]['date'] );
	}

	// -----------------------------------------------------------------------
	// is_current_user_assigned_to_task
	// -----------------------------------------------------------------------

	/**
	 * A user not in the assigned_users list is not considered assigned.
	 */
	public function test_is_current_user_assigned_returns_false_when_not_assigned() {
		$other_user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$task_id    = self::factory()->task->create(
			array( 'assigned_users' => array( $other_user ) )
		);

		wp_set_current_user( $this->editor_id );
		$task = new Task( $task_id );

		$this->assertFalse( $task->is_current_user_assigned_to_task() );
	}

	/**
	 * A user in the assigned_users list is considered assigned.
	 */
	public function test_is_current_user_assigned_returns_true_when_assigned() {
		$task_id = self::factory()->task->create(
			array( 'assigned_users' => array( $this->editor_id ) )
		);

		wp_set_current_user( $this->editor_id );
		$task = new Task( $task_id );

		$this->assertTrue( $task->is_current_user_assigned_to_task() );
	}

	// -----------------------------------------------------------------------
	// Board absence
	// -----------------------------------------------------------------------

	/**
	 * A task with no board taxonomy term assigned has a null board property.
	 */
	public function test_task_with_no_board_has_null_board() {
		$task_id = self::factory()->task->create();
		wp_set_object_terms( $task_id, array(), 'decker_board' );

		$task = new Task( $task_id );

		$this->assertNull( $task->board );
	}
}
