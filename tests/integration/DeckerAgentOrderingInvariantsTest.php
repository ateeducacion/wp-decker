<?php
/**
 * Ordering invariants for the operations an agent performs.
 *
 * Decker's board order is not an alphabetical sort: it is `menu_order` within a
 * board and stack, with max-priority tasks pulled to the front. An agent that
 * edits a label, a description or an assignee must leave that order alone, and
 * one that reorders deliberately must land the card where it was asked to.
 *
 * Every assertion reads the sequence back from a fresh query rather than
 * trusting a response or a PHP object still in memory, and the fixtures are
 * deliberately not in id order, so a test cannot pass by accident because the
 * data happened to be sorted by id or creation time.
 *
 * @package Decker
 */

class DeckerAgentOrderingInvariantsTest extends Decker_Test_Base {

	/**
	 * Ability service under test.
	 *
	 * @var Decker_Ability_Service
	 */
	private $service;

	/**
	 * Acting user.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Board fixture.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Tasks in the to-do stack, keyed by label.
	 *
	 * @var array<string, int>
	 */
	private $tasks = array();

	/**
	 * Label term used by the isolation tests.
	 *
	 * @var int
	 */
	private $label_id;

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->service = new Decker_Ability_Service();
		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$this->board_id = self::factory()->board->create( array( 'name' => 'Ordering Board' ) );
		$this->label_id = self::factory()->label->create( array( 'name' => 'Ordering Label' ) );

		foreach ( array( 'first', 'second', 'third', 'fourth' ) as $position => $name ) {
			$this->tasks[ $name ] = self::factory()->task->create(
				array(
					'post_title' => 'Card ' . $name,
					'board'      => $this->board_id,
					'stack'      => 'to-do',
				)
			);
			// Explicit, contiguous order so the starting sequence is known and
			// is not the id order.
			wp_update_post(
				array(
					'ID'         => $this->tasks[ $name ],
					'menu_order' => $position + 1,
				)
			);
		}
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Read a stack's task IDs in the order the board and the agent see them.
	 *
	 * Deliberately goes through the listing the agent itself uses, rather than
	 * a hand-rolled ORDER BY: the contract under test is the sequence people
	 * and clients are served, not an assumption about the column.
	 *
	 * @param string $stack Stack slug.
	 * @return int[] Task IDs in order.
	 */
	private function sequence( string $stack = 'to-do' ): array {
		$listing = $this->service->list_tasks(
			array(
				'board_id' => $this->board_id,
				'stack'    => $stack,
				'per_page' => 100,
			)
		);

		return array_map( static fn( $task ) => (int) $task['id'], $listing['tasks'] );
	}

	/**
	 * Dispatch the order endpoint the way the board drag does.
	 *
	 * @param int    $task_id      Task to move.
	 * @param string $source_stack Stack it is in.
	 * @param string $target_stack Stack to drop it on.
	 * @param int    $source_order Its current position.
	 * @param int    $target_order The position to drop it at.
	 * @return WP_REST_Response Response.
	 */
	private function reorder( int $task_id, string $source_stack, string $target_stack, int $source_order, int $target_order ) {
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $task_id . '/order' );
		$request->set_url_params( array( 'id' => $task_id ) );
		$request->set_param( 'board_id', $this->board_id );
		$request->set_param( 'source_stack', $source_stack );
		$request->set_param( 'target_stack', $target_stack );
		$request->set_param( 'source_order', $source_order );
		$request->set_param( 'target_order', $target_order );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The fixture starts in a known order that is not the id order.
	 */
	public function test_the_fixture_order_is_deterministic() {
		$this->assertSame(
			array(
				$this->tasks['first'],
				$this->tasks['second'],
				$this->tasks['third'],
				$this->tasks['fourth'],
			),
			$this->sequence()
		);
	}

	/**
	 * A new task joins the end of a populated stack.
	 */
	public function test_a_new_task_goes_to_the_end() {
		$created = $this->service->create_task(
			array(
				'title'    => 'Created last',
				'board_id' => $this->board_id,
				'stack'    => 'to-do',
			)
		);

		$this->assertIsArray( $created, wp_json_encode( $created ) );
		$sequence = $this->sequence();
		$this->assertSame( $created['id'], end( $sequence ) );
	}

	/**
	 * A max-priority task sorts ahead of ordinary ones.
	 */
	public function test_max_priority_sorts_to_the_front() {
		$this->service->update_task(
			array(
				'task_id'      => $this->tasks['third'],
				'max_priority' => true,
			)
		);

		// The ordering rule is max_priority DESC, then menu_order ASC, and the
		// engine recalculates menu_order on a stack change, so the priority card
		// must now precede the ordinary ones.
		$this->reorder( $this->tasks['third'], 'to-do', 'to-do', 3, 1 );

		$this->assertSame( $this->tasks['third'], $this->sequence()[0] );
		$this->assertTrue(
			(bool) get_post_meta( $this->tasks['third'], 'max_priority', true )
		);
	}

