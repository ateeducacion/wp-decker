<?php
/**
 * Class Test_Decker_Task
 *
 * @package Decker
 */


class DeckerTaskTest extends Decker_Test_Base {

	private $editor;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->created_tasks = array();
		$this->created_users = array();

		// Create an editor user
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		// Set current user as editor right away
		wp_set_current_user( $this->editor );
	}

	/**
	 * Tear down after each test.
	 */
	public function tear_down() {
		parent::tear_down();
	}

	/**
	 * Test task creation with valid data.
	 */
	public function test_task_creation() {
		$task_id = self::factory()->task->create(
			array(
				'post_title'   => 'Test Task',
				'post_content' => 'This is a test task description.',
			)
		);

		$this->assertNotEmpty( $task_id, 'Task ID should not be empty.' );

		$task = new Task( $task_id );
		$this->assertInstanceOf( Task::class, $task, 'Task should be an instance of the Task class.' );
		$this->assertEquals( 'Test Task', $task->title, 'Task title does not match.' );
		$this->assertEquals( 'This is a test task description.', $task->description, 'Task description does not match.' );
	}

	/**
	 * Test metadata retrieval for a task.
	 */
	public function test_task_metadata() {
		$task_id = self::factory()->task->create(
			array(
				'stack'        => 'in-progress',
				'max_priority' => true,
			)
		);

		$task = new Task( $task_id );
		$this->assertEquals( 'in-progress', $task->stack, 'Stack metadata does not match.' );
		$this->assertTrue( $task->max_priority, 'Max priority metadata does not match.' );
	}

	/**
	 * Test task duedate.
	 */
	public function test_task_duedate() {
		$duedate = '2024-12-31 00:00:00';

		$task_id = self::factory()->task->create(
			array(
				'duedate' => $duedate,
			)
		);

		$task = new Task( $task_id );
		$this->assertInstanceOf( DateTime::class, $task->duedate, 'Duedate should be an instance of DateTime.' );
		$this->assertEquals( $duedate, $task->duedate->format( 'Y-m-d H:i:s' ), 'Duedate does not match the expected value.' );
	}

	/**
	 * Test task author.
	 */
	public function test_task_author() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$task_id = self::factory()->task->create(
			array(
				'author' => $user_id,
			)
		);

		$task = new Task( $task_id );
		$this->assertEquals( $user_id, $task->author, 'Task author does not match the expected value.' );
	}


	/**
	 * Test task board and labels.
	 */
	public function test_task_labels() {

		$board_id = self::factory()->board->create();

		$label_ids = array(
			self::factory()->label->create(),
			self::factory()->label->create(),
		);

		$task_id = self::factory()->task->create(
			array(
				'board'  => $board_id,
				'labels' => $label_ids,
			)
		);

		$task = new Task( $task_id );

		// Check board.
		$this->assertNotNull( $task->board, 'Task board should not be null.' );
		$this->assertEquals( $board_id, $task->board->id, 'Task board ID does not match.' );

		// Check labels.
		$label_ids_from_task = array_map(
			function ( $label ) {
				return $label->id;
			},
			$task->labels
		);

		foreach ( $label_ids as $label_id ) {
			$this->assertContains( $label_id, $label_ids_from_task, 'Task is missing an expected label.' );
		}
	}

	/**
	 * Test task attachments.
	 */
	public function test_task_attachments() {

		$task_id       = self::factory()->task->create();
		$attachment_id = self::factory()->attachment->create_upload_object( __DIR__ . '/../../../fixtures/sample-1.pdf', 0 );

		update_post_meta( $task_id, 'attachments', array( $attachment_id ) );

		$task = new Task( $task_id );

		$this->assertCount( 1, $task->attachments, 'Task should have exactly one attachment.' );
		// TO-DO: Better test here
		// $this->assertEquals( $attachment_id, $task->attachments[0], 'Attachment ID does not match.' );
	}

	/**
	 * Test people users are ordered without duplicating the responsible user.
	 */
	public function test_get_people_users_places_responsible_first_without_duplicates() {
		$responsible_id = self::factory()->user->create(
			array( 'display_name' => 'Responsible User' )
		);
		$assigned_user_one = self::factory()->user->create(
			array( 'display_name' => 'Assigned User One' )
		);
		$assigned_user_two = self::factory()->user->create(
			array( 'display_name' => 'Assigned User Two' )
		);

		$task_id = self::factory()->task->create(
			array(
				'responsable'    => $responsible_id,
				'assigned_users' => array( $assigned_user_one, $responsible_id, $assigned_user_two ),
			)
		);

		$task = new Task( $task_id );

		$this->assertSame(
			array( $responsible_id, $assigned_user_one, $assigned_user_two ),
			array_map(
				static function ( WP_User $user ): int {
					return $user->ID;
				},
				$task->get_people_users()
			),
			'The people list should show the responsible user first without duplicates.'
		);
	}

	/**
	 * Test the rendered people avatars include the responsible star badge once.
	 */
	public function test_render_people_avatars_marks_the_responsible_user() {
		$responsible_id = self::factory()->user->create(
			array( 'display_name' => 'Responsible User' )
		);
		$assigned_id = self::factory()->user->create(
			array( 'display_name' => 'Assigned User' )
		);

		$task_id = self::factory()->task->create(
			array(
				'responsable'    => $responsible_id,
				'assigned_users' => array( $responsible_id, $assigned_id ),
			)
		);

		$task = new Task( $task_id );

		ob_start();
		$task->render_people_avatars();
		$html = ob_get_clean();
		$assigned_user_html_position    = strpos( $html, 'Assigned User' );
		$responsible_user_html_position = strpos( $html, 'Responsible User' );

		$this->assertSame(
			1,
			substr_count( $html, 'ri-star-s-fill' ),
			'The responsible user should be marked with a single star badge.'
		);
		$this->assertSame(
			2,
			substr_count( $html, 'class="avatar-group-item ' ),
			'The responsible user should not be duplicated in the rendered avatar group.'
		);
		$this->assertNotFalse(
			$assigned_user_html_position,
			'The assigned user name should be present in the rendered avatar group.'
		);
		$this->assertNotFalse(
			$responsible_user_html_position,
			'The responsible user name should be present in the rendered avatar group.'
		);
		$this->assertLessThan(
			$assigned_user_html_position,
			$responsible_user_html_position,
			'The responsible user should be rendered before the other assigned users.'
		);
	}

	/**
	 * Test the rendered card exposes labels through an icon + popover.
	 */
	public function test_render_task_card_displays_labels_with_popover() {
		$label_one = self::factory()->label->create(
			array(
				'name'  => 'Urgent',
				'color' => '#ff0000',
			)
		);
		$label_two = self::factory()->label->create(
			array(
				'name'  => 'Backend',
				'color' => '#00aabb',
			)
		);

		$task_id = self::factory()->task->create(
			array(
				'labels' => array( $label_one, $label_two ),
			)
		);

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_card();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'ri-price-tag-3-line',
			$html,
			'The card should render the label icon next to the other counters.'
		);
		$this->assertStringContainsString(
			'decker-labels-popover',
			$html,
			'The card should mark the labels counter as a popover trigger when labels exist.'
		);
		$this->assertStringContainsString(
			'data-decker-labels-content',
			$html,
			'The labels counter must carry the popover HTML content for the JS initializer to read.'
		);
		$this->assertStringContainsString(
			'decker-labels-popover-close',
			$html,
			'The popover body must include a close button so the user can dismiss it.'
		);
		$this->assertStringNotContainsString(
			'data-bs-toggle="popover"',
			$html,
			'The labels counter must not be auto-initialized by the generic popover bootstrap; the dedicated initializer applies sanitize:false.'
		);
		$this->assertStringContainsString(
			'data-decker-labels-count="2"',
			$html,
			'The labels counter should expose the number of labels for client-side hooks.'
		);
		$this->assertStringContainsString(
			'Urgent',
			$html,
			'The popover content should include each label name.'
		);
		$this->assertStringContainsString(
			'Backend',
			$html,
			'The popover content should include each label name.'
		);
		$this->assertStringContainsString(
			'#ff0000',
			$html,
			'The popover content should preserve each label color.'
		);
		$this->assertStringContainsString(
			'#00aabb',
			$html,
			'The popover content should preserve each label color.'
		);
	}

	/**
	 * Test a task with no labels renders the inert icon without popover wiring.
	 */
	public function test_render_task_card_without_labels_renders_inert_counter() {
		$task_id = self::factory()->task->create();

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_card();
		$html = ob_get_clean();

		$this->assertStringContainsString(
			'ri-price-tag-3-line',
			$html,
			'The label icon should still appear when the task has no labels.'
		);
		$this->assertStringNotContainsString(
			'decker-labels-popover"',
			$html,
			'A labelless task must not be wired as a popover trigger.'
		);
		$this->assertStringNotContainsString(
			'data-decker-labels-count',
			$html,
			'A labelless task must not expose the labels-count data attribute.'
		);
		$this->assertStringNotContainsString(
			'decker-labels-popover-close',
			$html,
			'A labelless task must not render a close button (there is no popover to close).'
		);
	}

	/**
	 * Render the full contextual menu for an admin user and lock its item order.
	 */
	public function test_render_task_menu_shows_full_menu_for_admin_in_order() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$task_id = self::factory()->task->create( array( 'author' => $admin ) );

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_menu();
		$html = ob_get_clean();

		$positions = array(
			'copy-task-url'                  => strpos( $html, 'copy-task-url' ),
			'data-bs-target="#task-modal"'   => strpos( $html, 'data-bs-target="#task-modal"' ),
			'ri-wordpress-line'              => strpos( $html, 'ri-wordpress-line' ),
			'clone-task'                     => strpos( $html, 'clone-task' ),
			'merge-task'                     => strpos( $html, 'merge-task' ),
			'archive-task'                   => strpos( $html, 'archive-task' ),
			'assign-to-me'                   => strpos( $html, 'assign-to-me' ),
			'leave-task'                     => strpos( $html, 'leave-task' ),
		);

		$previous = -1;
		foreach ( $positions as $marker => $position ) {
			$this->assertNotFalse( $position, "The menu should contain {$marker}." );
			$this->assertGreaterThan(
				$previous,
				$position,
				"The menu item {$marker} is out of order."
			);
			$previous = $position;
		}

		$this->assertStringContainsString( 'assign-to-me "', $html, 'Assign-to-me should be visible (not hidden) when unassigned.' );
		$this->assertStringContainsString( 'leave-task hidden"', $html, 'Leave should be hidden when unassigned.' );
		$this->assertStringContainsString( 'mark-for-today hidden"', $html, 'Mark-for-today should be hidden when unassigned.' );
		$this->assertStringContainsString( 'unmark-for-today hidden"', $html, 'Unmark-for-today should be hidden when unassigned.' );
		$this->assertStringContainsString( 'dropdown float-end mt-2', $html, 'The full menu uses the float-end dropdown wrapper.' );
		$this->assertStringContainsString( 'dropdown-toggle', $html, 'The full menu renders the dropdown toggle.' );
	}

	/**
	 * Render the reduced card-mode menu and lock the omitted items.
	 */
	public function test_render_task_menu_in_card_mode_renders_reduced_menu() {
		$task_id = self::factory()->task->create();

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_menu( true );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'copy-task-url', $html );
		$this->assertStringContainsString( 'clone-task', $html );
		$this->assertStringContainsString( 'archive-task', $html );

		$this->assertStringNotContainsString( 'data-bs-target="#task-modal"', $html );
		$this->assertStringNotContainsString( 'assign-to-me', $html );
		$this->assertStringNotContainsString( 'leave-task', $html );
		$this->assertStringNotContainsString( 'mark-for-today', $html );
		$this->assertStringNotContainsString( 'unmark-for-today', $html );
		$this->assertStringNotContainsString( 'dropdown-toggle', $html );

		$this->assertStringStartsWith( '<div class="dropdown-menu dropdown-menu-end">', trim( $html ) );
	}

	/**
	 * Archived tasks expose Unarchive and hide Merge.
	 */
	public function test_render_task_menu_for_archived_task_shows_unarchive_and_hides_merge() {
		$task_id = self::factory()->task->create();
		wp_update_post(
			array(
				'ID'          => $task_id,
				'post_status' => 'archived',
			)
		);

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_menu();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'unarchive-task', $html );
		$this->assertSame( 1, substr_count( $html, 'archive-task' ), 'Only the unarchive-task occurrence of "archive-task" should be present.' );
		$this->assertStringNotContainsString( 'merge-task', $html );
	}

	/**
	 * Lock the assigned/marked-for-today menu state matrix.
	 */
	public function test_render_task_menu_marks_today_state_for_assigned_user() {
		$task_id = self::factory()->task->create(
			array(
				'assigned_users' => array( $this->editor ),
			)
		);

		update_post_meta(
			$task_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->editor,
					'date'    => ( new DateTime() )->format( 'Y-m-d' ),
				),
			)
		);

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_menu();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'assign-to-me hidden"', $html, 'Assign-to-me should be hidden for an assigned user.' );
		$this->assertStringContainsString( 'leave-task "', $html, 'Leave should be visible for an assigned user.' );
		$this->assertStringContainsString( 'mark-for-today hidden"', $html, 'Mark-for-today should be hidden when already marked.' );
		$this->assertStringContainsString( 'unmark-for-today "', $html, 'Unmark-for-today should be visible when already marked.' );
	}

	/**
	 * Lock priority badge and overdue/today css classes on the card.
	 */
	public function test_render_task_card_shows_priority_and_overdue_state() {
		$task_a_id = self::factory()->task->create(
			array(
				'max_priority' => true,
				'duedate'      => ( new DateTime() )->modify( '-1 day' )->format( 'Y-m-d 00:00:00' ),
			)
		);

		$task_a = new Task( $task_a_id );

		ob_start();
		$task_a->render_task_card();
		$html_a = ob_get_clean();

		$this->assertStringContainsString( 'bg-danger-subtle text-danger', $html_a );
		$this->assertStringContainsString( '🔥', $html_a );
		$this->assertStringContainsString( 'due-past', $html_a );
		$this->assertStringContainsString( 'title="' . $task_a->get_formatted_date() . '"', $html_a );

		$task_b_id = self::factory()->task->create(
			array(
				'duedate' => ( new DateTime() )->format( 'Y-m-d 00:00:00' ),
			)
		);

		$task_b = new Task( $task_b_id );

		ob_start();
		$task_b->render_task_card();
		$html_b = ob_get_clean();

		$this->assertStringContainsString( 'due-today', $html_b );
		$this->assertStringContainsString( 'bg-secondary-subtle text-secondary', $html_b );
		$this->assertStringContainsString( 'Normal', $html_b );
	}

	/**
	 * Lock board background painting when requested.
	 */
	public function test_render_task_card_paints_board_background_when_requested() {
		$board_id = self::factory()->board->create( array( 'color' => '#336699' ) );
		$task_id  = self::factory()->task->create( array( 'board' => $board_id ) );

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_card( true );
		$html_painted = ob_get_clean();

		$this->assertStringContainsString( $task->pastelize_color( '#336699' ), $html_painted );

		ob_start();
		$task->render_task_card();
		$html_plain = ob_get_clean();

		$this->assertStringNotContainsString( 'background-color', $html_plain );
	}

	/**
	 * Lock the hidden-task striped vs flat background branches.
	 */
	public function test_render_task_card_paints_hidden_task_with_stripes() {
		$board_id = self::factory()->board->create( array( 'color' => '#336699' ) );
		$task_id  = self::factory()->task->create(
			array(
				'board'  => $board_id,
				'hidden' => true,
			)
		);

		$task = new Task( $task_id );

		ob_start();
		$task->render_task_card( true );
		$html_striped = ob_get_clean();

		$this->assertStringContainsString( 'repeating-linear-gradient', $html_striped );
		$this->assertStringContainsString( 'gainsboro', $html_striped );

		ob_start();
		$task->render_task_card();
		$html_flat = ob_get_clean();

		$this->assertStringContainsString( 'background-color: gainsboro;', $html_flat );
		$this->assertStringNotContainsString( 'repeating-linear-gradient', $html_flat );
	}

	/**
	 * Lock the comments counter popover wiring.
	 */
	public function test_render_task_card_counts_comments_with_popover() {
		$task_with_comments = self::factory()->task->create();
		self::factory()->comment->create_post_comments( $task_with_comments, 2 );

		$task = new Task( $task_with_comments );

		ob_start();
		$task->render_task_card();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'decker-comments-popover', $html );
		$this->assertStringContainsString( 'data-decker-comments-count="2"', $html );
		$this->assertStringContainsString( '<b>2</b>', $html );

		$task_without_comments = self::factory()->task->create();

		$task_empty = new Task( $task_without_comments );

		ob_start();
		$task_empty->render_task_card();
		$html_empty = ob_get_clean();

		$this->assertStringContainsString( '<b>0</b>', $html_empty );
		$this->assertStringNotContainsString( 'decker-comments-popover', $html_empty );
	}

	/**
	 * Lock the default constructor behavior for a brand new task.
	 */
	public function test_construct_without_input_uses_current_user_defaults() {
		wp_set_current_user( $this->editor );

		$task = new Task();

		$this->assertSame( 0, $task->ID );
		$this->assertSame( $this->editor, $task->author );
		$this->assertSame( 'to-do', $task->stack );
		$this->assertNull( $task->board );
		$this->assertSame( array(), $task->labels );
		$this->assertNull( $task->duedate );
	}

	/**
	 * Lock the post-type guard in the constructor.
	 */
	public function test_construct_throws_on_foreign_post_type() {
		$post_id = self::factory()->post->create();

		$this->expectException( Exception::class );

		ob_start();
		try {
			new Task( $post_id );
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Lock the responsable today flag hydration.
	 */
	public function test_construct_sets_responsable_with_today_flag() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$task_today_id = self::factory()->task->create(
			array(
				'responsable' => $user_id,
			)
		);
		update_post_meta(
			$task_today_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $user_id,
					'date'    => ( new DateTime() )->format( 'Y-m-d' ),
				),
			)
		);

		$task_today = new Task( $task_today_id );

		$this->assertSame( $user_id, $task_today->responsable->ID );
		$this->assertTrue( $task_today->responsable->today );

		$task_old_id = self::factory()->task->create(
			array(
				'responsable' => $user_id,
			)
		);
		update_post_meta(
			$task_old_id,
			'_user_date_relations',
			array(
				array(
					'user_id' => $user_id,
					'date'    => '2000-01-01',
				),
			)
		);

		$task_old = new Task( $task_old_id );

		$this->assertSame( $user_id, $task_old->responsable->ID );
		$this->assertFalse( $task_old->responsable->today );
	}
}
