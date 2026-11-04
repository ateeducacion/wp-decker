<?php
/** Description policy at the complete AJAX and native WordPress boundaries. @package Decker */
require_once dirname( __DIR__ ) . '/includes/class-decker-description-snapshot.php';

class DeckerTaskDescriptionPolicyTest extends Decker_Test_Base {
	private $editor;
	private $board;

	public function set_up() {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $this->editor )->add_cap( 'unfiltered_html', false );
		wp_set_current_user( $this->editor );
		$this->board = self::factory()->board->create();
	}

	public function tear_down() {
		remove_filter( 'decker_save_task_send_response', '__return_false' );
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_ajax_complete_save_and_title_only_resave_keep_safe_html() {
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$id = self::factory()->task->create( array( 'board' => $this->board ) );
		$locks = new Decker_Task_Locks();
		$locks->acquire_lock( $id, $this->editor );
		$html = '<h2>Heading</h2><hr><p style="text-align: right">C:\\path 😀</p><ul><li data-list="checked"><br></li><li data-list="unchecked"><strong>Text</strong><a href="https://example.invalid/?a=1&amp;b=2">Link</a><img src="https://example.invalid/image.png" alt="Image"></li></ul><ol start="5"><li>Five</li></ol><pre><code>' . "\tline\n\nnext" . '</code></pre>';
		$_POST = wp_slash( array( 'task_id' => $id, 'title' => 'Initial title', 'description' => $html, 'board' => $this->board, 'stack' => 'to-do', 'author' => $this->editor, 'responsable' => $this->editor, 'lock_generation' => ( new Decker_Task_Lock_State() )->generation( $id ) ) );
		add_filter( 'decker_save_task_send_response', '__return_false' );
		$handler = new Decker_Task_Ajax_Save( new Decker_Tasks() );
		$result = $handler->handle_save_decker_task();
		$this->assertTrue( $result['success'] );
		$first = get_post( $id )->post_content;
		$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( $first ) );
		foreach ( array( '<h2>', '<hr', '<img ', 'start="5"', 'C:\\path', "\tline\n\nnext" ) as $expected ) {
			$this->assertStringContainsString( $expected, $first );
		}
		$_POST['title'] = 'Title only';
		$_POST['description'] = wp_slash( $first );
		$_POST['lock_generation'] = $result['generation'];
		$this->assertTrue( $handler->handle_save_decker_task()['success'] );
		$this->assertSame( $first, get_post( $id )->post_content );
	}

	public function test_native_saves_and_clone_share_policy_without_unfiltered_html() {
		$html = '<ol><li data-list="checked"><span class="ql-ui"></span><em>Done</em></li><li data-list="unchecked"><img alt="Existing missing source"></li></ol><h2>Title</h2><hr><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">Unsafe link</a><p style="background-image:url(javascript:alert(1)); color:red">Text</p>';
		$id = wp_insert_post( wp_slash( array( 'post_type' => 'decker_task', 'post_title' => 'Native task', 'post_content' => $html, 'post_status' => 'publish', 'post_author' => $this->editor, 'meta_input' => array( 'stack' => 'to-do' ), 'tax_input' => array( 'decker_board' => array( $this->board ) ) ) ), true );
		$this->assertIsInt( $id );
		$content = get_post( $id )->post_content;
		foreach ( array( '<script', 'onclick', 'javascript:', 'background-image', 'ql-ui' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $content );
		}
		$this->assertStringContainsString( '<ul>', $content );
		$this->assertStringContainsString( '<h2>', $content );
		$this->assertStringContainsString( 'Existing missing source', $content );
		$clone = Decker_Task_Clone::clone_task( $id );
		$this->assertNotWPError( $clone );
		$this->assertSame( Decker_Description_Snapshot::items( $content ), Decker_Description_Snapshot::items( get_post( $clone )->post_content ) );
	}

	public function test_first_write_initializes_a_new_site_without_another_init_action() {
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='decker_task'" ) );
		delete_option( Decker_Task_Description::VERSION_OPTION );
		foreach ( array( 'First', 'Second' ) as $title ) {
			$id = wp_insert_post( array( 'post_type' => 'decker_task', 'post_title' => $title, 'post_content' => '<p>New site</p>' ), true );
			$this->assertNotWPError( $id );
			$this->assertGreaterThan( 0, $id );
		}
		$this->assertSame( 1, (int) get_option( Decker_Task_Description::VERSION_OPTION ) );
	}

	public function test_old_site_cannot_overwrite_descriptions_until_backed_up() {
		$id = self::factory()->task->create( array( 'board' => $this->board ) );
		$before = get_post( $id )->to_array();
		delete_option( Decker_Task_Description::VERSION_OPTION );
		$this->assertFalse( Decker_Task_Description::is_ready() );
		$result = wp_update_post( array( 'ID' => $id, 'post_title' => 'Must not commit' ), true );
		$this->assertWPError( $result );
		$this->assertSame( $before, get_post( $id )->to_array() );
		$this->assertSame( array(), ( new Decker_Task_Description_Migration() )->run( false )['errors'] );
		$this->assertTrue( Decker_Task_Description::is_ready() );
	}

	public function test_merge_keeps_both_checklists_and_inline_content() {
		$first = '<ul><li data-list="unchecked"><strong>Destination</strong></li></ul>';
		$second = '<ul><li data-list="checked"><br></li><li data-list="unchecked"><em>Source</em><img src="https://example.invalid/source.png"></li></ul>';
		$destination = self::factory()->task->create( array( 'board' => $this->board, 'post_content' => $first ) );
		$source = self::factory()->task->create( array( 'board' => $this->board, 'post_content' => $second ) );
		$this->assertTrue( Decker_Task_Merge::merge_tasks( $source, $destination ) );
		$expected = array_merge( Decker_Description_Snapshot::items( $first ), Decker_Description_Snapshot::items( $second ) );
		$this->assertSame( $expected, Decker_Description_Snapshot::items( get_post( $destination )->post_content ) );
		$this->assertStringContainsString( '<hr', get_post( $destination )->post_content );
		$this->assertSame( 'archived', get_post_status( $source ) );
	}

	public function test_other_post_types_are_not_normalized() {
		$html = '<p class="ql-align-center">Other entity</p>';
		$id = wp_insert_post( array( 'post_type' => 'decker_kb', 'post_title' => 'Knowledge', 'post_content' => $html ), true );
		$this->assertNotWPError( $id );
		$this->assertSame( $html, get_post( $id )->post_content );
	}

	public function test_native_rest_with_core_kses_and_abilities_keep_each_checklist_state() {
		kses_init();
		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$html = '<h2>Heading</h2><hr><ul><li data-list="checked"><strong>Done</strong></li><li data-list="unchecked"><br></li></ul>';
		$request = new WP_REST_Request( 'POST', '/wp/v2/tasks' );
		$request->set_body_params( array( 'title' => 'REST policy', 'content' => $html, 'status' => 'publish', 'decker_board' => array( $this->board ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];
		$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( get_post( $id )->post_content ) );
		$service = new Decker_Ability_Service();
		$created = $service->create_task( array( 'title' => 'Ability policy', 'description' => $html, 'board_id' => $this->board, 'stack' => 'to-do' ) );
		$this->assertNotWPError( $created );
		$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( get_post( $created['id'] )->post_content ) );
	}
}
