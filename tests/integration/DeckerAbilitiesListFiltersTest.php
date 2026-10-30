<?php
/**
 * Relation and "for today" filters on the list-tasks ability.
 *
 * An agent asking "what is on my plate?" must not have to page through every
 * task on every board and filter client-side: that is slow, it wastes model
 * context, and a client-side filter over a paginated response is simply wrong.
 * These filters are therefore resolved server-side, against the authenticated
 * user, and the totals describe the filtered set.
 *
 * The filters deliberately take no user ID. Listing another person's workload
 * is not an operation the agent surface offers.
 *
 * @package Decker
 */

class DeckerAbilitiesListFiltersTest extends Decker_Test_Base {

	/**
	 * Ability service under test.
	 *
	 * @var Decker_Ability_Service
	 */
	private $service;

	/**
	 * The user the filters are asked about.
	 *
	 * @var int
	 */
	private $me_id;

	/**
	 * An unrelated colleague with the same capabilities.
	 *
	 * @var int
	 */
	private $other_id;

	/**
	 * Board holding the fixtures.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixtures, keyed by role in the scenario.
	 *
	 * @var array<string, int>
	 */
	private $tasks = array();

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->service  = new Decker_Ability_Service();
		$this->me_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->other_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->me_id );
		$this->board_id = self::factory()->board->create(
			array(
				'name'  => 'Filter Board',
				'color' => '#0099ff',
			)
		);

		// Deterministic fixtures: every task has a different relation to me, and
		// none of them is distinguishable by ID order or creation time alone.
		$this->tasks['authored'] = $this->task( 'Authored by me', $this->me_id, 0, array() );
		$this->tasks['assigned'] = $this->task( 'Assigned to me', $this->other_id, $this->other_id, array( $this->me_id ) );
		$this->tasks['responsible'] = $this->task( 'I am responsible', $this->other_id, $this->me_id, array() );
		$this->tasks['unrelated'] = $this->task( 'Nothing to do with me', $this->other_id, $this->other_id, array( $this->other_id ) );
		// The responsible-user fallback: no `responsable` meta, so the author is
		// the responsible user. Authored by the colleague, so not mine.
		$this->tasks['fallback_other'] = $this->task( 'Fallback to other author', $this->other_id, 0, array() );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a task fixture.
	 *
	 * @param string $title       Task title.
	 * @param int    $author      Author user ID.
	 * @param int    $responsible Responsible user ID, 0 to leave unset.
	 * @param int[]  $assignees   Assignee user IDs.
	 * @return int Task ID.
	 */
	private function task( string $title, int $author, int $responsible, array $assignees ): int {
		$task_id = self::factory()->task->create(
			array(
				'post_title'  => $title,
				'board'       => $this->board_id,
				'stack'       => 'to-do',
				'post_author' => $author,
				'author'      => $author,
			)
		);

		if ( $responsible > 0 ) {
			update_post_meta( $task_id, 'responsable', $responsible );
		} else {
			delete_post_meta( $task_id, 'responsable' );
		}

		update_post_meta( $task_id, 'assigned_users', $assignees );

		return $task_id;
	}

	/**
	 * List tasks and return the matching IDs.
	 *
	 * @param array $input Ability input.
	 * @return int[] Task IDs.
	 */
	private function ids( array $input ): array {
		$result = $this->service->list_tasks( $input );
		$this->assertIsArray( $result, 'The listing must not error: ' . wp_json_encode( $result ) );

		return array_map( static fn( $task ) => (int) $task['id'], $result['tasks'] );
	}

	/**
	 * Without a filter every visible task is listed.
	 */
	public function test_no_filter_lists_every_visible_task() {
		$ids = $this->ids( array() );

		foreach ( $this->tasks as $role => $task_id ) {
			$this->assertContains( $task_id, $ids, $role . ' must be listed when no filter is applied' );
		}
	}

	/**
	 * `author` matches only what this user wrote.
	 */
	public function test_author_filter() {
		$ids = $this->ids( array( 'relation' => 'author' ) );

		$this->assertSame( array( $this->tasks['authored'] ), $ids );
	}

	/**
	 * `assigned` matches only tasks this user is assigned to.
	 */
	public function test_assigned_filter() {
		$ids = $this->ids( array( 'relation' => 'assigned' ) );

		$this->assertSame( array( $this->tasks['assigned'] ), $ids );
	}

	/**
	 * `responsible` honours the fallback to the task author.
	 */
	public function test_responsible_filter_includes_the_author_fallback() {
		$ids = $this->ids( array( 'relation' => 'responsible' ) );

		// Explicit `responsable` meta, and my own authored task where the meta is
		// absent so the author is the responsible user.
		$this->assertContains( $this->tasks['responsible'], $ids );
		$this->assertContains( $this->tasks['authored'], $ids );
		// The colleague's own fallback task is theirs, not mine.
		$this->assertNotContains( $this->tasks['fallback_other'], $ids );
		$this->assertNotContains( $this->tasks['unrelated'], $ids );
	}

	/**
	 * `any` is the union of the three relations.
	 */
	public function test_any_filter_is_the_union() {
		$ids = $this->ids( array( 'relation' => 'any' ) );

		$this->assertContains( $this->tasks['authored'], $ids );
		$this->assertContains( $this->tasks['assigned'], $ids );
		$this->assertContains( $this->tasks['responsible'], $ids );
		$this->assertNotContains( $this->tasks['unrelated'], $ids );
		$this->assertNotContains( $this->tasks['fallback_other'], $ids );
	}

	/**
	 * A serialized-meta near miss must not match.
	 *
	 * `assigned_users` is a serialized array, so a LIKE prefilter for user 5
	 * also matches the stored "i:15". The SQL narrows; PHP decides.
	 */
	public function test_assigned_filter_rejects_a_serialized_near_miss() {
		$decoy = $this->task( 'Assigned to a similar id', $this->other_id, $this->other_id, array() );
		// A user ID that contains mine as a substring, without creating the user:
		// the filter must reject it on the value, not on the user's existence.
		update_post_meta( $decoy, 'assigned_users', array( (int) ( $this->me_id . '9' ) ) );

		$ids = $this->ids( array( 'relation' => 'assigned' ) );

		$this->assertNotContains( $decoy, $ids );
		$this->assertContains( $this->tasks['assigned'], $ids );
	}

	/**
	 * The filters answer for the caller, not for whoever created the data.
	 */
	public function test_filters_follow_the_authenticated_user() {
		wp_set_current_user( $this->other_id );

		$this->assertSame( array( $this->tasks['unrelated'] ), $this->ids( array( 'relation' => 'assigned' ) ) );
		$this->assertContains( $this->tasks['unrelated'], $this->ids( array( 'relation' => 'any' ) ) );
		$this->assertNotContains( $this->tasks['authored'], $this->ids( array( 'relation' => 'any' ) ) );
	}

	/**
	 * No user ID can be supplied to read someone else's workload.
	 */
	public function test_schema_rejects_a_user_id() {
		$schema = ( new Decker_Abilities() )->get_ability_definitions()['decker/list-tasks']['input_schema'];

		$this->assertWPError(
			rest_validate_value_from_schema( array( 'user_id' => $this->other_id ), $schema, 'input' )
		);
		$this->assertWPError(
			rest_validate_value_from_schema( array( 'relation' => 'everyones' ), $schema, 'input' )
		);
	}

	/**
	 * The "for today" filter is personal and date-specific.
	 */
	public function test_today_filter_is_personal() {
		$manager = new Decker_Task_Today_Manager();
		$manager->mark_for_today( $this->tasks['assigned'], $this->me_id );
		$manager->mark_for_today( $this->tasks['unrelated'], $this->other_id );

		$mine = $this->ids( array( 'today' => true ) );
		$this->assertSame( array( $this->tasks['assigned'] ), $mine );

		// The colleague's own today list is theirs alone.
		wp_set_current_user( $this->other_id );
		$this->assertSame( array( $this->tasks['unrelated'] ), $this->ids( array( 'today' => true ) ) );
	}

	/**
	 * The task editor stores user_id as a string, and that must still match.
	 *
	 * The meta box reads the id from a data attribute and posts the relations
	 * as JSON, so the saver stores "5" where Decker_Task_Today_Manager stores
	 * 5. Every reader casts, so the two have always been interchangeable; a
	 * narrowing query that only accepts one of them would hide from the agent
	 * listing a task the board shows as marked for today.
	 */
	public function test_today_filter_matches_a_relation_stored_as_a_string() {
		update_post_meta(
			$this->tasks['authored'],
			'_user_date_relations',
			array(
				array(
					'user_id' => (string) $this->me_id,
					'date'    => Decker_Task_Date_Relations::today_string(),
				),
			)
		);

		$this->assertSame( array( $this->tasks['authored'] ), $this->ids( array( 'today' => true ) ) );
	}

	/**
	 * A relation from another date must not appear in today's list.
	 */
	public function test_today_filter_ignores_other_dates() {
		update_post_meta(
			$this->tasks['authored'],
			'_user_date_relations',
			array(
				array(
					'user_id' => $this->me_id,
					'date'    => '2020-01-01',
				),
			)
		);

		$this->assertSame( array(), $this->ids( array( 'today' => true ) ) );
	}

	/**
	 * Filters combine, and with the stack and board filters too.
	 */
	public function test_filters_combine() {
		$manager = new Decker_Task_Today_Manager();
		$manager->mark_for_today( $this->tasks['assigned'], $this->me_id );
		$manager->mark_for_today( $this->tasks['authored'], $this->me_id );

		$this->assertSame(
			array( $this->tasks['assigned'] ),
			$this->ids(
				array(
					'relation' => 'assigned',
					'today'    => true,
				)
			)
		);

		$this->assertSame(
			array( $this->tasks['authored'] ),
			$this->ids(
				array(
					'relation' => 'author',
					'today'    => true,
					'board_id' => $this->board_id,
					'stack'    => 'to-do',
				)
			)
		);
	}

	/**
	 * A hidden task stays hidden through the relation filters.
	 */
	public function test_relation_filter_does_not_bypass_hidden_visibility() {
		$hidden = $this->task( 'Hidden and assigned to me', $this->other_id, $this->other_id, array( $this->me_id ) );
		update_post_meta( $hidden, 'hidden', '1' );

		// Default listing hides every hidden task, even a related one.
		$this->assertNotContains( $hidden, $this->ids( array( 'relation' => 'assigned' ) ) );

		// Opting in shows the ones I am entitled to see.
		$this->assertContains(
			$hidden,
			$this->ids(
				array(
					'relation'       => 'assigned',
					'include_hidden' => true,
				)
			)
		);

		// The task's own author may see it: they are one of the people it
		// concerns, and the relation filter does not take that away.
		wp_set_current_user( $this->other_id );
		$this->assertContains(
			$hidden,
			$this->ids(
				array(
					'relation'       => 'author',
					'include_hidden' => true,
				)
			)
		);

		// An unrelated colleague with the same capabilities never sees it, with
		// or without a relation filter, and opting in does not change that.
		$outsider = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $outsider );
		$this->assertNotContains( $hidden, $this->ids( array( 'include_hidden' => true ) ) );
		$this->assertNotContains(
			$hidden,
			$this->ids(
				array(
					'relation'       => 'any',
					'include_hidden' => true,
				)
			)
		);
	}

	/**
	 * Totals describe the filtered set, and pages do not overlap.
	 */
	public function test_pagination_describes_the_filtered_set() {
		for ( $index = 0; $index < 4; $index++ ) {
			$this->task( 'Mine ' . $index, $this->me_id, 0, array() );
		}

		$first = $this->service->list_tasks(
			array(
				'relation' => 'author',
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$second = $this->service->list_tasks(
			array(
				'relation' => 'author',
				'per_page' => 2,
				'page'     => 2,
			)
		);

		// Five authored tasks: the original one plus the four above.
		$this->assertSame( 5, $first['total'] );
		$this->assertSame( 3, $first['total_pages'] );
		$this->assertCount( 2, $first['tasks'] );
		$this->assertCount( 2, $second['tasks'] );

		$first_ids  = array_map( static fn( $task ) => (int) $task['id'], $first['tasks'] );
		$second_ids = array_map( static fn( $task ) => (int) $task['id'], $second['tasks'] );
		$this->assertSame( array(), array_intersect( $first_ids, $second_ids ) );
	}

	/**
	 * A relation filter narrows the query instead of scanning a capped set.
	 *
	 * The PHP pass is bounded, so before this narrowing a user's own task could
	 * be missing from their own listing once a board held more tasks than the
	 * cap. `any` and `responsible` cannot be a single WP_Query constraint -- one
	 * is an OR across a column and two meta keys, the other falls back to the
	 * author -- so the candidates are collected up front and passed as post__in.
	 */
	public function test_relation_filters_narrow_the_query() {
		$filter = new Decker_Ability_Task_Filter();

		foreach ( array( 'any', 'responsible' ) as $relation ) {
			$narrowed = $filter->narrow(
				array( 'post_type' => 'decker_task' ),
				$filter->normalize( array( 'relation' => $relation ) )
			);

			$this->assertArrayHasKey( 'post__in', $narrowed, $relation );
			$this->assertNotEmpty( $narrowed['post__in'], $relation );
			// My own tasks are candidates; the colleague's unrelated one is not.
			$this->assertContains( $this->tasks['authored'], $narrowed['post__in'], $relation );
			$this->assertNotContains( $this->tasks['unrelated'], $narrowed['post__in'], $relation );
		}

		// `author` is an exact column match, so it needs no candidate list.
		$by_author = $filter->narrow(
			array( 'post_type' => 'decker_task' ),
			$filter->normalize( array( 'relation' => 'author' ) )
		);
		$this->assertSame( $this->me_id, $by_author['author'] );
		$this->assertArrayNotHasKey( 'post__in', $by_author );
	}

	/**
	 * A narrowed query with no candidates returns nothing, not everything.
	 */
	public function test_narrowing_cannot_fail_open() {
		$stranger = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $stranger );
		$filter = new Decker_Ability_Task_Filter();

		$narrowed = $filter->narrow(
			array( 'post_type' => 'decker_task' ),
			$filter->normalize( array( 'relation' => 'any' ) )
		);

		// An impossible ID rather than an absent constraint, which would list
		// every task on the site.
		$this->assertSame( array( 0 ), $narrowed['post__in'] );
		$this->assertSame( array(), $this->ids( array( 'relation' => 'any' ) ) );
	}

	/**
	 * With no authenticated user, a relation filter matches nothing.
	 *
	 * The REST layer rejects an anonymous caller before this point, but the
	 * filter must not fall open if it is ever reached another way: "related to
	 * me" with no "me" is no tasks, not every task.
	 */
	public function test_relation_and_today_match_nothing_for_an_anonymous_user() {
		wp_set_current_user( 0 );

		foreach ( array( 'any', 'assigned', 'responsible', 'author' ) as $relation ) {
			$this->assertSame(
				array(),
				$this->ids( array( 'relation' => $relation ) ),
				$relation . ' must match nothing without a current user'
			);
		}

		$this->assertSame( array(), $this->ids( array( 'today' => true ) ) );
	}

	/**
	 * An unknown relation value is ignored rather than silently matching nothing.
	 */
	public function test_unknown_relation_is_ignored() {
		// The schema rejects it at the REST boundary; a direct in-process caller
		// gets the unfiltered listing rather than an empty one, so a typo can
		// never look like "you have no tasks".
		$ids = $this->ids( array( 'relation' => 'nonsense' ) );

		$this->assertContains( $this->tasks['unrelated'], $ids );
	}
}
