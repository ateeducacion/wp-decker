<?php
/**
 * WP-CLI commands for the Decker plugin.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Custom WP-CLI commands for Decker Plugin.
	 */
	class Decker_WPCLI extends WP_CLI_Command {

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

	// Registrar el comando principal que agrupa los subcomandos.
	WP_CLI::add_command( 'decker', 'Decker_WPCLI' );
}
