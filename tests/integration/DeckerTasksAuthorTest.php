<?php
/**
 * Tests for author assignment when saving decker_task via AJAX handler.
 *
 * @package Decker
 */

class DeckerTasksAuthorTest extends Decker_Test_Base {

    private $board_id;

    public function set_up() {
        parent::set_up();
        // Ensure CPTs are registered
        do_action( 'init' );

        // Create a board with a user that has sufficient permissions.
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $admin_id );
        $board_id = self::factory()->board->create();
        $this->assertNotWPError( $board_id, 'Could not create board for tests.' );
        $this->board_id = $board_id;

        // Reset user to unauthenticated so each test can set its own.
        wp_set_current_user( 0 );
    }

    public function tear_down() {
        if ( $this->board_id ) {
            wp_delete_term( $this->board_id, 'decker_board' );
        }
        parent::tear_down();
    }

    /**
     * Default author on create is current user when no author is provided.
     */
    public function test_default_author_is_current_user_on_create() {
        $creator_id = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $creator_id );

        add_filter( 'decker_save_task_send_response', '__return_false' );
        $tasks = new Decker_Tasks();

        $_POST = array(
            'task_id'      => 0,
            'title'        => 'Otra tarea',
            'description'  => 'Texto',
            'stack'        => 'to-do',
            'board'        => $this->board_id,
            'due_date'     => '2025-01-02',
            // no author
        );

        $resp = $tasks->handle_save_decker_task();
        remove_filter( 'decker_save_task_send_response', '__return_false' );

        $this->assertIsArray( $resp );
        $this->assertTrue( $resp['success'] );
        $post = get_post( $resp['task_id'] );
        $this->assertInstanceOf( 'WP_Post', $post );
        $this->assertEquals( $creator_id, (int) $post->post_author, 'Default author must be the current user.' );
    }

    /**
     * Admin can set author to another user.
     */
    public function test_admin_can_set_other_author_on_create() {
        $admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
        wp_set_current_user( $admin_id );

        add_filter( 'decker_save_task_send_response', '__return_false' );
        $tasks = new Decker_Tasks();

        $_POST = array(
            'task_id'      => 0,
            'title'        => 'Tarea admin',
            'description'  => 'Detalle',
            'stack'        => 'to-do',
            'board'        => $this->board_id,
            'due_date'     => '2025-01-03',
            'author'       => $other_id,
        );

        $resp = $tasks->handle_save_decker_task();
        remove_filter( 'decker_save_task_send_response', '__return_false' );

        $this->assertIsArray( $resp );
        $this->assertTrue( $resp['success'] );
        $post = get_post( $resp['task_id'] );
        $this->assertInstanceOf( 'WP_Post', $post );
        $this->assertEquals( $other_id, (int) $post->post_author, 'Administrator can assign another author.' );

    }
}
