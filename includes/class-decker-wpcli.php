<?php
/**
 * WP-CLI commands for the Decker plugin.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

// --require runs before WordPress; defer definitions until its bootstrap finishes.
if ( ! defined( 'ABSPATH' ) && defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_hook(
		'after_wp_load',
		static function () {
			require __FILE__;
		}
	);
	return;
}
defined( 'ABSPATH' ) || exit;
if ( class_exists( 'WP_CLI_Command' ) && ! class_exists( 'Decker_WPCLI', false ) ) {

	/**
	 * Custom WP-CLI commands for Decker Plugin.
	 */
	class Decker_WPCLI extends WP_CLI_Command {

		/**
		 * Back up and convert every task description in this site.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Report only; use the isolated --skip-plugins/--require invocation.
		 * [--writers-paused]
		 * : Confirm that HTTP, cron, import and CLI writers have been paused.
		 * [--batch-size=<size>]
		 * : Maximum rows per batch (1-1000). Default: 100.
		 *
		 * @subcommand migrate-descriptions
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Options.
		 */
		public function migrate_descriptions( $args, $assoc_args ) {
			$dry_run = isset( $assoc_args['dry-run'] );
			$this->description_services();
			if ( ! $dry_run && ( ! isset( $assoc_args['writers-paused'] ) || ! has_filter( 'wp_insert_post_data', array( 'Decker_Task_Description', 'filter_post' ) ) ) ) {
				WP_CLI::error( 'Pause all task writers, load Decker normally and pass --writers-paused. See docs/task-description-migration.md.' );
			}
			if ( $dry_run && has_filter( 'wp_insert_post_data', array( 'Decker_Task_Description', 'filter_post' ) ) ) {
				WP_CLI::error( 'Use --skip-plugins --skip-themes --require=wp-content/plugins/decker/includes/class-decker-wpcli.php for an isolated dry run.' );
			}
			try {
				$progress = static function ( $processed, $total, $id ) {
					WP_CLI::log( sprintf( 'Site %d: %d/%d tasks inspected; last ID %d.', get_current_blog_id(), $processed, $total, $id ) );
				};
				$report = ( new Decker_Task_Description_Migration() )->run( $dry_run, (int) ( $assoc_args['batch-size'] ?? 100 ), $progress );
				WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				if ( $report['errors'] ) {
					WP_CLI::halt( 1 );
				}
			} catch ( RuntimeException $error ) {
				WP_CLI::error( $error->getMessage() );
			}
		}

		/**
		 * Verify immutable backups and migration coverage for this site.
		 *
		 * @subcommand verify-descriptions
		 */
		public function verify_descriptions() {
			$this->description_services();
			$result = ( new Decker_Task_Description_Migration() )->verify();
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			if ( $result['pending'] ) {
				WP_CLI::halt( 1 );
			}
		}

		/**
		 * Restore a task's exact original description if it has not been edited.
		 *
		 * ## OPTIONS
		 *
		 * --task-id=<id>
		 * : Task ID to restore.
		 * [--writers-paused]
		 * : Confirm that all task writers remain paused.
		 *
		 * @subcommand restore-descriptions
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Options.
		 */
		public function restore_descriptions( $args, $assoc_args ) {
			$this->description_services();
			if ( ! isset( $assoc_args['writers-paused'] ) || ! has_filter( 'wp_insert_post_data', array( 'Decker_Task_Description', 'filter_post' ) ) ) {
				WP_CLI::error( 'Load Decker normally and pause all task writers before restoration.' );
			}
			try {
				( new Decker_Task_Description_Migration() )->restore( (int) $assoc_args['task-id'] );
				WP_CLI::success( 'Original description restored. Keep writers paused until plugin/content versions are coordinated.' );
			} catch ( RuntimeException $error ) {
				WP_CLI::error( $error->getMessage() );
			}
		}

		/** Load only definitions in the isolated read-only CLI invocation. */
		private function description_services(): void {
			require_once __DIR__ . '/class-decker.php';
			require_once __DIR__ . '/class-decker-task-locks.php';
			require_once __DIR__ . '/class-decker-task-description-converter.php';
			require_once __DIR__ . '/class-decker-task-description.php';
			require_once __DIR__ . '/class-decker-task-description-backup.php';
			require_once __DIR__ . '/class-decker-task-description-migration.php';
		}

		/**
		 * Say hello.
		 *
		 * ## OPTIONS
		 *
		 * [--name=<name>]
		 * : The name to greet.
		 *
		 * ## EXAMPLES
		 *
		 *     wp decker greet --name=Freddy
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function greet( $args, $assoc_args ) {
			$name = $assoc_args['name'] ?? 'World';
			WP_CLI::success( "Hello, $name!" );
		}

		/**
		 * Create sample data for Decker Plugin.
		 *
		 * This command creates 10 labels, 5 boards and 10 tasks per board.
		 *
		 * ## EXAMPLES
		 *
		 *     wp decker create_sample_data
		 */
		public function create_sample_data() {
			// Check if we're running on a development version.
			if ( defined( 'DECKER_VERSION' ) && DECKER_VERSION !== '0.0.0' ) {
				WP_CLI::warning( 'You are adding sample data to a non-development version of Decker (v' . DECKER_VERSION . ')' );
				WP_CLI::confirm( 'Do you want to continue?' );
			}

			WP_CLI::log( 'Starting sample data creation...' );

			$demo_data = new Decker_Demo_Data();
			$demo_data->create_sample_data();

			WP_CLI::success( 'Sample data created successfully!' );
		}
	}

	// Register the main command that groups the subcommands.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'decker', 'Decker_WPCLI' );
	}
}