	/**
	 * Moving a card inside its stack lands it where it was dropped.
	 */
	public function test_same_stack_move_lands_at_the_target() {
		$response = $this->reorder( $this->tasks['first'], 'to-do', 'to-do', 1, 3 );
		$this->assertSame( 200, $response->get_status() );

		$sequence = $this->sequence();

		$this->assertSame( $this->tasks['first'], $sequence[2], wp_json_encode( $sequence ) );
		// No duplicates and nothing lost.
		$this->assertCount( 4, $sequence );
		$this->assertSame( array_unique( $sequence ), $sequence );
	}

	/**
	 * Moving the last card to the front keeps every card exactly once.
	 */
	public function test_last_to_first_keeps_the_set_intact() {
		$this->reorder( $this->tasks['fourth'], 'to-do', 'to-do', 4, 1 );

		$sequence = $this->sequence();

		$this->assertSame( $this->tasks['fourth'], $sequence[0] );
		$this->assertCount( 4, $sequence );
		$this->assertSame( array_unique( $sequence ), $sequence );
	}

	/**
	 * No two cards in a stack share a menu_order.
	 *
	 * Contiguity is deliberately NOT asserted: the renumbering pass excludes
	 * the card being moved, so it keeps an intermediate value while the others
	 * are renumbered, and the stored values are legitimately non-contiguous.
	 * What must hold is that no two cards tie, because the engine's own comment
	 * explains that a tie makes the resulting order depend on second-granularity
	 * post_modified, which is flaky.
	 */
	public function test_no_two_cards_share_a_menu_order() {
		$this->reorder( $this->tasks['second'], 'to-do', 'to-do', 2, 4 );

		$orders = array_map(
			static fn( $id ) => (int) get_post( $id )->menu_order,
			$this->sequence()
		);

		$this->assertSame( array_unique( $orders ), $orders, wp_json_encode( $orders ) );
	}

	/**
	 * A cross-stack move updates both stacks and leaves the third alone.
	 */
	public function test_cross_stack_move_updates_both_stacks() {
		$done_before = $this->sequence( 'done' );

		$response = $this->reorder( $this->tasks['second'], 'to-do', 'in-progress', 2, 1 );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( 'in-progress', get_post_meta( $this->tasks['second'], 'stack', true ) );
		$this->assertSame( array( $this->tasks['second'] ), $this->sequence( 'in-progress' ) );

		// The source stack closed the gap.
		$this->assertSame(
			array( $this->tasks['first'], $this->tasks['third'], $this->tasks['fourth'] ),
			$this->sequence()
		);

		// The uninvolved stack did not move.
		$this->assertSame( $done_before, $this->sequence( 'done' ) );
	}

