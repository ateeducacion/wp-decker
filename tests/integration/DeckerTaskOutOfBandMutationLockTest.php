<?php
/**
 * Integration tests for edit-session invalidation on out-of-band mutations.
 *
 * The editor form resubmits the whole task (stack, board, due date, assignees,
 * labels). Endpoints that mutate a card outside that form therefore leave any
 * open form holding stale data, and the next save would silently revert them.
 *
 * These tests prove each such endpoint rotates the lock generation, so the
 * stale save is rejected with 409 instead of overwriting the change.
 *
 * @package Decker
 */

/**
 * Class DeckerTaskOutOfBandMutationLockTest
 */
class DeckerTaskOutOfBandMutationLockTest extends Decker_Test_Base {

	/**
	 * The editor holding the open form.
	 *
	 * @var int
	 */
	private $user_a;

	/**
	 * The user mutating the card from elsewhere.
	 *
	 * @var int
	 */
	private $user_b;

	/**
	 * Board fixture.
	 *
	 * @var int
	 */
	private $board_id;

	/**
	 * Task fixture.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Lock manager.
	 *
	 * @var Decker_Task_Locks
	 */
	private $locks;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		do_action( 'init' );

		$this->user_a = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->user_b = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->user_a );

		$this->board_id = self::factory()->board->create();
		$this->task_id  = self::factory()->task->create(
			array(
				'post_title' => 'Original title',
				'board'      => $this->board_id,
				'stack'      => 'to-do',
			)
		);

		$this->locks = new Decker_Task_Locks();

		// Make handle_save_decker_task() return its payload instead of dying.
		add_filter( 'decker_save_task_send_response', '__return_false' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		remove_filter( 'decker_save_task_send_response', '__return_false' );
		$_POST = array();
		wp_delete_user( $this->user_a );
		wp_delete_user( $this->user_b );
		parent::tear_down();
	}

	/**
	 * Run the AJAX save handler against the current $_POST payload.
	 */
	private function ajax_save() {
		return ( new Decker_Task_Ajax_Save( new Decker_Tasks() ) )->handle_save_decker_task();
	}

	/**
	 * Open the editor form as user A and return its session generation.
	 *
	 * @return string The generation embedded in A's form.
	 */
	private function open_form_as_a(): string {
		wp_set_current_user( $this->user_a );
		$info = $this->locks->acquire_lock( $this->task_id, $this->user_a );

		return (string) $info['generation'];
	}

	/**
	 * Submit A's form with the generation it was rendered with.
	 *
	 * @param string $generation The generation A's form is carrying.
	 * @param string $stack      The stack A's form resubmits.
	 * @return array The save response payload.
	 */
	private function save_as_a( string $generation, string $stack = 'to-do' ): array {
		wp_set_current_user( $this->user_a );

		$_POST = array(
			'task_id'         => $this->task_id,
			'title'           => 'Saved from a form opened earlier',
			'stack'           => $stack,
			'board'           => $this->board_id,
			'lock_generation' => $generation,
		);

		return $this->ajax_save();
	}

	/**
	 * Assert a save response is the lock-conflict rejection.
	 *
	 * @param array $response The save response payload.
	 * @return void
	 */
	private function assertStaleSaveRejected( array $response ) {
		$this->assertFalse( $response['success'], 'The stale save should have been rejected.' );
		$this->assertSame( 'decker_task_locked', $response['code'] );
	}

	/**
	 * Move the card between stacks through the order/stack controller.
	 *
	 * @param string $target_stack The stack to drop the card on.
	 * @return WP_REST_Response The controller response.
	 */
	private function move_card_as_b( string $target_stack ) {
		wp_set_current_user( $this->user_b );

		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $this->task_id . '/stack' );
		$request->set_url_params( array( 'id' => $this->task_id ) );
		$request->set_param( 'board_id', $this->board_id );
		$request->set_param( 'source_stack', 'to-do' );
		$request->set_param( 'target_stack', $target_stack );
		$request->set_param( 'source_order', 1 );
		$request->set_param( 'target_order', 1 );

		return ( new Decker_Tasks() )->get_order_engine()->update_task_stack_and_order( $request );
	}

	/**
	 * The regression this PR exists for: B moves a card, A's older form must not
	 * be able to drag it back by resubmitting its stale stack.
	 */
	public function test_stack_change_invalidates_an_open_form() {
		$generation = $this->open_form_as_a();

		$this->move_card_as_b( 'in-progress' );

		$this->assertNotSame(
			$generation,
			$this->current_generation(),
			'Moving the card should have rotated the generation.'
		);

		$this->assertStaleSaveRejected( $this->save_as_a( $generation ) );

		$this->assertSame(
			'in-progress',
			$this->get_stack( $this->task_id ),
			"A's stale save must not revert the card to its previous stack."
		);
		$this->assertSame( 'Original title', get_post( $this->task_id )->post_title );
	}

	/**
	 * Assigning a user invalidates an open form, which would otherwise resubmit
	 * the assignee list it was rendered with.
	 */
	public function test_assign_invalidates_an_open_form() {
		$generation = $this->open_form_as_a();

		wp_set_current_user( $this->user_b );
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/assign' );
		$request->set_url_params( array( 'id' => $this->task_id ) );
		$request->set_param( 'user_id', $this->user_b );
		( new Decker_Tasks_Rest_Ops( new Decker_Tasks() ) )->assign_user_to_task( $request );

		$this->assertNotSame( $generation, $this->current_generation() );
		$this->assertStaleSaveRejected( $this->save_as_a( $generation ) );
	}

	/**
	 * Removing a user invalidates an open form.
	 */
	public function test_leave_invalidates_an_open_form() {
		update_post_meta( $this->task_id, 'assigned_users', array( $this->user_b ) );

		$generation = $this->open_form_as_a();

		wp_set_current_user( $this->user_b );
		$request = new WP_REST_Request( 'POST', '/decker/v1/tasks/' . $this->task_id . '/leave' );
		$request->set_url_params( array( 'id' => $this->task_id ) );
		$request->set_param( 'user_id', $this->user_b );
		( new Decker_Tasks_Rest_Ops( new Decker_Tasks() ) )->remove_user_from_task( $request );

		$this->assertNotSame( $generation, $this->current_generation() );
		$this->assertStaleSaveRejected( $this->save_as_a( $generation ) );
	}

	/**
	 * Changing the due date invalidates an open form.
	 */
	public function test_due_date_change_invalidates_an_open_form() {
		$generation = $this->open_form_as_a();

		wp_set_current_user( $this->user_b );
		$request = new WP_REST_Request( 'PUT', '/decker/v1/tasks/' . $this->task_id . '/update_due_date' );
		$request->set_url_params( array( 'id' => $this->task_id ) );
		$request->set_param( 'id', $this->task_id );
		$request->set_param( 'duedate', '2026-12-31' );
		( new Decker_Tasks_Rest_Ops( new Decker_Tasks() ) )->update_task_due_date( $request );

		$this->assertNotSame( $generation, $this->current_generation() );
		$this->assertStaleSaveRejected( $this->save_as_a( $generation ) );
	}

	/**
	 * A generic REST update rotates the generation too, so the token cannot be
	 * replayed by a stale form or a second REST client of the same user.
	 */
	public function test_generic_rest_update_rotates_the_generation() {
		$generation = $this->open_form_as_a();

		$request = new WP_REST_Request( 'POST', sprintf( '/wp/v2/tasks/%d', $this->task_id ) );
		$request->set_param( 'title', 'Updated over generic REST' );
		$request->set_param( 'lock_generation', $generation );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertNotSame(
			$generation,
			$this->current_generation(),
			'A successful REST update should rotate the generation.'
		);

		$this->assertStaleSaveRejected( $this->save_as_a( $generation ) );
	}

	/**
	 * Invalidation must not steal the lock: whoever is editing keeps ownership
	 * and only has to reload, rather than losing the card to a passing drag.
	 */
	public function test_invalidation_preserves_the_lock_owner() {
		$this->open_form_as_a();
		$before = $this->locks->get_lock_info( $this->task_id, $this->user_b );

		$this->move_card_as_b( 'in-progress' );

		$after = $this->locks->get_lock_info( $this->task_id, $this->user_b );

		$this->assertTrue( $before['locked'], 'A should hold the lock before the move.' );
		$this->assertTrue( $after['locked'], 'A should still hold the lock after the move.' );
		$this->assertSame( $this->user_a, (int) $after['owner']['id'] );
	}

	/**
	 * A never-locked task has no session to invalidate, so out-of-band mutations
	 * must not start handing out generations that later saves would then need.
	 */
	public function test_never_locked_task_is_not_given_a_generation() {
		$this->assertSame( '', $this->current_generation() );

		$this->move_card_as_b( 'in-progress' );

		$this->assertSame(
			'',
			$this->current_generation(),
			'Mutating a never-locked task must not mint a generation.'
		);

		// A save with no token still works, as it did before this change.
		wp_set_current_user( $this->user_a );
		$_POST = array(
			'task_id' => $this->task_id,
			'title'   => 'Saved without a lock',
			'stack'   => 'in-progress',
			'board'   => $this->board_id,
		);

		$response = $this->ajax_save();

		$this->assertTrue( $response['success'] );
	}

	/**
	 * Read the authoritative generation token straight from the stored state.
	 *
	 * @return string The current generation, or an empty string when never locked.
	 */
	private function current_generation(): string {
		return ( new Decker_Task_Lock_State() )->generation( $this->task_id );
	}

	/**
	 * Read the current stack term of a task.
	 *
	 * @param int $task_id The task post ID.
	 * @return string The stack slug, or an empty string.
	 */
	private function get_stack( int $task_id ): string {
		$stack = get_post_meta( $task_id, 'stack', true );

		return is_string( $stack ) ? $stack : '';
	}
}
