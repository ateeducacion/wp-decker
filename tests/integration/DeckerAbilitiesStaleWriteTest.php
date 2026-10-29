<?php
/**
 * Stale-write protection for the mutating abilities.
 *
 * The edit lock is advisory and only enforced when Decker's post-locking
 * feature is active, so on its own it does not stop an agent from overwriting
 * a change made between its read and its write. That matters most for the
 * read-modify-write operations: adding one label means sending the whole
 * collection back, so a lost update silently drops someone else's label.
 *
 * `expected_modified` closes that window without a second locking system: it
 * compares the value the caller read against post_modified_gmt, which
 * WordPress already maintains.
 *
 * @package Decker
 */

class DeckerAbilitiesStaleWriteTest extends Decker_Test_Base {

	/**
	 * Ability service under test.
	 *
	 * @var Decker_Ability_Service
	 */
	private $service;

	/**
	 * The agent's user.
	 *
	 * @var int
	 */
	private $agent_id;

	/**
	 * A colleague editing the same task.
	 *
	 * @var int
	 */
	private $colleague_id;

	/**
	 * Board holding the task.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task under test.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Label terms.
	 *
	 * @var int[]
	 */
	private $labels = array();

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		$this->service      = new Decker_Ability_Service();
		$this->agent_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->colleague_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->agent_id );

		$this->board_id = self::factory()->board->create(
			array(
				'name'  => 'Concurrency Board',
				'color' => '#0099ff',
			)
		);

		foreach ( array( 'Alpha', 'Beta', 'Gamma' ) as $name ) {
			$this->labels[ $name ] = self::factory()->label->create( array( 'name' => $name ) );
		}

		$this->task_id = self::factory()->task->create(
			array(
				'post_title'  => 'Shared task',
				'board'       => $this->board_id,
				'stack'       => 'to-do',
				'post_author' => $this->agent_id,
				'author'      => $this->agent_id,
			)
		);

		wp_set_post_terms( $this->task_id, array( $this->labels['Alpha'] ), 'decker_label' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Read a task's current modified value, as a caller would.
	 *
	 * @return string RFC3339 timestamp.
	 */
	private function read_modified(): string {
		$task = $this->service->get_task( array( 'task_id' => $this->task_id ) );

		return $task['modified'];
	}

	/**
	 * Simulate another user saving the task.
	 *
	 * @param string $title New title.
	 * @return void
	 */
	private function colleague_saves( string $title ): void {
		global $wpdb;

		wp_update_post(
			array(
				'ID'         => $this->task_id,
				'post_title' => $title,
			)
		);

		// wp_update_post() always stamps post_modified with the current time and
		// ignores a value passed to it, and the column has one-second
		// granularity, so a save in the same second as the caller's read is
		// indistinguishable from no save at all. The timestamp is therefore
		// pushed forward directly, which is what a save a second later looks
		// like. See test_same_second_change_is_not_detected() for the limit
		// this works around.
		$later = gmdate( 'Y-m-d H:i:s', time() + 5 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $later,
				'post_modified_gmt' => $later,
			),
			array( 'ID' => $this->task_id )
		);
		clean_post_cache( $this->task_id );
	}

	/**
	 * Read a task's label IDs straight from the taxonomy.
	 *
	 * @return int[] Label term IDs.
	 */
	private function stored_labels(): array {
		$ids = wp_get_post_terms( $this->task_id, 'decker_label', array( 'fields' => 'ids' ) );
		$ids = is_wp_error( $ids ) ? array() : array_map( 'absint', $ids );
		sort( $ids );

		return $ids;
	}

	/**
	 * A matching timestamp lets the write through.
	 */
	public function test_matching_timestamp_is_accepted() {
		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $this->read_modified(),
				'title'             => 'Renamed by the agent',
			)
		);

		$this->assertIsArray( $result, wp_json_encode( $result ) );
		$this->assertSame( 'Renamed by the agent', get_post( $this->task_id )->post_title );
	}

	/**
	 * A stale timestamp is refused, and nothing is written.
	 */
	public function test_stale_timestamp_is_refused() {
		$stale = $this->read_modified();
		$this->colleague_saves( 'Renamed by the colleague' );

		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'title'             => 'Renamed by the agent',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_stale', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		// The colleague's change survives: this is the lost update that would
		// otherwise happen silently.
		$this->assertSame( 'Renamed by the colleague', get_post( $this->task_id )->post_title );
	}

	/**
	 * The error says what to do about it.
	 */
	public function test_conflict_error_is_actionable() {
		$stale = $this->read_modified();
		$this->colleague_saves( 'Renamed by the colleague' );

		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'title'             => 'Renamed by the agent',
			)
		);

		$data = $result->get_error_data();
		$this->assertSame( $stale, $data['expected_modified'] );
		$this->assertNotSame( $stale, $data['current_modified'] );
	}

	/**
	 * A label add built on a stale read cannot drop another user's label.
	 */
	public function test_stale_label_write_cannot_lose_a_label() {
		$stale = $this->read_modified();

		// The colleague adds Beta while the agent is preparing its own change.
		wp_set_post_terms(
			$this->task_id,
			array( $this->labels['Alpha'], $this->labels['Beta'] ),
			'decker_label'
		);
		$this->colleague_saves( 'Shared task' );

		// The agent's write was built from a read that only knew about Alpha.
		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'label_ids'         => array( $this->labels['Alpha'], $this->labels['Gamma'] ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_stale', $result->get_error_code() );

		// Beta is still there. Without the guard it would have been dropped.
		$this->assertSame(
			array( $this->labels['Alpha'], $this->labels['Beta'] ),
			$this->stored_labels()
		);
	}

	/**
	 * Omitting the timestamp keeps the previous behaviour.
	 */
	public function test_the_guard_is_optional() {
		$this->colleague_saves( 'Renamed by the colleague' );

		$result = $this->service->update_task(
			array(
				'task_id' => $this->task_id,
				'title'   => 'Renamed without a guard',
			)
		);

		// Existing callers that never sent the field are unaffected.
		$this->assertIsArray( $result );
		$this->assertSame( 'Renamed without a guard', get_post( $this->task_id )->post_title );
	}

	/**
	 * An unparseable timestamp is a bad request, not a conflict.
	 */
	public function test_invalid_timestamp_is_rejected() {
		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => 'whenever',
				'title'             => 'Renamed',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'decker_invalid_expected_modified', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'Shared task', get_post( $this->task_id )->post_title );
	}

	/**
	 * The same timestamp written with another offset is not a conflict.
	 */
	public function test_equivalent_instant_is_not_a_conflict() {
		$post = get_post( $this->task_id );
		$utc  = strtotime( $post->post_modified_gmt . ' UTC' );

		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				// Same instant, expressed in another offset.
				'expected_modified' => gmdate( 'Y-m-d\TH:i:sP', $utc ),
				'title'             => 'Renamed',
			)
		);

		$this->assertIsArray( $result, wp_json_encode( $result ) );
	}

	/**
	 * Move and archive honour the same guard.
	 */
	public function test_move_and_archive_are_guarded_too() {
		$stale = $this->read_modified();
		$this->colleague_saves( 'Renamed by the colleague' );

		$moved = $this->service->move_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'stack'             => 'in-progress',
			)
		);
		$this->assertWPError( $moved );
		$this->assertSame( 'decker_task_stale', $moved->get_error_code() );
		$this->assertSame( 'to-do', get_post_meta( $this->task_id, 'stack', true ) );

		$archived = $this->service->archive_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'archived'          => true,
			)
		);
		$this->assertWPError( $archived );
		$this->assertSame( 'decker_task_stale', $archived->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $this->task_id ) );
	}

	/**
	 * The guard cannot see a change made within the same second.
	 *
	 * post_modified_gmt has one-second granularity, so two saves in the same
	 * second are indistinguishable. This documents the limit rather than
	 * claiming a guarantee the storage does not provide: the edit lock remains
	 * the primary defence against concurrent editing.
	 */
	public function test_same_second_change_is_not_detected() {
		$stale = $this->read_modified();

		// A plain save, with no timestamp nudge: same second as the read.
		wp_update_post(
			array(
				'ID'         => $this->task_id,
				'post_title' => 'Renamed by the colleague',
			)
		);
		clean_post_cache( $this->task_id );

		$result = $this->service->update_task(
			array(
				'task_id'           => $this->task_id,
				'expected_modified' => $stale,
				'title'             => 'Renamed by the agent',
			)
		);

		if ( $stale === $this->read_modified() ) {
			// Same second: the write goes through. Documented, not desired.
			$this->assertIsArray( $result );
		} else {
			// The second rolled over mid-test, so the guard catches it.
			$this->assertWPError( $result );
		}
	}

	/**
	 * The field is accepted by the schemas that need it.
	 */
	public function test_schemas_accept_the_field() {
		$definitions = ( new Decker_Abilities() )->get_ability_definitions();

		foreach ( array( 'decker/update-task', 'decker/move-task', 'decker/archive-task' ) as $name ) {
			$this->assertArrayHasKey(
				'expected_modified',
				$definitions[ $name ]['input_schema']['properties'],
				$name . ' must accept expected_modified'
			);
		}

		// A create has nothing to be stale against.
		$this->assertArrayNotHasKey(
			'expected_modified',
			$definitions['decker/create-task']['input_schema']['properties']
		);
	}
}
