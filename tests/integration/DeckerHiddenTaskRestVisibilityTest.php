<?php
/**
 * Hidden tasks must stay hidden over REST.
 *
 * A task flagged `hidden` is meant for the people it concerns: its author, the
 * responsible user, its assignees and administrators. That rule was enforced by
 * the Abilities API and by the Decker listings, but not by the REST endpoints, so
 * any user with `edit_posts` could read a hidden task through `/wp/v2/tasks/<id>`,
 * find it in the collection, and read its comments.
 *
 * @package Decker
 */

class DeckerHiddenTaskRestVisibilityTest extends Decker_Test_Base {

	/**
	 * REST server used to dispatch the requests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Administrator, who may always see hidden tasks.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Author of the hidden task.
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Responsible user for the hidden task.
	 *
	 * @var int
	 */
	private $responsible_id;

	/**
	 * Assignee of the hidden task.
	 *
	 * @var int
	 */
	private $assignee_id;

	/**
	 * Editor with `edit_posts` but no relation to the hidden task.
	 *
	 * @var int
	 */
	private $outsider_id;

	/**
	 * The hidden task.
	 *
	 * @var int
	 */
	private $hidden_task_id;

	/**
	 * An ordinary, visible task used as a control.
	 *
	 * @var int
	 */
	private $visible_task_id;

