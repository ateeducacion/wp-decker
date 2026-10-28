<?php
/**
 * Tests for the subtask markup on a task card.
 *
 * What the board shows is the whole point of the feature, so the counter, the
 * three-colour bar and the chip row are asserted as markup rather than left to
 * the browser tests alone.
 *
 * @package Decker
 */

/**
 * Class DeckerSubtaskCardViewTest
 */
class DeckerSubtaskCardViewTest extends Decker_Test_Base {

	/**
	 * The viewer, and the task's responsable.
	 *
	 * @var int
	 */
	private $viewer;

	/**
	 * Another user on the task.
	 *
	 * @var int
	 */
	private $teammate;

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

		update_option( 'decker_settings', array( 'subtasks_enabled' => '1' ) );

		$this->viewer   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->teammate = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->viewer );

		$this->task_id = self::factory()->task->create(
			array(
				'board'          => self::factory()->board->create(),
				'responsable'    => $this->viewer,
				'assigned_users' => array( $this->teammate ),
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
	 * Render the subtask markup of the fixture task.
	 *
	 * @param string $part Either 'progress' or 'chips'.
	 * @return string The markup.
	 */
	private function render( string $part ): string {
		$view = new Decker_Subtask_Card_View( new Task( $this->task_id ) );

		ob_start();
		if ( 'progress' === $part ) {
			$view->render_progress();
		} else {
			$view->render_chips();
		}

		return (string) ob_get_clean();
	}

	/**
	 * Store a list of items on the fixture task.
	 *
	 * @param array $items The raw items.
	 * @return array The stored items.
	 */
	private function store( array $items ): array {
		return Decker_Subtasks::set( $this->task_id, $items );
	}

	/**
	 * A task without subtasks renders nothing at all, counter included.
	 */
	public function test_a_task_without_subtasks_renders_nothing() {
		$this->assertSame( '', $this->render( 'progress' ) );
		$this->assertSame( '', $this->render( 'chips' ) );
	}

	/**
	 * With the feature off nothing renders either, even with stored items.
	 */
	public function test_nothing_renders_while_the_feature_is_disabled() {
		$this->store( array( array( 'title' => 'Step' ) ) );

		update_option( 'decker_settings', array( 'subtasks_enabled' => '0' ) );

		$this->assertSame( '', $this->render( 'progress' ) );
		$this->assertSame( '', $this->render( 'chips' ) );
	}

	/**
	 * The counter shows done/total and a segment per represented state.
	 */
	public function test_the_counter_shows_the_totals_and_the_bar() {
		$this->store(
			array(
				array(
					'title'  => 'Done',
					'status' => 'done',
				),
				array(
					'title'  => 'Started',
					'status' => 'in-progress',
				),
				array( 'title' => 'Open one' ),
				array( 'title' => 'Open two' ),
			)
		);

		$markup = $this->render( 'progress' );

		$this->assertStringContainsString( '<b>1/4</b>', $markup );
		$this->assertStringContainsString( 'decker-subtasks-bar', $markup );
		$this->assertStringContainsString( 'progress-bar bg-success" style="width: 25%', $markup );
		$this->assertStringContainsString( 'progress-bar bg-warning" style="width: 25%', $markup );
		$this->assertStringContainsString( 'progress-bar bg-secondary" style="width: 50%', $markup );
		$this->assertStringContainsString( 'decker_tab=subtasks', $markup );
	}

	/**
	 * The bar's segments always add up to the full width.
	 */
	public function test_the_bar_segments_add_up_to_one_hundred() {
		$this->store(
			array(
				array(
					'title'  => 'Done',
					'status' => 'done',
				),
				array( 'title' => 'Open one' ),
				array( 'title' => 'Open two' ),
			)
		);

		preg_match_all( '/width: (\d+)%/', $this->render( 'progress' ), $matches );

		$this->assertNotEmpty( $matches[1] );
		$this->assertSame( 100, array_sum( array_map( 'intval', $matches[1] ) ) );
	}

	/**
	 * A state with no items gets no segment rather than a zero-width one.
	 */
	public function test_an_unrepresented_state_gets_no_segment() {
		$this->store( array( array( 'title' => 'Open' ) ) );

		$markup = $this->render( 'progress' );

		$this->assertStringNotContainsString( 'bg-success', $markup );
		$this->assertStringNotContainsString( 'bg-warning', $markup );
		$this->assertStringContainsString( 'bg-secondary" style="width: 100%', $markup );
	}

	/**
	 * The hover list previews the items and says how many it left out.
	 */
	public function test_the_hover_list_previews_the_items() {
		$items = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$items[] = array( 'title' => 'Step ' . $i );
		}
		$this->store( $items );

		$markup = html_entity_decode( $this->render( 'progress' ) );

		$this->assertStringContainsString( 'decker-subtasks-popover-list', $markup );
		$this->assertStringContainsString( 'Step 1<', $markup );
		$this->assertStringContainsString( 'Step 12<', $markup );
		$this->assertStringNotContainsString( 'Step 13<', $markup );
		$this->assertStringContainsString( 'and 3 more subtasks', $markup );
	}

	/**
	 * Chips cover the open items only, newest state first, capped with a +N.
	 */
	public function test_chips_show_three_open_items_and_count_the_rest() {
		$items = array(
			array(
				'title'  => 'Finished',
				'status' => 'done',
			),
		);
		for ( $i = 1; $i <= 6; $i++ ) {
			$items[] = array( 'title' => 'Open ' . $i );
		}
		$this->store( $items );

		$markup = $this->render( 'chips' );

		$this->assertSame( 3, substr_count( $markup, 'decker-subtask-chip-label' ) );
		$this->assertStringNotContainsString( 'Finished', $markup );
		$this->assertStringContainsString( 'decker-subtask-chip-more', $markup );
		$this->assertStringContainsString( '>+3</a>', $markup );

		// Only the last chip shares its row with the +N; the rest take the
		// whole width of the card.
		$this->assertSame( 1, substr_count( $markup, 'decker-subtask-chip-with-more' ) );
		$this->assertLessThan(
			strpos( $markup, 'decker-subtask-chip-more' ),
			strpos( $markup, 'decker-subtask-chip-with-more' ),
			'The marked chip must be the one right before the +N.'
		);
	}

	/**
	 * No overflow chip when every open item fits.
	 */
	public function test_no_overflow_chip_when_everything_fits() {
		$this->store( array( array( 'title' => 'Only one' ) ) );

		$markup = $this->render( 'chips' );

		$this->assertStringContainsString( 'Only one', $markup );
		$this->assertStringNotContainsString( 'decker-subtask-chip-more', $markup );
		$this->assertStringNotContainsString( 'decker-subtask-chip-with-more', $markup );
	}

	/**
	 * The viewer's own item is tinted, and the list order is left alone.
	 */
	public function test_the_viewers_own_chip_is_marked() {
		$this->store(
			array(
				array(
					'title'    => 'Theirs',
					'assignee' => $this->teammate,
				),
				array(
					'title'    => 'Mine',
					'assignee' => $this->viewer,
				),
			)
		);

		$markup = $this->render( 'chips' );

		$this->assertLessThan(
			strpos( $markup, 'Mine' ),
			strpos( $markup, 'Theirs' ),
			'The chips follow the order the list is stored in.'
		);
		$this->assertSame( 1, substr_count( $markup, 'decker-subtask-chip-mine' ) );
	}

	/**
	 * A finished task shows the counter but no chips: nothing is left to do.
	 */
	public function test_a_finished_task_shows_no_chips() {
		$this->store(
			array(
				array(
					'title'  => 'Done',
					'status' => 'done',
				),
			)
		);

		$this->assertStringContainsString( '<b>1/1</b>', $this->render( 'progress' ) );
		$this->assertSame( '', $this->render( 'chips' ) );
	}

	/**
	 * The chip tooltip carries what the truncated label cannot.
	 */
	public function test_the_chip_tooltip_carries_the_details() {
		$this->store(
			array(
				array(
					'title'    => 'A rather long subtask title that the chip truncates',
					'status'   => 'in-progress',
					'assignee' => $this->teammate,
					'duedate'  => '2026-11-20',
				),
			)
		);

		$markup = html_entity_decode( $this->render( 'chips' ) );
		$user   = get_userdata( $this->teammate );

		$this->assertStringContainsString( 'A rather long subtask title that the chip truncates', $markup );
		$this->assertStringContainsString( $user->display_name, $markup );
		$this->assertStringContainsString( 'In Progress', $markup );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( '2026-11-20' ) ), $markup );
	}

	/**
	 * Item titles are escaped, since they are whatever someone typed.
	 */
	public function test_item_titles_are_escaped() {
		$this->store( array( array( 'title' => 'Fix <script>alert(1)</script> now' ) ) );

		$markup = $this->render( 'chips' );

		$this->assertStringNotContainsString( '<script>', $markup );
	}

	/**
	 * An assigned chip ends with that person's face.
	 */
	public function test_a_chip_shows_the_face_of_whoever_it_is_assigned_to() {
		$this->store(
			array(
				array(
					'title'    => 'Assigned',
					'assignee' => $this->teammate,
				),
				array( 'title' => 'Nobody' ),
			)
		);

		$markup = $this->render( 'chips' );

		// One face, on the assigned chip: an empty circle on every unassigned
		// chip would be noise.
		$this->assertSame( 1, substr_count( $markup, 'decker-subtask-chip-avatar' ) );
		$this->assertStringContainsString( esc_url( get_avatar_url( $this->teammate, array( 'size' => 32 ) ) ), $markup );

		// It is decorative: the name is already in the chip's tooltip.
		$this->assertStringContainsString( 'alt=""', $markup );
	}

	/**
	 * Each chip carries the state as a dot class, not only as a colour.
	 */
	public function test_chips_carry_their_state_as_a_class() {
		$this->store(
			array(
				array(
					'title'  => 'Started',
					'status' => 'in-progress',
				),
				array( 'title' => 'Open' ),
			)
		);

		$markup = $this->render( 'chips' );

		$this->assertStringContainsString( 'decker-subtask-dot-in-progress', $markup );
		$this->assertStringContainsString( 'decker-subtask-dot-to-do', $markup );
	}
}
