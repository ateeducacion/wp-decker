<?php
/**
 * Comments on protected post types must be no easier to reach than the post itself.
 *
 * Decker gates `decker_kb`, `tasks` and `decker_event` behind `edit_posts`, but the
 * comment guards used to require only that the visitor be logged in. Any subscriber
 * could therefore read the comments of a task they were denied access to.
 *
 * The collection guard had a second defect: it narrowed `comments_clauses`, which is
 * not part of WP_Comment_Query's cache key, so an authorized user's cached result set
 * was served to the next unauthorized request with the same arguments.
 *
 * @package Decker
 */

class DeckerCommentAccessFollowsPostTest extends Decker_Test_Base {

	/**
	 * REST server used to dispatch the requests.
	 *
	 * @var WP_REST_Server
	 */
	private $server;

	/**
	 * Administrator used to build the fixtures.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber, without `edit_posts`.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Contributor: has `edit_posts` but not `read_private_posts`.
	 *
	 * @var int
	 */
	private $contributor_id;

	/**
	 * Editor, with full access to Decker content.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Published task.
	 *
	 * @var int
	 */
	private $task_id;

	/**
	 * Private task, used to exercise the per-post half of the rule.
	 *
	 * @var int
	 */
	private $private_task_id;

	/**
	 * Published KB article.
	 *
	 * @var int
	 */
	private $kb_id;

	/**
	 * Ordinary published post, used as a control.
	 *
	 * @var int
	 */
	private $public_post_id;

	/**
	 * Comment on the published task.
	 *
	 * @var int
	 */
	private $task_comment_id;

	/**
	 * Comment on the private task.
	 *
	 * @var int
	 */
	private $private_comment_id;

	/**
	 * Comment on the KB article.
	 *
	 * @var int
	 */
	private $kb_comment_id;

	/**
	 * Comment on the ordinary post.
	 *
	 * @var int
	 */
	private $public_comment_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$this->server   = new WP_REST_Server();
		$wp_rest_server = $this->server;
		do_action( 'rest_api_init' );
		do_action( 'init' );

