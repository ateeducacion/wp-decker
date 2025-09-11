<?php
/**
 * Tests for REST comment protection on protected CPTs.
 *
 * @package Decker
 */

class DeckerRestCommentProtectionTest extends Decker_Test_Base {

    private $server;
    private $task_id;
    private $public_post_id;
    private $task_comment_id;
    private $public_comment_id;
    private $admin_id;

    public function set_up(): void {
        parent::set_up();

        // Allow anonymous comments so our protection can trigger before core blocks.
        update_option( 'comment_registration', 0 );
        update_option( 'require_name_email', 1 );

        // Initialize REST server and register routes.
        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        do_action( 'init' );

        // Create admin and authenticate to use factories requiring caps.
        $this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->admin_id );

        // Create required board and a protected CPT post (decker_task) via factories.
        $board_id = self::factory()->board->create( array(
            'name'  => 'Board REST Test',
            'color' => '#0099ff',
        ) );

        $this->task_id = self::factory()->task->create( array(
            'post_title'  => 'Protected Task',
            'board'       => $board_id,
            'stack'       => 'to-do',
            // Author/responsable default to current user if omitted.
        ) );

        // Create a normal public post.
        $this->public_post_id = self::factory()->post->create( array(
            'post_type'   => 'post',
            'post_status' => 'publish',
            'post_title'  => 'Public Post',
        ) );

        // Add one comment on each post.
        $this->task_comment_id = self::factory()->comment->create( array(
            'comment_post_ID' => $this->task_id,
            'comment_content' => 'Comment on protected task',
        ) );

        $this->public_comment_id = self::factory()->comment->create( array(
            'comment_post_ID' => $this->public_post_id,
            'comment_content' => 'Comment on public post',
        ) );

        // Now make requests unauthenticated.
        wp_set_current_user( 0 );
    }

    public function tear_down(): void {
        // Ensure unauthenticated before deletions.
        wp_set_current_user( 0 );
        if ( $this->task_comment_id ) {
            wp_delete_comment( $this->task_comment_id, true );
        }
        if ( $this->public_comment_id ) {
            wp_delete_comment( $this->public_comment_id, true );
        }
        if ( $this->task_id ) {
            wp_delete_post( $this->task_id, true );
        }
        if ( $this->public_post_id ) {
            wp_delete_post( $this->public_post_id, true );
        }
        if ( $this->admin_id ) {
            wp_delete_user( $this->admin_id );
        }

        parent::tear_down();
    }

    /**
     * Unauthenticated users should not see comments from protected CPTs.
     */
    public function test_unauthenticated_comment_collection_excludes_protected_cpts() {
        $request  = new WP_REST_Request( 'GET', '/wp/v2/comments' );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 200, $response->get_status() );

        $data = $response->get_data();
        $ids  = wp_list_pluck( $data, 'id' );

        // Should include the public post comment, but not the protected task comment.
        $this->assertContains( $this->public_comment_id, $ids );
        $this->assertNotContains( $this->task_comment_id, $ids );
    }

    /**
     * Unauthenticated users cannot fetch a single comment from a protected CPT.
     */
    public function test_unauthenticated_cannot_get_single_protected_comment() {
        $request  = new WP_REST_Request( 'GET', '/wp/v2/comments/' . $this->task_comment_id );
        $response = $this->server->dispatch( $request );

        $this->assertEquals( 401, $response->get_status() );

        $data = $response->get_data();
        $this->assertArrayHasKey( 'message', $data );
        $this->assertSame( 'Autenticación requerida para ver este comentario.', $data['message'] );
    }

    /**
     * Unauthenticated users cannot create comments on protected CPTs.
     */
    public function test_unauthenticated_cannot_create_comment_on_protected_cpt() {
        $request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
        $request->set_param( 'post', $this->task_id );
        $request->set_param( 'content', 'Trying to comment' );
        $request->set_param( 'author_name', 'Anónimo' );
        $request->set_param( 'author_email', 'anon@example.com' );

        $response = $this->server->dispatch( $request );

        $this->assertEquals( 401, $response->get_status() );
        $this->assertSame( 'Autenticación requerida para crear comentarios en este tipo de contenido.', $response->get_data()['message'] );
    }

    /**
     * Unauthenticated users cannot delete or edit comments on protected CPTs.
     */
    public function test_unauthenticated_cannot_modify_comment_on_protected_cpt() {
        // Attempt DELETE.
        $delete = new WP_REST_Request( 'DELETE', '/wp/v2/comments/' . $this->task_comment_id );
        $response = $this->server->dispatch( $delete );
        $this->assertEquals( 401, $response->get_status() );
        $this->assertSame( 'You are not authorized to access this resource.', $response->get_data()['message'] );

        // Attempt UPDATE.
        $update = new WP_REST_Request( 'PUT', '/wp/v2/comments/' . $this->task_comment_id );
        $update->set_param( 'content', 'Edit intent' );
        $response = $this->server->dispatch( $update );
        $this->assertEquals( 401, $response->get_status() );
        $this->assertSame( 'You are not authorized to access this resource.', $response->get_data()['message'] );
    }
}
