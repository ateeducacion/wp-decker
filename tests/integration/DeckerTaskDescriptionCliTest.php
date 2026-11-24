<?php
/** Description CLI adapters with real conversion and database persistence. @package Decker */

class DeckerTaskDescriptionCliTest extends Decker_Test_Base {
	private $result;
	private $board;
	private $runner_config;

	public function set_up() {
		parent::set_up();
		// Enable command registration without putting WordPress into CLI mode.
		if ( ! defined( 'WP_CLI' ) ) define( 'WP_CLI', false );
		if ( ! defined( 'WP_CLI_ROOT' ) ) define( 'WP_CLI_ROOT', dirname( __DIR__, 2 ) . '/vendor/wp-cli/wp-cli' );
		require_once WP_CLI_ROOT . '/php/utils.php';
		require_once WP_CLI_ROOT . '/php/dispatcher.php';
		// Supply CLI defaults without reading an operator's YAML/configuration.
		$config = new ReflectionProperty( WP_CLI\Runner::class, 'config' );
		$this->runner_config = $config->getValue( WP_CLI::get_runner() );
		$config->setValue( WP_CLI::get_runner(), WP_CLI::get_configurator()->to_array()[0] );
		require_once dirname( __DIR__, 2 ) . '/includes/class-decker-wpcli.php';
		if ( ! isset( WP_CLI::get_root_command()->get_subcommands()['decker'] ) ) WP_CLI::add_command( 'decker', 'Decker_WPCLI' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->board = self::factory()->board->create();
	}

	public function tear_down() {
		( new ReflectionProperty( WP_CLI\Runner::class, 'config' ) )->setValue( WP_CLI::get_runner(), $this->runner_config );
		parent::tear_down();
	}

	public function test_apply_requires_the_controlled_window_acknowledgement() {
		$id = $this->historical();
		$this->exit_from( fn () => $this->invoke( 'migrate-descriptions' ), 'Pause all task writers' );
		$this->assertFalse( get_option( Decker_Task_Description_Backup::key( $id ), false ) );
	}

	public function test_apply_and_restore_require_normal_plugin_hooks() {
		$id = $this->historical();
		remove_filter( 'wp_insert_post_data', array( 'Decker_Task_Description', 'filter_post' ), 20 );
		$this->exit_from( fn () => $this->invoke( 'migrate-descriptions --writers-paused' ), 'load Decker normally' );
		$this->exit_from( fn () => $this->invoke( 'restore-descriptions --writers-paused --task-id=' . $id ), 'Load Decker normally' );
		$this->assertFalse( get_option( Decker_Task_Description_Backup::key( $id ), false ) );
	}

	public function test_dry_run_rejects_the_normal_site_bootstrap() {
		$this->exit_from( fn () => $this->invoke( 'migrate-descriptions --dry-run' ), 'isolated dry run' );
	}

	public function test_isolated_dry_run_reports_without_creating_backups_or_state() {
		$id = $this->historical();
		$before = get_post( $id )->to_array();
		$version = get_option( Decker_Task_Description::VERSION_OPTION );
		remove_filter( 'wp_insert_post_data', array( 'Decker_Task_Description', 'filter_post' ), 20 );
		$this->invoke( 'migrate-descriptions --dry-run --batch-size=1' );
		$this->assertSame( 1, $this->report()['converted'] );
		$this->assertSame( array(), $this->report()['errors'] );
		$this->assertStringContainsString( 'Site ', $this->result->stdout );
		$this->assertSame( $before, get_post( $id )->to_array() );
		$this->assertSame( $version, get_option( Decker_Task_Description::VERSION_OPTION ) );
		$this->assertFalse( get_option( Decker_Task_Description_Backup::key( $id ), false ) );
		$this->assertFalse( get_option( Decker_Task_Description_Migration::state_key( $id ), false ) );
	}

	public function test_apply_verify_and_restore_use_the_real_services() {
		$id = $this->historical();
		$original = get_post( $id )->post_content;
		$this->invoke( 'migrate-descriptions --writers-paused --batch-size=1' );
		$this->assertSame( 1, $this->report()['converted'] );
		$this->assertSame( array(), $this->report()['errors'] );
		$this->assertSame( $original, Decker_Task_Description_Backup::read( $id )['content'] );
		$this->assertStringContainsString( '<ul>', get_post( $id )->post_content );
		$this->invoke( 'verify-descriptions' );
		$this->assertSame( array( 'total' => 1, 'pending' => array() ), $this->report() );
		$this->invoke( 'restore-descriptions --writers-paused --task-id=' . $id );
		$this->assertSame( $original, get_post( $id )->post_content );
		$this->assertFalse( Decker_Task_Description::is_ready() );
		$this->assertStringContainsString( 'Original description restored.', $this->result->stdout );
	}

	public function test_conversion_error_emits_json_and_a_failing_exit_status() {
		$id = $this->historical( '<p class="ql-unknown">Review me</p>' );
		$this->exit_from( fn () => $this->invoke( 'migrate-descriptions --writers-paused' ), 'halt' );
		$this->assertSame( $id, $this->report()['errors'][0]['task_id'] );
		$this->assertSame( '<p class="ql-unknown">Review me</p>', get_post( $id )->post_content );
		$this->assertSame( get_post( $id )->post_content, Decker_Task_Description_Backup::read( $id )['content'] );
		$this->assertFalse( Decker_Task_Description::is_ready() );
	}

	public function test_bad_batch_size_becomes_a_cli_error_before_any_backup() {
		$id = $this->historical();
		$this->exit_from( fn () => $this->invoke( 'migrate-descriptions --writers-paused --batch-size=0' ), 'Batch size must be' );
		$this->assertFalse( get_option( Decker_Task_Description_Backup::key( $id ), false ) );
	}

	public function test_verify_returns_pending_ids_and_fails_for_incomplete_coverage() {
		$id = $this->historical();
		$this->exit_from( fn () => $this->invoke( 'verify-descriptions' ), 'halt' );
		$this->assertSame( array( $id ), $this->report()['pending'] );
	}

	public function test_restore_requires_acknowledgement_and_a_verified_backup() {
		$id = $this->historical();
		$this->exit_from( fn () => $this->invoke( 'restore-descriptions --task-id=' . $id ), 'pause all task writers' );
		$this->exit_from( fn () => $this->invoke( 'restore-descriptions --writers-paused --task-id=' . $id ), 'Missing or invalid description backup' );
	}

	public function test_restore_reports_a_later_edit_instead_of_overwriting_it() {
		$id = $this->historical();
		$this->invoke( 'migrate-descriptions --writers-paused' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Later edit' ) );
		$before = get_post( $id )->to_array();
		$this->exit_from( fn () => $this->invoke( 'restore-descriptions --writers-paused --task-id=' . $id ), 'edited after migration' );
		$this->assertSame( $before, get_post( $id )->to_array() );
	}

	private function historical( string $html = '<ol><li data-list="checked">Done</li></ol>' ): int {
		global $wpdb;
		$id = self::factory()->task->create( array( 'board' => $this->board ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $html ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertSame( $html, get_post( $id )->post_content );
		return $id;
	}

	private function invoke( string $command ): object {
		$this->result = WP_CLI::runcommand( 'decker ' . $command, array( 'launch' => false, 'exit_error' => false, 'return' => 'all' ) );
		return $this->result;
	}

	private function report(): array {
		return json_decode( substr( $this->result->stdout, strpos( $this->result->stdout, '{' ) ), true, 512, JSON_THROW_ON_ERROR );
	}

	private function exit_from( callable $command, string $message ): void {
		$result = $command();
		$this->assertSame( 1, $result->return_code );
		if ( 'halt' === $message ) {
			$this->assertNotEmpty( $result->stdout );
		} else {
			$this->assertStringContainsString( $message, $result->stderr );
		}
	}
}