	/**
	 * Comment on the hidden task.
	 *
	 * @var int
	 */
	private $hidden_comment_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );
		do_action( 'init' );

		$this->admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->responsible_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assignee_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->outsider_id    = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin_id );

		$board_id = self::factory()->board->create(
			array(
				'name'  => 'Hidden Task Board',
				'color' => '#0099ff',
			)
		);

		$this->hidden_task_id = self::factory()->task->create(
			array(
				'post_title'  => 'HIDDEN Task Title',
				'board'       => $board_id,
				'stack'       => 'to-do',
				'post_author' => $this->author_id,
				'author'      => $this->author_id,
				'responsable' => $this->responsible_id,
			)
		);
		update_post_meta( $this->hidden_task_id, 'hidden', '1' );
		update_post_meta( $this->hidden_task_id, 'assigned_users', array( $this->assignee_id ) );

		$this->visible_task_id = self::factory()->task->create(
			array(
				'post_title' => 'VISIBLE Task Title',
				'board'      => $board_id,
				'stack'      => 'to-do',
			)
		);

		$this->hidden_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->hidden_task_id,
				'comment_content'  => 'HIDDEN task comment.',
				'comment_approved' => 1,
			)
		);

		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Dispatch a GET as a given user.
	 *
	 * @param int    $user_id User to act as.
	 * @param string $route   Route to request.
	 * @return WP_REST_Response
	 */
	private function get_as( $user_id, $route ) {
		wp_set_current_user( $user_id );

		return $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );
	}

	/**
	 * The people a hidden task belongs to keep reading it.
	 */
	public function test_related_users_still_read_the_hidden_task() {
		$allowed = array(
			'author'      => $this->author_id,
			'responsible' => $this->responsible_id,
			'assignee'    => $this->assignee_id,
			'admin'       => $this->admin_id,
		);

		foreach ( $allowed as $role => $user_id ) {
			$response = $this->get_as( $user_id, '/wp/v2/tasks/' . $this->hidden_task_id );

			$this->assertEquals( 200, $response->get_status(), sprintf( 'The %s was denied the hidden task.', $role ) );
			$this->assertEquals( $this->hidden_task_id, $response->get_data()['id'] );
		}
	}

	/**
	 * Anyone else is refused, `edit_posts` notwithstanding.
	 */
	public function test_outsider_cannot_read_the_hidden_task() {
		wp_set_current_user( $this->outsider_id );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'Fixture is wrong: the outsider has no edit_posts.' );

		$response = $this->get_as( $this->outsider_id, '/wp/v2/tasks/' . $this->hidden_task_id );

		$this->assertEquals( 403, $response->get_status(), 'The outsider read a hidden task.' );
		$this->assertStringNotContainsString( 'HIDDEN Task Title', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * An ordinary task is untouched for the same user.
	 */
	public function test_visible_task_is_unaffected() {
		$response = $this->get_as( $this->outsider_id, '/wp/v2/tasks/' . $this->visible_task_id );

		$this->assertEquals( 200, $response->get_status(), 'An ordinary task was refused.' );
		$this->assertStringContainsString( 'VISIBLE Task Title', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The outsider may not write to the hidden task either.
	 */
	public function test_outsider_cannot_write_to_the_hidden_task() {
		wp_set_current_user( $this->outsider_id );

		$update = new WP_REST_Request( 'POST', '/wp/v2/tasks/' . $this->hidden_task_id );
		$update->set_param( 'title', 'Renamed by an outsider' );
		$this->assertEquals( 403, $this->server->dispatch( $update )->get_status(), 'The outsider edited a hidden task.' );

		$delete = new WP_REST_Request( 'DELETE', '/wp/v2/tasks/' . $this->hidden_task_id );
		$this->assertEquals( 403, $this->server->dispatch( $delete )->get_status(), 'The outsider deleted a hidden task.' );

		$this->assertEquals( 'HIDDEN Task Title', get_post( $this->hidden_task_id )->post_title );
	}

	/**
	 * The collection drops hidden tasks for non-administrators and keeps the rest.
	 */
	public function test_collection_drops_hidden_tasks() {
		$response = $this->get_as( $this->outsider_id, '/wp/v2/tasks' );
		$this->assertEquals( 200, $response->get_status() );

		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertNotContains( $this->hidden_task_id, $ids, 'A hidden task appeared in the collection.' );
		$this->assertContains( $this->visible_task_id, $ids, 'The collection lost an ordinary task.' );

		// Administrators keep the full listing.
		$response = $this->get_as( $this->admin_id, '/wp/v2/tasks' );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertContains( $this->hidden_task_id, $ids, 'The administrator lost the hidden task.' );
		$this->assertContains( $this->visible_task_id, $ids, 'The administrator lost an ordinary task.' );
	}

	/**
	 * Comments of a hidden task follow the task.
	 */
	public function test_comments_follow_the_hidden_task() {
		$response = $this->get_as( $this->outsider_id, '/wp/v2/comments/' . $this->hidden_comment_id );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'The outsider read a hidden task comment.' );
		$this->assertStringNotContainsString( 'HIDDEN task comment.', wp_json_encode( $response->get_data() ) );

		foreach ( array( 'assignee' => $this->assignee_id, 'author' => $this->author_id, 'admin' => $this->admin_id ) as $role => $user_id ) {
			$response = $this->get_as( $user_id, '/wp/v2/comments/' . $this->hidden_comment_id );
			$this->assertEquals( 200, $response->get_status(), sprintf( 'The %s was denied a hidden task comment.', $role ) );
			$this->assertStringContainsString( 'HIDDEN task comment.', $response->get_data()['content']['rendered'] );
		}
	}

	/**
	 * The Abilities API keeps applying the same rule through the shared helper.
	 */
	public function test_abilities_path_still_conceals_the_hidden_task() {
		wp_set_current_user( $this->outsider_id );
		$this->assertTrue( Decker_Tasks::is_hidden_from_current_user( $this->hidden_task_id ) );

		wp_set_current_user( $this->assignee_id );
		$this->assertFalse( Decker_Tasks::is_hidden_from_current_user( $this->hidden_task_id ) );

		wp_set_current_user( $this->outsider_id );
		$this->assertFalse(
			Decker_Tasks::is_hidden_from_current_user( $this->visible_task_id ),
			'An ordinary task was treated as hidden.'
		);

		$this->assertFalse(
			Decker_Tasks::is_hidden_from_current_user( 0 ),
			'A missing task was treated as hidden.'
		);
	}

	/**
	 * Anonymous visitors remain denied, as before.
	 */
	public function test_anonymous_still_denied() {
		$response = $this->get_as( 0, '/wp/v2/tasks/' . $this->hidden_task_id );
		$this->assertEquals( 403, $response->get_status() );
		$this->assertTrue( Decker_Tasks::is_hidden_from_current_user( $this->hidden_task_id ) );
	}
}