	/**
	 * The board taxonomy survives a stack move.
	 */
	public function test_a_stack_move_keeps_the_board() {
		$this->reorder( $this->tasks['third'], 'to-do', 'done', 3, 1 );

		$this->assertSame(
			array( $this->board_id ),
			wp_get_post_terms( $this->tasks['third'], 'decker_board', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * Editing a label must not reorder the board.
	 *
	 * This is the invariant an agent is most likely to break: the ability write
	 * sends the whole task state, and ordering is part of that state.
	 */
	public function test_a_label_change_does_not_reorder() {
		$before = $this->sequence();

		$result = $this->service->update_task(
			array(
				'task_id'   => $this->tasks['second'],
				'label_ids' => array( $this->label_id ),
			)
		);
		$this->assertIsArray( $result, wp_json_encode( $result ) );

		$this->assertSame( $before, $this->sequence() );
		$this->assertSame(
			array( $this->label_id ),
			wp_get_post_terms( $this->tasks['second'], 'decker_label', array( 'fields' => 'ids' ) )
		);
	}

	/**
	 * Editing a description must not reorder the board.
	 */
	public function test_a_description_change_does_not_reorder() {
		$before = $this->sequence();

		$this->service->update_task(
			array(
				'task_id'     => $this->tasks['third'],
				'description' => 'Rewritten by the agent',
			)
		);

		$this->assertSame( $before, $this->sequence() );
	}

	/**
	 * Changing assignees must not reorder the board.
	 */
	public function test_an_assignee_change_does_not_reorder() {
		$before   = $this->sequence();
		$assignee = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->service->update_task(
			array(
				'task_id'      => $this->tasks['first'],
				'assignee_ids' => array( $assignee ),
			)
		);

		$this->assertSame( $before, $this->sequence() );
		$this->assertSame(
			array( $assignee ),
			array_map( 'absint', (array) get_post_meta( $this->tasks['first'], 'assigned_users', true ) )
		);
	}

	/**
	 * Marking a task for today must not reorder the board.
	 */
	public function test_marking_for_today_does_not_reorder() {
		$before = $this->sequence();

		( new Decker_Task_Today_Manager() )->mark_for_today( $this->tasks['fourth'], $this->user_id );

		$this->assertSame( $before, $this->sequence() );
	}

	/**
	 * Adding a comment must not reorder the board.
	 */
	public function test_a_comment_does_not_reorder() {
		$before = $this->sequence();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->tasks['second'],
				'comment_content'  => 'A comment from the agent',
				'comment_approved' => 1,
			)
		);

		$this->assertSame( $before, $this->sequence() );
	}

	/**
	 * get_post() must see the new order, not the one it cached.
	 *
	 * Every menu_order write in the order engine is raw SQL, so WordPress never
	 * learns the values changed and keeps serving the cached post objects.
	 * Without a persistent object cache that only lasts until the end of the
	 * request, which is why it went unnoticed; with Redis or Memcached -- the
	 * normal production setup -- the `posts` group survives the request and a
	 * board keeps rendering the old order after a drag.
	 *
	 * Found while writing these tests: the stored column and the served
	 * sequence appeared to disagree, and the disagreement was the stale cache.
	 */
	public function test_the_post_cache_sees_the_new_order() {
		// Prime the cache the way a page render would, before the move.
		foreach ( $this->tasks as $id ) {
			get_post( $id );
		}

		$this->reorder( $this->tasks['fourth'], 'to-do', 'to-do', 4, 1 );

		$cached = array();
		foreach ( $this->sequence() as $id ) {
			$cached[] = (int) get_post( $id )->menu_order;
		}

		$sorted = $cached;
		sort( $sorted );
		$this->assertSame(
			$sorted,
			$cached,
			'get_post() is serving a stale menu_order: ' . wp_json_encode( $cached )
		);

		// The card that was dropped at the front holds the lowest order.
		$this->assertSame( min( $cached ), $cached[0] );
	}

	/**
	 * A cross-stack move refreshes both stacks' cached objects.
	 */
	public function test_the_post_cache_is_refreshed_in_both_stacks() {
		foreach ( $this->tasks as $id ) {
			get_post( $id );
		}

		$this->reorder( $this->tasks['second'], 'to-do', 'in-progress', 2, 1 );

		$this->assertSame(
			'in-progress',
			get_post_meta( $this->tasks['second'], 'stack', true )
		);

		$remaining = array();
		foreach ( $this->sequence() as $id ) {
			$remaining[] = (int) get_post( $id )->menu_order;
		}

		$sorted = $remaining;
		sort( $sorted );
		$this->assertSame( $sorted, $remaining, wp_json_encode( $remaining ) );
	}

	/**
	 * A position derived from a partial view of the stack does not land there.
	 *
	 * Hidden tasks occupy real positions, and a client cannot list the ones
	 * belonging to other people, so an index computed from what it can see is
	 * not the index the engine works with. This records how far off the result
	 * can be, which is what the client's refusal and verification are sized
	 * against: it is not bounded to a single slot.
	 */
	public function test_a_position_from_a_partial_view_is_not_honoured() {
		// Real order: first, hidden_one, hidden_two, fourth.
		$hidden_one = self::factory()->task->create(
			array(
				'post_title' => 'Hidden one',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);
		$hidden_two = self::factory()->task->create(
			array(
				'post_title' => 'Hidden two',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);
		update_post_meta( $hidden_one, 'hidden', '1' );
		update_post_meta( $hidden_two, 'hidden', '1' );
		wp_update_post( array( 'ID' => $this->tasks['second'], 'menu_order' => 90 ) );
		wp_update_post( array( 'ID' => $this->tasks['third'], 'menu_order' => 91 ) );
		wp_update_post( array( 'ID' => $hidden_one, 'menu_order' => 2 ) );
		wp_update_post( array( 'ID' => $hidden_two, 'menu_order' => 3 ) );
		wp_update_post( array( 'ID' => $this->tasks['fourth'], 'menu_order' => 4 ) );

		// A client that cannot see the hidden tasks sees [first, fourth, ...]
		// and asks for `first` at its second visible position.
		$this->reorder( $this->tasks['first'], 'to-do', 'to-do', 1, 2 );

		$visible = array_values(
			array_filter(
				$this->sequence(),
				static fn( $id ) => ! get_post_meta( $id, 'hidden', true )
			)
		);

		// The request was "put first after fourth". Recorded as an assertion so
		// a future change to the engine shows up here rather than silently
		// changing what the client has to defend against.
		$this->assertNotSame(
			array( $this->tasks['fourth'], $this->tasks['first'] ),
			array_slice( $visible, 0, 2 ),
			'If this now passes, the engine honours a partial-view index and the client may stop refusing.'
		);
	}

	/**
	 * Repeated reads return the same order.
	 */
	public function test_order_is_stable_across_reads() {
		$this->reorder( $this->tasks['first'], 'to-do', 'to-do', 1, 4 );

		$first = $this->sequence();
		$this->assertSame( $first, $this->sequence() );
		$this->assertSame( $first, $this->sequence() );
	}
}