		$this->admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->contributor_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->editor_id      = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin_id );

		$board_id = self::factory()->board->create(
			array(
				'name'  => 'Comment Access Board',
				'color' => '#0099ff',
			)
		);

		$this->task_id = self::factory()->task->create(
			array(
				'post_title' => 'Task With Comments',
				'board'      => $board_id,
				'stack'      => 'to-do',
			)
		);

		$this->private_task_id = self::factory()->task->create(
			array(
				'post_title' => 'Private Task',
				'board'      => $board_id,
				'stack'      => 'to-do',
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->private_task_id,
				'post_status' => 'private',
			)
		);

		$this->kb_id = self::factory()->post->create(
			array(
				'post_type'      => 'decker_kb',
				'post_status'    => 'publish',
				'post_title'     => 'KB With Comments',
				'comment_status' => 'open',
			)
		);

		$this->public_post_id = self::factory()->post->create(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => 'Ordinary Post',
				'comment_status' => 'open',
			)
		);

		$this->task_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->task_id,
				'comment_content'  => 'CONFIDENTIAL task comment.',
				'comment_approved' => 1,
			)
		);

		$this->private_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->private_task_id,
				'comment_content'  => 'CONFIDENTIAL private task comment.',
				'comment_approved' => 1,
			)
		);

		$this->kb_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->kb_id,
				'comment_content'  => 'CONFIDENTIAL kb comment.',
				'comment_approved' => 1,
			)
		);

		$this->public_comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $this->public_post_id,
				'comment_content'  => 'Ordinary public comment.',
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
	 * Dispatch a GET on a single comment.
	 *
	 * @param int $comment_id Comment to read.
	 * @return WP_REST_Response
	 */
	private function get_comment_response( $comment_id ) {
		return $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/comments/' . $comment_id ) );
	}

	/**
	 * Assert a denial that carries none of the comment body.
	 *
	 * @param WP_REST_Response $response Dispatched response.
	 * @param string           $context  Description used in failure messages.
	 */
	private function assertDenied( $response, $context ) {
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			sprintf( '%s: expected a denial, got HTTP %d.', $context, $response->get_status() )
		);

		$this->assertStringNotContainsString(
			'CONFIDENTIAL',
			wp_json_encode( $response->get_data() ),
			sprintf( '%s: the refusal leaked the comment body.', $context )
		);
	}

	/**
	 * A subscriber has no access to a task, so none to its comments either.
	 */
	public function test_subscriber_cannot_read_comments_on_protected_posts() {
		wp_set_current_user( $this->subscriber_id );

		// Control: the subscriber is indeed denied the task itself.
		$task = $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/tasks/' . $this->task_id ) );
		$this->assertEquals( 403, $task->get_status(), 'Fixture is wrong: the subscriber could read the task.' );

		$this->assertDenied( $this->get_comment_response( $this->task_comment_id ), 'subscriber on a task comment' );
		$this->assertDenied( $this->get_comment_response( $this->kb_comment_id ), 'subscriber on a KB comment' );
	}

	/**
	 * Anonymous visitors stay denied.
	 */
	public function test_anonymous_cannot_read_comments_on_protected_posts() {
		$this->assertDenied( $this->get_comment_response( $this->task_comment_id ), 'anonymous on a task comment' );
		$this->assertDenied( $this->get_comment_response( $this->kb_comment_id ), 'anonymous on a KB comment' );
	}

	/**
	 * A user with `edit_posts` keeps the access the Decker UI depends on.
	 */
	public function test_authorized_users_keep_reading_protected_comments() {
		foreach ( array( 'contributor' => $this->contributor_id, 'editor' => $this->editor_id, 'admin' => $this->admin_id ) as $role => $user_id ) {
			wp_set_current_user( $user_id );

			$response = $this->get_comment_response( $this->task_comment_id );
			$this->assertEquals( 200, $response->get_status(), sprintf( '%s was denied a task comment.', $role ) );
			$this->assertStringContainsString( 'CONFIDENTIAL task comment.', $response->get_data()['content']['rendered'] );

			$response = $this->get_comment_response( $this->kb_comment_id );
			$this->assertEquals( 200, $response->get_status(), sprintf( '%s was denied a KB comment.', $role ) );
			$this->assertStringContainsString( 'CONFIDENTIAL kb comment.', $response->get_data()['content']['rendered'] );
		}
	}

	/**
	 * `edit_posts` alone is not enough: the specific post must be readable too.
	 */
	public function test_contributor_cannot_read_comments_on_a_private_task() {
		wp_set_current_user( $this->contributor_id );

		$this->assertFalse( current_user_can( 'read_post', $this->private_task_id ), 'Fixture is wrong: the contributor can read the private task.' );
		$this->assertDenied( $this->get_comment_response( $this->private_comment_id ), 'contributor on a private task comment' );

		// An editor may read that task, and therefore its comments.
		wp_set_current_user( $this->editor_id );
		$response = $this->get_comment_response( $this->private_comment_id );
		$this->assertEquals( 200, $response->get_status(), 'The editor was denied a private task comment.' );
	}

	/**
	 * Ordinary posts are untouched for everyone.
	 */
	public function test_ordinary_post_comments_remain_public() {
		foreach ( array( 'anonymous' => 0, 'subscriber' => $this->subscriber_id, 'editor' => $this->editor_id ) as $role => $user_id ) {
			wp_set_current_user( $user_id );

			$response = $this->get_comment_response( $this->public_comment_id );
			$this->assertEquals( 200, $response->get_status(), sprintf( '%s was denied an ordinary comment.', $role ) );
			$this->assertStringContainsString( 'Ordinary public comment.', $response->get_data()['content']['rendered'] );
		}
	}

	/**
	 * Fetch the comment collection and return the IDs it contains.
	 *
	 * @param int $user_id User to dispatch as.
	 * @return int[]
	 */
	private function collection_ids_for( $user_id ) {
		wp_set_current_user( $user_id );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/comments' ) );
		$this->assertEquals( 200, $response->get_status(), 'The comment collection did not return a list.' );

		return wp_list_pluck( $response->get_data(), 'id' );
	}

	/**
	 * The collection hides protected comments from users without access, and keeps
	 * showing them to users with access.
	 */
	public function test_collection_follows_the_same_rule() {
		foreach ( array( 'anonymous' => 0, 'subscriber' => $this->subscriber_id ) as $role => $user_id ) {
			$ids = $this->collection_ids_for( $user_id );

			$this->assertNotContains( $this->task_comment_id, $ids, sprintf( '%s saw a task comment in the collection.', $role ) );
			$this->assertNotContains( $this->kb_comment_id, $ids, sprintf( '%s saw a KB comment in the collection.', $role ) );
			$this->assertContains( $this->public_comment_id, $ids, sprintf( '%s lost the ordinary comment.', $role ) );
		}

		$ids = $this->collection_ids_for( $this->editor_id );
		$this->assertContains( $this->task_comment_id, $ids, 'The editor lost task comments from the collection.' );
		$this->assertContains( $this->kb_comment_id, $ids, 'The editor lost KB comments from the collection.' );
		$this->assertContains( $this->public_comment_id, $ids, 'The editor lost the ordinary comment.' );
	}

	/**
	 * An authorized user's cached collection must not be replayed to an
	 * unauthorized one.
	 *
	 * WP_Comment_Query builds its cache key from the query vars only, so a guard
	 * that narrows `comments_clauses` leaves both users sharing one cache entry.
	 */
	public function test_collection_cache_is_not_shared_across_permission_levels() {
		$fetch = function ( $user_id ) {
			wp_set_current_user( $user_id );

			$request = new WP_REST_Request( 'GET', '/wp/v2/comments' );
			$request->set_param( 'post', $this->task_id );

			return wp_list_pluck( $this->server->dispatch( $request )->get_data(), 'id' );
		};

		// Warm the cache as a user who may see the comment, then ask anonymously.
		$this->assertContains( $this->task_comment_id, $fetch( $this->editor_id ), 'The editor could not see the task comment.' );
		$this->assertNotContains( $this->task_comment_id, $fetch( 0 ), 'An anonymous request was served the editor cached result.' );

		// And the other way round: a warm anonymous cache must not blind the editor.
		$this->assertNotContains( $this->task_comment_id, $fetch( 0 ), 'Anonymous saw the task comment.' );
		$this->assertContains( $this->task_comment_id, $fetch( $this->editor_id ), 'The editor was served the anonymous cached result.' );
	}

	/**
	 * Writes follow the same rule: a subscriber may not comment on a protected post.
	 */
	public function test_subscriber_cannot_write_comments_on_protected_posts() {
		wp_set_current_user( $this->subscriber_id );

		$create = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$create->set_param( 'post', $this->task_id );
		$create->set_param( 'content', 'Injected by a subscriber.' );
		$response = $this->server->dispatch( $create );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'A subscriber created a comment on a protected task.' );

		$update = new WP_REST_Request( 'PUT', '/wp/v2/comments/' . $this->task_comment_id );
		$update->set_param( 'content', 'Tampered.' );
		$response = $this->server->dispatch( $update );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'A subscriber edited a comment on a protected task.' );

		$response = $this->server->dispatch( new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $this->task_comment_id ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'A subscriber deleted a comment on a protected task.' );

		$this->assertStringContainsString(
			'CONFIDENTIAL task comment.',
			get_comment( $this->task_comment_id )->comment_content,
			'The protected comment was modified.'
		);
	}

	/**
	 * An authorized user can still create a comment on a protected post.
	 */
	public function test_authorized_user_can_still_comment_on_protected_posts() {
		wp_set_current_user( $this->editor_id );

		$create = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$create->set_param( 'post', $this->task_id );
		$create->set_param( 'content', 'Legitimate editor comment.' );

		$response = $this->server->dispatch( $create );

		$this->assertEquals( 201, $response->get_status(), 'The editor could not comment on a task.' );
		$this->assertStringContainsString( 'Legitimate editor comment.', $response->get_data()['content']['rendered'] );

		wp_delete_comment( $response->get_data()['id'], true );
	}
}
