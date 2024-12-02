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
		 *     wp decker create_sample_data.
		 */
		public function create_sample_data() {
			// Temporarily elevate permissions.
			$current_user = wp_get_current_user();
			$old_user = $current_user;
			wp_set_current_user( 1 ); // Switch to admin user (ID 1).

			WP_CLI::log( 'Starting sample data creation...' );

			// 1. Create labels.
			WP_CLI::log( 'Creating labels...' );
			$labels = array();
			for ( $i = 1; $i <= 10; $i++ ) {
				$term_name = "Label $i";
				$term_slug = sanitize_title( $term_name );
				$term_color = $this->generate_random_color();

				// Verificar si la etiqueta ya existe.
				$existing_term = term_exists( $term_slug, 'decker_label' );
				if ( $existing_term ) {
					WP_CLI::warning( "Label '$term_name' already exists. Skipping..." );
					$labels[] = $existing_term['term_id'];
					continue;
				}

				$term = wp_insert_term(
					$term_name,
					'decker_label',
					array(
						'slug' => $term_slug,
					)
				);

				if ( is_wp_error( $term ) ) {
					WP_CLI::warning( "Error creating label '$term_name': " . $term->get_error_message() );
					continue;
				}

				// Añadir meta 'term-color'.
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				WP_CLI::success( "Label '$term_name' created with color $term_color." );
				$labels[] = $term['term_id'];
			}

			// 2. Create boards.
			WP_CLI::log( 'Creating boards...' );
			$boards = array();
			for ( $i = 1; $i <= 5; $i++ ) {
				$term_name = "Board $i";
				$term_slug = sanitize_title( $term_name );
				$term_color = $this->generate_random_color();

				// Verificar si el tablero ya existe.
				$existing_term = term_exists( $term_slug, 'decker_board' );
				if ( $existing_term ) {
					WP_CLI::warning( "Board '$term_name' already exists. Skipping..." );
					$boards[] = $existing_term['term_id'];
					continue;
				}

				$term = wp_insert_term(
					$term_name,
					'decker_board',
					array(
						'slug' => $term_slug,
					)
				);

				if ( is_wp_error( $term ) ) {
					WP_CLI::warning( "Error creating board '$term_name': " . $term->get_error_message() );
					continue;
				}

				// Añadir meta 'term-color'.
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				WP_CLI::success( "Board '$term_name' created with color $term_color." );
				$boards[] = $term['term_id'];
			}

			// 3. Get all users.
			WP_CLI::log( 'Getting users...' );
			$users = get_users( array( 'fields' => array( 'ID' ) ) );
			if ( empty( $users ) ) {
				WP_CLI::error( 'No users available to assign to tasks.' );
				return;
			}
			$user_ids = wp_list_pluck( $users, 'ID' );

			// 4. Create tasks.
			WP_CLI::log( 'Creating tasks...' );
			foreach ( $boards as $board_id ) {
				$board = get_term( $board_id, 'decker_board' );
				if ( is_wp_error( $board ) ) {
					WP_CLI::warning( "Could not get board with ID $board_id. Skipping..." );
					continue;
				}

				for ( $j = 1; $j <= 10; $j++ ) {
					$post_title = "Task $j for {$board->name}";
					$post_content = "Content for task $j in board {$board->name}.";

					// Asignar etiquetas aleatorias (0 a 3 etiquetas).
					$num_labels = wp_rand( 0, 3 );
					if ( $num_labels > 0 && ! empty( $labels ) ) {
						$assigned_labels = $this->wp_rand_elements( $labels, $num_labels );
					} else {
						$assigned_labels = array();
					}

					// Asignar usuarios aleatorios (1 a 3 usuarios).
					$num_users = wp_rand( 1, 3 );
					$assigned_users = $this->wp_rand_elements( $user_ids, $num_users );

					 // Generar campos adicionales.
					$max_priority = $this->random_boolean( 0.2 ); // 20% de probabilidad de ser true.
					$archived = $this->random_boolean( 0.2 );      // 20% de probabilidad de ser true.
					$creation_date = $this->random_date( '-2 months', 'now' );
					$due_date = $this->random_date( $creation_date->format( 'Y-m-d' ), '+3 months' );
					$stack = $this->random_stack();

					$task_id = Decker_Tasks::create_or_update_task(
						0, // Create new task.
						$post_title,
						$post_content,
						$stack,
						$board_id,
						$max_priority,
						$due_date,
						1, // ID of admin user.
						$assigned_users,
						$assigned_labels,
						$creation_date,
						$archived,
						0 // ID of next_cloud.
					);

					if ( is_wp_error( $task_id ) ) {
						WP_CLI::warning( "Error creating task '$post_title': " . $task_id->get_error_message() );
						continue;
					}

					WP_CLI::success( "Task '$post_title' created and assigned to board '{$board->name}'." );
				}
			}

			WP_CLI::success( 'Sample data created successfully!' );

			// Restore original user.
			wp_set_current_user( $old_user->ID );
		}

		/**
		 * Generates a random hexadecimal color.
		 *
		 * @return string Color in hexadecimal format (e.g., #a3f4c1).
		 */
		private function generate_random_color() {
			return sprintf( '#%06X', wp_rand( 0, 0xFFFFFF ) );
		}

		/**
		 * Selects random elements from an array.
		 *
		 * @param array $array Array to select elements from.
		 * @param int   $number Number of elements to select.
		 * @return array Selected elements.
		 */
		private function wp_rand_elements( $array, $number ) {
			if ( $number >= count( $array ) ) {
				return $array;
			}
			$keys = array_rand( $array, $number );
			if ( 1 == $number ) {
				return array( $array[ $keys ] );
			}
			$selected = array();
			foreach ( $keys as $key ) {
				$selected[] = $array[ $key ];
			}
			return $selected;
		}


		/**
		 * Genera un valor booleano aleatorio basado en una probabilidad.
		 *
		 * @param float $true_probability Probabilidad de que retorne true (entre 0 y 1).
		 * @return bool
		 */
		private function random_boolean( $true_probability = 0.5 ) {
			return ( wp_rand() / mt_getrandmax() ) < $true_probability;
		}

		/**
		 * Genera una fecha aleatoria entre dos fechas dadas.
		 *
		 * @param string $start Fecha de inicio (formato reconocido por strtotime).
		 * @param string $end Fecha de fin (formato reconocido por strtotime).
		 * @return DateTime Fecha generada aleatoriamente.
		 */
		private function random_date( $start, $end ) {
			$min = strtotime( $start );
			$max = strtotime( $end );
			$timestamp = wp_rand( $min, $max );
			return ( new DateTime() )->setTimestamp( $timestamp );
		}

		/**
		 * Selecciona un stack aleatorio.
		 *
		 * @return string Uno de los valores: 'to-do', 'in-progress', 'done'.
		 */
		private function random_stack() {
			$stacks = array( 'to-do', 'in-progress', 'done' );
			return $stacks[ array_rand( $stacks ) ];
		}
	}

	// Registrar el comando principal que agrupa los subcomandos.
	WP_CLI::add_command( 'decker', 'Decker_WPCLI' );
}
