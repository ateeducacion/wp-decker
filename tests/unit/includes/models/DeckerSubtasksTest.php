<?php
/**
 * Unit tests for the subtask list model.
 *
 * Covers the sanitization every write goes through and the progress counting
 * the card counter and the pane both read.
 *
 * @package Decker
 */

/**
 * Class DeckerSubtasksTest
 */
class DeckerSubtasksTest extends Decker_Test_Base {

	/**
	 * Task owner.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * A user assigned to the task.
	 *
	 * @var int
	 */
	private $assignee_id;

	/**
	 * A user who is not on the task.
	 *
	 * @var int
	 */
	private $outsider_id;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->user_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assignee_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->outsider_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->user_id );

		$this->task_id = self::factory()->task->create(
			array(
				'board'          => self::factory()->board->create(),
				'responsable'    => $this->user_id,
				'assigned_users' => array( $this->assignee_id ),
			)
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		update_option( 'decker_settings', array() );
		parent::tear_down();
	}

	/**
	 * A promoted item is read as the task it became, not as the stored copy.
	 */
	public function test_a_promoted_item_is_read_from_its_task() {
		$child = self::factory()->task->create(
			array(
				'board'       => self::factory()->board->create(),
				'post_title'  => 'Renamed on its own card',
				'responsable' => $this->outsider_id,
				'duedate'     => '2026-12-24',
			)
		);
		update_post_meta( $child, 'stack', 'in-progress' );

		update_post_meta(
			$this->task_id,
			Decker_Subtasks::META_KEY,
			array(
				array(
					'id'       => 1,
					'title'    => 'What it was called when it was promoted',
					'status'   => 'to-do',
					'assignee' => $this->assignee_id,
					'duedate'  => '',
					'task_id'  => $child,
				),
			)
		);

		$items = Decker_Subtasks::get( $this->task_id );

		$this->assertSame( 'Renamed on its own card', $items[0]['title'] );
		$this->assertSame( '2026-12-24', $items[0]['duedate'] );
		$this->assertSame( 'in-progress', $items[0]['status'] );

		// The owner is the task's responsable, who is not even on this card,
		// so the row carries their name and face rather than a user id the
		// pane could not resolve.
		$this->assertSame( $this->outsider_id, $items[0]['assignee'] );
		$this->assertSame(
			get_userdata( $this->outsider_id )->display_name,
			$items[0]['assignee_name']
		);
		$this->assertNotSame( '', $items[0]['assignee_avatar'] );
	}

	/**
	 * With the task gone, the stored copy is all that is left of the item.
	 */
	public function test_a_promoted_item_falls_back_to_the_stored_copy() {
		update_post_meta(
			$this->task_id,
			Decker_Subtasks::META_KEY,
			array(
				array(
					'id'       => 1,
					'title'    => 'Its task was deleted',
					'status'   => 'to-do',
					'assignee' => 0,
					'duedate'  => '',
					'task_id'  => 999999,
				),
			)
		);

		$items = Decker_Subtasks::get( $this->task_id );

		$this->assertSame( 'Its task was deleted', $items[0]['title'] );
		$this->assertArrayNotHasKey( 'assignee_name', $items[0] );
	}

	/**
	 * A task with no stored list reads as empty.
	 */
	public function test_get_returns_empty_list_for_a_task_without_subtasks() {
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * A missing task id reads as empty rather than querying for post 0.
	 */
	public function test_a_missing_task_id_reads_as_empty() {
		$this->assertSame( array(), Decker_Subtasks::get( 0 ) );
		$this->assertSame( array(), Decker_Subtasks::get_allowed_assignees( 0 ) );
	}

	/**
	 * A stored list round-trips with ids assigned and defaults filled in.
	 */
	public function test_set_assigns_ids_and_defaults() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array( 'title' => 'Locate the appearances' ),
				array(
					'title'  => 'Replace them',
					'status' => 'in-progress',
				),
			)
		);

		$this->assertCount( 2, $items );
		$this->assertSame( array( 1, 2 ), wp_list_pluck( $items, 'id' ) );
		$this->assertSame( 'to-do', $items[0]['status'] );
		$this->assertSame( 'in-progress', $items[1]['status'] );
		$this->assertSame( 0, $items[0]['assignee'] );
		$this->assertSame( '', $items[0]['duedate'] );
		$this->assertSame( 0, $items[0]['task_id'] );

		$this->assertEquals( $items, Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * An item without a usable title carries no information and is dropped.
	 */
	public function test_items_without_a_title_are_dropped() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array( 'title' => '   ' ),
				array( 'title' => 'Real step' ),
				array( 'status' => 'done' ),
				'not an item',
			)
		);

		$this->assertCount( 1, $items );
		$this->assertSame( 'Real step', $items[0]['title'] );
	}

	/**
	 * An unknown state falls back to to-do rather than being stored as-is.
	 */
	public function test_unknown_status_falls_back_to_todo() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array(
					'title'  => 'Step',
					'status' => 'blocked',
				),
			)
		);

		$this->assertSame( 'to-do', $items[0]['status'] );
	}

	/**
	 * Only people already on the card can own a subtask.
	 */
	public function test_assignee_outside_the_task_is_cleared() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array(
					'title'    => 'Assigned to a member',
					'assignee' => $this->assignee_id,
				),
				array(
					'title'    => 'Assigned to the responsable',
					'assignee' => $this->user_id,
				),
				array(
					'title'    => 'Assigned to an outsider',
					'assignee' => $this->outsider_id,
				),
			)
		);

		$this->assertSame( $this->assignee_id, $items[0]['assignee'] );
		$this->assertSame( $this->user_id, $items[1]['assignee'] );
		$this->assertSame( 0, $items[2]['assignee'] );
	}

	/**
	 * Only a real calendar date in Y-m-d form is stored.
	 */
	public function test_due_dates_are_validated() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array(
					'title'   => 'Valid',
					'duedate' => '2026-11-15',
				),
				array(
					'title'   => 'Rolled over',
					'duedate' => '2026-13-45',
				),
				array(
					'title'   => 'Wrong format',
					'duedate' => '15/11/2026',
				),
				array(
					'title'   => 'Not a date',
					'duedate' => 'tomorrow',
				),
			)
		);

		$this->assertSame( '2026-11-15', $items[0]['duedate'] );
		$this->assertSame( '', $items[1]['duedate'] );
		$this->assertSame( '', $items[2]['duedate'] );
		$this->assertSame( '', $items[3]['duedate'] );
	}

	/**
	 * Duplicate ids are re-minted so every stored item stays addressable.
	 */
	public function test_duplicate_ids_are_reassigned() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array(
				array(
					'id'    => 7,
					'title' => 'First',
				),
				array(
					'id'    => 7,
					'title' => 'Second',
				),
			)
		);

		$this->assertSame( 7, $items[0]['id'] );
		$this->assertSame( 8, $items[1]['id'] );
	}

	/**
	 * A title longer than the stored limit is truncated, not rejected.
	 */
	public function test_long_titles_are_truncated() {
		$items = Decker_Subtasks::set(
			$this->task_id,
			array( array( 'title' => str_repeat( 'a', Decker_Subtasks::MAX_TITLE_LENGTH + 50 ) ) )
		);

		$this->assertSame( Decker_Subtasks::MAX_TITLE_LENGTH, mb_strlen( $items[0]['title'] ) );
	}

	/**
	 * The list is capped so the serialized meta stays bounded.
	 */
	public function test_the_list_is_capped() {
		$submitted = array();
		for ( $i = 0; $i < Decker_Subtasks::MAX_ITEMS + 10; $i++ ) {
			$submitted[] = array( 'title' => 'Step ' . $i );
		}

		$this->assertCount( Decker_Subtasks::MAX_ITEMS, Decker_Subtasks::set( $this->task_id, $submitted ) );
	}

	/**
	 * Storing an empty list removes the meta instead of leaving an empty array.
	 */
	public function test_clearing_the_list_deletes_the_meta() {
		Decker_Subtasks::set( $this->task_id, array( array( 'title' => 'Step' ) ) );
		Decker_Subtasks::set( $this->task_id, array() );

		$this->assertSame( '', get_post_meta( $this->task_id, Decker_Subtasks::META_KEY, true ) );
		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * A meta value that is not a list of items reads as empty.
	 */
	public function test_malformed_meta_reads_as_empty() {
		update_post_meta( $this->task_id, Decker_Subtasks::META_KEY, 'not a list' );

		$this->assertSame( array(), Decker_Subtasks::get( $this->task_id ) );
	}

	/**
	 * Progress counts each state and rounds the percentage.
	 */
	public function test_progress_counts_each_state() {
		$progress = Decker_Subtasks::progress(
			array(
				array( 'status' => 'done' ),
				array( 'status' => 'done' ),
				array( 'status' => 'in-progress' ),
				array( 'status' => 'to-do' ),
			)
		);

		$this->assertSame( 4, $progress['total'] );
		$this->assertSame( 2, $progress['done'] );
		$this->assertSame( 50, $progress['percent'] );
		$this->assertSame(
			array(
				'to-do'       => 1,
				'in-progress' => 1,
				'done'        => 2,
			),
			$progress['counts']
		);
	}

	/**
	 * An empty list reports zero progress instead of dividing by zero.
	 */
	public function test_progress_of_an_empty_list() {
		$progress = Decker_Subtasks::progress( array() );

		$this->assertSame( 0, $progress['total'] );
		$this->assertSame( 0, $progress['done'] );
		$this->assertSame( 0, $progress['percent'] );
	}

	/**
	 * An unknown state is not counted in any bucket but keeps the total honest.
	 */
	public function test_progress_ignores_unknown_states() {
		$progress = Decker_Subtasks::progress(
			array(
				array( 'status' => 'done' ),
				array( 'status' => 'blocked' ),
			)
		);

		$this->assertSame( 1, $progress['total'] );
		$this->assertSame( 1, $progress['done'] );
	}

	/**
	 * A task with no responsable meta still offers its author.
	 *
	 * Task resolves the responsable that way, so a task created outside the
	 * card form — over the generic REST endpoint, by the mail parser — would
	 * otherwise offer nobody at all and silently drop every assignee.
	 */
	public function test_allowed_assignees_fall_back_to_the_author() {
		$task_id = self::factory()->task->create(
			array(
				'board'  => self::factory()->board->create(),
				'author' => $this->assignee_id,
			)
		);
		delete_post_meta( $task_id, 'responsable' );

		$this->assertSame( array( $this->assignee_id ), Decker_Subtasks::get_allowed_assignees( $task_id ) );

		$items = Decker_Subtasks::set(
			$task_id,
			array(
				array(
					'title'    => 'Assigned to the author',
					'assignee' => $this->assignee_id,
				),
			)
		);

		$this->assertSame( $this->assignee_id, $items[0]['assignee'] );
	}

	/**
	 * Build an open item for the chip-selection tests.
	 *
	 * @param string $title    The item title.
	 * @param string $status   The item status.
	 * @param int    $assignee The assigned user.
	 * @return array The item.
	 */
	private function chip_item( string $title, string $status = 'to-do', int $assignee = 0 ): array {
		return array(
			'id'       => 0,
			'title'    => $title,
			'status'   => $status,
			'assignee' => $assignee,
			'duedate'  => '',
			'task_id'  => 0,
		);
	}

	/**
	 * A finished item never takes a chip: the counter already carries it.
	 */
	public function test_card_chips_exclude_done_items() {
		$selection = Decker_Subtasks::select_card_chips(
			array(
				$this->chip_item( 'Done one', 'done' ),
				$this->chip_item( 'Still open' ),
				$this->chip_item( 'Done two', 'done' ),
			)
		);

		$this->assertSame( array( 'Still open' ), wp_list_pluck( $selection['chips'], 'title' ) );
		$this->assertSame( 0, $selection['remaining'] );
	}

	/**
	 * A task whose subtasks are all finished shows no chips at all.
	 */
	public function test_card_chips_of_a_finished_task_are_empty() {
		$selection = Decker_Subtasks::select_card_chips(
			array( $this->chip_item( 'A', 'done' ), $this->chip_item( 'B', 'done' ) )
		);

		$this->assertSame( array(), $selection['chips'] );
		$this->assertSame( 0, $selection['remaining'] );
	}

	/**
	 * The chips are the first open items, in the order the list is stored in.
	 *
	 * The list is hand-ordered by dragging, so a card that reordered it on its
	 * own could not be read against the card's own Subtasks tab.
	 */
	public function test_card_chips_keep_the_stored_order() {
		$selection = Decker_Subtasks::select_card_chips(
			array(
				$this->chip_item( 'Someone else, started', 'in-progress', $this->assignee_id ),
				$this->chip_item( 'Nobody' ),
				$this->chip_item( 'Mine, not started', 'to-do', $this->user_id ),
				$this->chip_item( 'Mine, started', 'in-progress', $this->user_id ),
			)
		);

		$this->assertSame(
			array( 'Someone else, started', 'Nobody', 'Mine, not started' ),
			wp_list_pluck( $selection['chips'], 'title' )
		);
		$this->assertSame( 1, $selection['remaining'] );
	}

	/**
	 * The card takes three chips and counts the rest, so its height is bounded.
	 */
	public function test_card_chips_are_capped_and_the_rest_counted() {
		$items = array();
		for ( $i = 1; $i <= 56; $i++ ) {
			$items[] = $this->chip_item( 'Step ' . $i, $i <= 8 ? 'done' : 'to-do' );
		}

		$selection = Decker_Subtasks::select_card_chips( $items );

		$this->assertCount( 3, $selection['chips'] );
		$this->assertSame( 45, $selection['remaining'], '56 items, 8 done, 3 shown.' );
	}

	/**
	 * The feature flag drives is_enabled(), and defaults to on.
	 */
	public function test_is_enabled_follows_the_setting() {
		// A site that has never saved the settings screen has no key at all.
		delete_option( 'decker_settings' );
		$this->assertTrue( Decker_Subtasks::is_enabled() );

		update_option( 'decker_settings', array() );
		$this->assertTrue( Decker_Subtasks::is_enabled() );

		// Clearing the checkbox stores '0', which has to stick.
		update_option( 'decker_settings', array( 'subtasks_enabled' => '0' ) );
		$this->assertFalse( Decker_Subtasks::is_enabled() );

		update_option( 'decker_settings', array( 'subtasks_enabled' => '1' ) );
		$this->assertTrue( Decker_Subtasks::is_enabled() );
	}

	/**
	 * Cloning a task carries the breakdown over, without the promotion links.
	 */
	public function test_cloning_a_task_copies_its_subtasks() {
		Decker_Subtasks::set(
			$this->task_id,
			array(
				array(
					'title'  => 'Step one',
					'status' => 'done',
				),
				array( 'title' => 'Step two' ),
			)
		);

		$clone_id = Decker_Task_Clone::clone_task( $this->task_id );
		$this->assertNotWPError( $clone_id );

		$items = Decker_Subtasks::get( $clone_id );
		$this->assertCount( 2, $items );
		$this->assertSame( 'Step one', $items[0]['title'] );
		$this->assertSame( 'done', $items[0]['status'] );
		$this->assertSame( 0, $items[0]['task_id'] );
	}
}
