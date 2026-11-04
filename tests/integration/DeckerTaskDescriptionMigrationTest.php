<?php
/** Integration coverage for backups, task isolation, resume and rollback. @package Decker */
require_once dirname( __DIR__ ) . '/includes/class-decker-description-snapshot.php';

class DeckerTaskDescriptionMigrationTest extends Decker_Test_Base {
	private $editor;
	private $board;
	private $migration;

	public function set_up() {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
		$this->board = self::factory()->board->create();
		$this->migration = new Decker_Task_Description_Migration();
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Seed raw historical bytes after creating identity, without the task writer. */
	private function historical( string $html, string $status = 'publish' ): int {
		global $wpdb;
		update_option( Decker_Task_Description::VERSION_OPTION, 1, false );
		$id = self::factory()->post->create( array( 'post_type' => 'decker_task', 'post_title' => 'Anonymous historical task', 'post_author' => $this->editor, 'post_status' => $status, 'post_content' => '' ) );
		wp_set_object_terms( $id, array( $this->board ), 'decker_board' );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $html ), array( 'ID' => $id ), array( '%s' ), array( '%d' ) );
		clean_post_cache( $id );
		delete_option( Decker_Task_Description::VERSION_OPTION );
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertSame( hash( 'sha256', $html ), hash( 'sha256', get_post( $id )->post_content ) );
		return $id;
	}

	/** Ignore only fields explicitly changed by a content migration. */
	private function unrelated( int $id ): array {
		$core = get_post( $id )->to_array();
		unset( $core['post_content'], $core['post_modified'], $core['post_modified_gmt'] );
		return array( $core, get_post_meta( $id ), wp_get_object_terms( $id, array( 'decker_board', 'decker_label' ), array( 'fields' => 'tt_ids' ) ) );
	}

	public function test_backups_cover_all_statuses_classic_and_empty_across_batches() {
		$ids = array();
		$legacy = '<ol><li data-list="checked"><span class="ql-ui"></span><br></li><li data-list="unchecked">Pending</li></ol>';
		foreach ( array( 'publish', 'archived', 'private', 'draft', 'pending', 'trash', 'auto-draft', 'future' ) as $status ) {
			$id = $this->historical( $legacy, $status );
			update_post_meta( $id, 'hidden', '1' );
			$ids[ $id ] = array( $legacy, $this->unrelated( $id ) );
		}
		foreach ( array( '', '<p><br></p>', '<ol start="5"><li>Classic</li></ol>' ) as $html ) {
			$id = $this->historical( $html );
			$ids[ $id ] = array( $html, $this->unrelated( $id ) );
		}
		$report = $this->migration->run( false, 2 );
		$this->assertSame( array(), $report['errors'] );
		foreach ( $ids as $id => list( $html, $unrelated ) ) {
			$this->assertSame( $html, Decker_Task_Description_Backup::read( $id )['content'] );
			$this->assertSame( $unrelated, $this->unrelated( $id ) );
			$this->assertSame( Decker_Description_Snapshot::items( $html ), Decker_Description_Snapshot::items( get_post( $id )->post_content ) );
		}
		$this->assertSame( array(), $this->migration->verify()['pending'] );
		$this->assertSame( 1, (int) get_option( Decker_Task_Description::VERSION_OPTION ) );
	}

	public function test_missing_meta_subtasks_attachments_comments_and_domain_events_are_untouched() {
		$html = file_get_contents( dirname( __DIR__ ) . '/fixtures/task-descriptions/task-6976.html' );
		$id = $this->historical( $html );
		delete_post_meta( $id, 'hidden' );
		delete_post_meta( $id, 'responsable' );
		update_post_meta( $id, '_decker_subtasks', array( array( 'id' => 'step-one', 'text' => 'Independent subtask', 'done' => true ) ) );
		update_post_meta( $id, 'assigned_users', array( $this->editor ) );
		update_post_meta( $id, 'stack', 'done' );
		update_post_meta( $id, 'max_priority', '1' );
		self::factory()->comment->create( array( 'comment_post_ID' => $id ) );
		$attachment = self::factory()->post->create( array( 'post_type' => 'attachment', 'post_parent' => $id ) );
		$before = $this->unrelated( $id );
		$comments = get_comments( array( 'post_id' => $id, 'fields' => 'ids' ) );
		$events = array();
		$listener = static function () use ( &$events ) { $events[] = current_filter(); };
		$hooks = array( 'decker_task_created', 'decker_user_assigned', 'decker_task_completed', 'decker_task_responsable_changed', 'decker_task_updated' );
		foreach ( $hooks as $hook ) { add_action( $hook, $listener ); }
		try {
			$this->assertSame( array(), $this->migration->run( false )['errors'] );
		} finally {
			foreach ( $hooks as $hook ) { remove_action( $hook, $listener ); }
		}
		$this->assertSame( array(), $events );
		$this->assertSame( $before, $this->unrelated( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, 'hidden' ) );
		$this->assertFalse( metadata_exists( 'post', $id, 'responsable' ) );
		$this->assertSame( $id, (int) get_post( $attachment )->post_parent );
		$this->assertSame( $comments, get_comments( array( 'post_id' => $id, 'fields' => 'ids' ) ) );
	}

	public function test_dry_run_writes_nothing_and_does_not_complete_the_site() {
		global $wpdb;
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$before = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A );
		$options = $wpdb->get_results( "SELECT * FROM {$wpdb->options} ORDER BY option_id", ARRAY_A );
		$meta = get_post_meta( $id );
		$this->assertSame( array(), $this->migration->run( true, 1 )['errors'] );
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) );
		$this->assertSame( $options, $wpdb->get_results( "SELECT * FROM {$wpdb->options} ORDER BY option_id", ARRAY_A ) );
		$this->assertSame( $meta, get_post_meta( $id ) );
	}

	public function test_second_run_is_immutable_and_rollback_restores_exact_bytes() {
		$html = '<ol><li data-list="checked">C:\\path &amp; 😀</li><li data-list="unchecked"><br></li></ol>';
		$id = $this->historical( $html );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$backup = Decker_Task_Description_Backup::read( $id );
		$post = get_post( $id )->to_array();
		$revisions = wp_get_post_revisions( $id );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$this->assertSame( $backup, Decker_Task_Description_Backup::read( $id ) );
		$this->assertSame( $post, get_post( $id )->to_array() );
		$this->assertEquals( $revisions, wp_get_post_revisions( $id ) );
		$this->assertTrue( $this->migration->restore( $id ) );
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertSame( $backup, Decker_Task_Description_Backup::read( $id ) );
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
	}

	public function test_rollback_refuses_a_title_only_edit() {
		$id = $this->historical( '<ol><li data-list="checked">Text</li></ol>' );
		$this->migration->run( false );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Later title' ) );
		$this->expectException( RuntimeException::class );
		$this->migration->restore( $id );
	}

	public function test_invalid_backup_or_unknown_structure_never_changes_content() {
		$html = '<ol><li data-list="checked">Content</li></ol>';
		$id = $this->historical( $html );
		add_option( Decker_Task_Description_Backup::key( $id ), array( 'invalid' => true ), '', false );
		$other = $this->historical( '<p class="ql-unknown">Unknown structure</p>' );
		$original = get_post( $other )->post_content;
		$this->assertCount( 2, $this->migration->run( false )['errors'] );
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertSame( $original, get_post( $other )->post_content );
		$this->assertSame( $original, Decker_Task_Description_Backup::read( $other )['content'] );
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
	}

	public function test_resume_after_backup_and_after_content_commit() {
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$interrupt = static function () { throw new RuntimeException( 'Simulated interruption after backup.' ); };
		add_action( 'add_option_' . Decker_Task_Description_Backup::key( $id ), $interrupt );
		$this->assertCount( 1, $this->migration->run( false )['errors'] );
		remove_action( 'add_option_' . Decker_Task_Description_Backup::key( $id ), $interrupt );
		$backup = Decker_Task_Description_Backup::read( $id );
		$interrupt_write = static function ( $post_id ) use ( $id ) {
			if ( $post_id === $id ) { throw new RuntimeException( 'Simulated interruption after content commit.' ); }
		};
		add_action( 'post_updated', $interrupt_write );
		$this->assertCount( 1, $this->migration->run( false )['errors'] );
		remove_action( 'post_updated', $interrupt_write );
		$this->assertSame( 'prepared', get_option( Decker_Task_Description_Migration::state_key( $id ) )['phase'] );
		$this->assertStringStartsWith( '<ul>', get_post( $id )->post_content );
		$modified = get_post( $id )->post_modified_gmt;
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$this->assertSame( $modified, get_post( $id )->post_modified_gmt );
		$this->assertSame( $backup, Decker_Task_Description_Backup::read( $id ) );
	}

	public function test_revisions_enabled_disabled_dates_and_generation() {
		$id = $this->historical( '<ol><li data-list="checked">Old</li></ol>' );
		$locks = new Decker_Task_Locks();
		$lock = $locks->acquire_lock( $id, $this->editor );
		$before_lock = ( new Decker_Task_Lock_State() )->read( $id );
		$before_date = get_post( $id )->post_modified_gmt;
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		try { $this->assertSame( array(), $this->migration->run( false )['errors'] ); }
		finally { remove_filter( 'wp_revisions_to_keep', '__return_zero' ); }
		$after_lock = ( new Decker_Task_Lock_State() )->read( $id );
		$this->assertNotSame( $before_lock['token'], $after_lock['token'] );
		$this->assertSame( $before_lock['user'], $after_lock['user'] );
		$this->assertSame( $before_lock['time'], $after_lock['time'] );
		$this->assertGreaterThan( $before_date, get_post( $id )->post_modified_gmt );
		$this->assertSame( array(), wp_get_post_revisions( $id ) );
		$this->assertWPError( $locks->assert_user_can_save( $id, $this->editor, $before_lock['token'], true ) );
	}

	public function test_native_revision_restore_normalizes_parent_and_keeps_source_revision() {
		$html = '<ol><li data-list="checked">Historical</li></ol>';
		$id = $this->historical( $html );
		$revision = wp_save_post_revision( $id );
		$this->assertIsInt( $revision );
		$this->migration->run( false );
		$this->assertSame( $id, wp_restore_post_revision( $revision ) );
		$this->assertSame( '<ul><li data-list="checked">Historical</li></ul>', get_post( $id )->post_content );
		$this->assertSame( $html, get_post( $revision )->post_content );
	}

	public function test_backup_and_content_write_failures_leave_original_recoverable() {
		global $wpdb;
		$html = '<ol><li data-list="checked">Original</li></ol>';
		$id = $this->historical( $html );
		$key = Decker_Task_Description_Backup::key( $id );
		$backup_failure = static function ( $query ) use ( $key ) {
			return str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, $key ) ? 'SELECT nonexistent_description_backup_column' : $query;
		};
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $backup_failure );
		try { $report = $this->migration->run( false ); }
		finally { remove_filter( 'query', $backup_failure ); $wpdb->suppress_errors( $old ); }
		$this->assertNotEmpty( $report['errors'] );
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertFalse( get_option( $key, false ) );
		$write_failure = static function ( $query ) use ( $wpdb, $id ) {
			return str_starts_with( $query, 'UPDATE `' . $wpdb->posts . '`' ) && ( str_contains( $query, "`ID` = '$id'" ) || str_contains( $query, "`ID` = $id" ) ) ? 'SELECT nonexistent_description_write_column' : $query;
		};
		$old = $wpdb->suppress_errors( true );
		add_filter( 'query', $write_failure );
		try { $report = $this->migration->run( false ); }
		finally { remove_filter( 'query', $write_failure ); $wpdb->suppress_errors( $old ); }
		$this->assertNotEmpty( $report['errors'] );
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertSame( $html, Decker_Task_Description_Backup::read( $id )['content'] );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
	}

	public function test_pending_invalidation_is_resumed_without_rewriting_content() {
		$id = $this->historical( '<ol><li data-list="checked">Text</li></ol>' );
		( new Decker_Task_Locks() )->acquire_lock( $id, $this->editor );
		$failure = static function ( $check, $post_id, $key ) use ( $id ) {
			return $id === $post_id && Decker_Task_Locks::STATE_META === $key ? false : $check;
		};
		add_filter( 'update_post_metadata', $failure, 10, 3 );
		try { $report = $this->migration->run( false ); }
		finally { remove_filter( 'update_post_metadata', $failure ); }
		$this->assertNotEmpty( $report['errors'] );
		$this->assertSame( 'written', get_option( Decker_Task_Description_Migration::state_key( $id ) )['phase'] );
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
		$content = get_post( $id )->post_content;
		$date = get_post( $id )->post_modified_gmt;
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$this->assertSame( $content, get_post( $id )->post_content );
		$this->assertSame( $date, get_post( $id )->post_modified_gmt );
	}

	public function test_original_revisions_are_not_pruned_by_migration() {
		global $wpdb;
		$id = $this->historical( 'Initial revision' );
		update_option( Decker_Task_Description::VERSION_OPTION, 1 );
		foreach ( array( 'Second', 'Third', 'Fourth' ) as $text ) wp_update_post( array( 'ID' => $id, 'post_content' => $text ), true );
		$revisions = wp_get_post_revisions( $id );
		$this->assertGreaterThan( 2, count( $revisions ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<ol><li data-list="checked">Legacy</li></ol>' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$limit = static function () { return 1; };
		add_filter( 'wp_revisions_to_keep', $limit );
		try { $this->assertSame( array(), $this->migration->run( false )['errors'] ); }
		finally { remove_filter( 'wp_revisions_to_keep', $limit ); }
		foreach ( $revisions as $revision_id => $revision ) {
			$this->assertSame( $revision->post_content, get_post( $revision_id )->post_content );
		}
	}

	public function test_restore_resumes_after_commit_without_duplicate_writes() {
		$html = '<ol><li data-list="checked">Original</li></ol>';
		$id = $this->historical( $html );
		$this->migration->run( false );
		$interrupt = static function ( $post_id ) use ( $id ) { if ( $post_id === $id ) throw new RuntimeException( 'Interrupted restoration after commit.' ); };
		add_action( 'post_updated', $interrupt );
		try {
			try { $this->migration->restore( $id ); $this->fail( 'Expected interruption.' ); }
			catch ( RuntimeException $error ) { $this->assertStringContainsString( 'Interrupted restoration', $error->getMessage() ); }
		} finally { remove_action( 'post_updated', $interrupt ); }
		$this->assertSame( $html, get_post( $id )->post_content );
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
		$this->assertSame( 'restoring', get_option( Decker_Task_Description_Migration::state_key( $id ) )['phase'] );
		$modified = get_post( $id )->post_modified_gmt;
		$revisions = wp_get_post_revisions( $id );
		$this->assertTrue( $this->migration->restore( $id ) );
		$this->assertTrue( $this->migration->restore( $id ) );
		$this->assertSame( $modified, get_post( $id )->post_modified_gmt );
		$this->assertEquals( $revisions, wp_get_post_revisions( $id ) );
		$this->assertSame( $html, Decker_Task_Description_Backup::read( $id )['content'] );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
	}

	public function test_resume_restore_refuses_an_intervening_title_edit() {
		global $wpdb;
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$this->migration->run( false );
		$interrupt = static function () { throw new RuntimeException( 'Interrupted restore.' ); };
		add_action( 'post_updated', $interrupt );
		try { $this->migration->restore( $id ); }
		catch ( RuntimeException $error ) { $this->assertSame( 'Interrupted restore.', $error->getMessage() ); }
		finally { remove_action( 'post_updated', $interrupt ); }
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Manual later edit' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->expectException( RuntimeException::class );
		$this->migration->restore( $id );
	}

	public function test_css_comparison_preserves_whitespace_inside_values() {
		$compare = new ReflectionMethod( Decker_Task_Description_Migration::class, 'same_safe_html' );
		$a = '<p style="font-family:&quot;Semi; Colon&quot;; color:red;">Text</p>';
		$b = '<p style="font-family:&quot;Semi; Colon&quot;;color:red">Text</p>';
		$this->assertTrue( $compare->invoke( null, $a, $b ) );
		$this->assertFalse( $compare->invoke( null, $a, str_replace( 'Semi; Colon', 'Semi;Colon', $b ) ) );
		$this->assertFalse( $compare->invoke( null, $a, '<p style="color:red">Text</p>' ) );
	}

	public function test_missing_slug_and_guid_do_not_gain_wordpress_defaults() {
		global $wpdb;
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>', 'archived' );
		$wpdb->update( $wpdb->posts, array( 'post_name' => '', 'guid' => '', 'post_author' => 0, 'menu_order' => -5 ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$before = $this->unrelated( $id );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$this->assertSame( $before, $this->unrelated( $id ) );
	}

	public function test_kses_separator_and_url_whitespace_is_safe_but_lost_css_is_not() {
		$html = '<p style="text-align: center; padding-left: 3em;">Text</p><a href="https://example.invalid/?a=1&amp;b=2 ">Link</a>';
		$id = $this->historical( $html );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$this->assertSame( $html, Decker_Task_Description_Backup::read( $id )['content'] );
		$link = Decker_Description_Snapshot::document( get_post( $id )->post_content )->getElementsByTagName( 'a' )->item( 0 );
		$this->assertSame( 'https://example.invalid/?a=1&b=2', trim( $link->getAttribute( 'href' ) ) );
		$unsafe = '<p style="color:red;background-image:url(javascript:alert(1))">Unsafe historical CSS</p>';
		$other = $this->historical( $unsafe );
		$this->assertNotEmpty( $this->migration->run( false )['errors'] );
		$this->assertSame( $unsafe, get_post( $other )->post_content );
		$this->assertSame( $unsafe, Decker_Task_Description_Backup::read( $other )['content'] );
		$this->assertContains( $other, $this->migration->verify()['pending'] );
	}

	public function test_a_write_during_backup_cannot_be_overwritten() {
		global $wpdb;
		$html = '<ol><li data-list="checked">Original</li></ol>';
		$id = $this->historical( $html );
		$change = static function () use ( $wpdb, $id ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Concurrent edit</p>', 'post_title' => 'Concurrent title' ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		};
		add_action( 'add_option_' . Decker_Task_Description_Backup::key( $id ), $change );
		try { $report = $this->migration->run( false ); }
		finally { remove_action( 'add_option_' . Decker_Task_Description_Backup::key( $id ), $change ); }
		$this->assertNotEmpty( $report['errors'] );
		$this->assertSame( '<p>Concurrent edit</p>', get_post( $id )->post_content );
		$this->assertSame( 'Concurrent title', get_post( $id )->post_title );
		$this->assertSame( $html, Decker_Task_Description_Backup::read( $id )['content'] );
		$this->assertContains( $id, $this->migration->verify()['pending'] );
	}

	public function test_a_failed_completion_flag_never_reports_success() {
		$this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$failure = static function ( $value, $old ) { return $old; };
		add_filter( 'pre_update_option_' . Decker_Task_Description::VERSION_OPTION, $failure, 10, 2 );
		try {
			try { $this->migration->run( false ); $this->fail( 'Completion storage failure must be visible.' ); }
			catch ( RuntimeException $error ) { $this->assertStringContainsString( 'completion version', $error->getMessage() ); }
		} finally { remove_filter( 'pre_update_option_' . Decker_Task_Description::VERSION_OPTION, $failure ); }
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
	}

	public function test_changed_migration_invalidates_ability_expected_modified() {
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$service = new Decker_Ability_Service();
		$before = $service->get_task( array( 'task_id' => $id ) );
		$this->assertNotWPError( $before );
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
		$result = $service->update_task( array( 'task_id' => $id, 'title' => 'Stale ability title', 'expected_modified' => $before['modified'] ) );
		$this->assertWPError( $result );
		$this->assertSame( 'decker_task_stale', $result->get_error_code() );
	}

	public function test_completed_journal_cannot_hide_reintroduced_legacy_content() {
		global $wpdb;
		$id = $this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$this->migration->run( false );
		$backup = Decker_Task_Description_Backup::read( $id );
		$legacy = '<ol><li data-list="unchecked">Later legacy edit</li></ol>';
		$wpdb->update( $wpdb->posts, array( 'post_content' => $legacy ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertNotEmpty( $this->migration->run( false )['errors'] );
		$this->assertContains( $id, $this->migration->verify()['pending'] );
		$this->assertSame( $legacy, get_post( $id )->post_content );
		$this->assertSame( $backup, Decker_Task_Description_Backup::read( $id ) );
		$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
	}

	public function test_another_database_connection_excludes_a_second_executor() {
		$this->historical( '<ol><li data-list="checked">Original</li></ol>' );
		$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$method = new ReflectionMethod( Decker_Task_Description_Migration::class, 'lock_name' );
		$key = $method->invoke( null );
		$this->assertSame( '1', (string) $connection->get_var( $connection->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) );
		try {
			try { $this->migration->run( false ); $this->fail( 'A second executor must be refused.' ); }
			catch ( RuntimeException $error ) { $this->assertStringContainsString( 'Another description migration', $error->getMessage() ); }
		} finally {
			$connection->get_var( $connection->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			$connection->close();
		}
		$this->assertSame( array(), $this->migration->run( false )['errors'] );
	}

	public function test_multisite_backups_and_versions_are_site_local() {
		if ( ! is_multisite() ) $this->markTestSkipped( 'Run the WordPress multisite test configuration.' );
		$id = $this->historical( '<ol><li data-list="checked">First site</li></ol>' );
		$this->migration->run( false );
		$original = Decker_Task_Description_Backup::read( $id );
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		try {
			$this->assertFalse( get_option( Decker_Task_Description_Backup::key( $id ), false ) );
			$this->assertFalse( get_option( Decker_Task_Description::VERSION_OPTION, false ) );
			$other = $this->historical( '<ol><li data-list="unchecked">Second site</li></ol>' );
			$this->assertSame( array(), $this->migration->run( false )['errors'] );
			$this->assertSame( $site, Decker_Task_Description_Backup::read( $other )['site_id'] );
		} finally { restore_current_blog(); }
		$this->assertSame( $original, Decker_Task_Description_Backup::read( $id ) );
		$this->assertSame( 1, (int) get_option( Decker_Task_Description::VERSION_OPTION ) );
	}
}
