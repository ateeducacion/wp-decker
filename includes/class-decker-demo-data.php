<?php
/**
 * Demo data generator for the Decker plugin.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

/**
 * Class for generating demo data.
 */
class Decker_Demo_Data {

	/**
	 * Create sample data for Decker Plugin.
	 *
	 * This method creates 10 labels, 5 boards and 10 tasks per board.
	 */
	public function create_sample_data() {
		// Temporarily elevate permissions.
		$current_user = wp_get_current_user();
		$old_user = $current_user;
		wp_set_current_user( 1 ); // Switch to admin user (ID 1).

		$labels = $this->create_labels();
		$boards = $this->create_boards();
		$this->create_tasks( $boards, $labels );

		// Set up alert settings for demo data.
		$options = get_option( 'decker_settings', array() );
		$options['alert_color'] = 'danger';
		$options['alert_message'] = '<strong>' . __( 'Warning', 'decker' ) . ':</strong> ' . __( 'You are running this site with demo data.', 'decker' );
		update_option( 'decker_settings', $options );

		// Restore original user.
		wp_set_current_user( $old_user->ID );
	}

	/**
	 * Creates sample labels.
	 *
	 * @return array Array of label term IDs.
	 */
	private function create_labels() {
		$labels = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$term_name = "Label $i";
			$term_slug = sanitize_title( $term_name );
			$term_color = $this->generate_random_color();

			// Check if the label already exists.
			$existing_term = term_exists( $term_slug, 'decker_label' );
			if ( $existing_term ) {
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

			if ( ! is_wp_error( $term ) ) {
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				$labels[] = $term['term_id'];
			}
		}
		return $labels;
	}

	/**
	 * Creates sample boards.
	 *
	 * @return array Array of board term IDs.
	 */
	private function create_boards() {
		$boards = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$term_name = "Board $i";
			$term_slug = sanitize_title( $term_name );
			$term_color = $this->generate_random_color();

			// Check if the board already exists.
			$existing_term = term_exists( $term_slug, 'decker_board' );
			if ( $existing_term ) {
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

			if ( ! is_wp_error( $term ) ) {
				add_term_meta( $term['term_id'], 'term-color', $term_color, true );
				$boards[] = $term['term_id'];
			}
		}
		return $boards;
	}

	/**
	 * Creates sample tasks for each board.
	 *
	 * @param array $boards Array of board term IDs.
	 * @param array $labels Array of label term IDs.
	 */
	private function create_tasks( $boards, $labels ) {
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		if ( empty( $users ) ) {
			return;
		}
		$user_ids = wp_list_pluck( $users, 'ID' );

		foreach ( $boards as $board_id ) {
			$board = get_term( $board_id, 'decker_board' );
			if ( is_wp_error( $board ) ) {
				continue;
			}

			for ( $j = 1; $j <= 10; $j++ ) {
				$post_title = "Task $j for {$board->name}";
				$post_content = "Content for task $j in board {$board->name}.";

				// Assign random labels (0 to 3 labels).
				$num_labels = $this->custom_rand( 0, 3 );
				$assigned_labels = ( $num_labels > 0 && ! empty( $labels ) )
					? $this->wp_rand_elements( $labels, $num_labels )
					: array();

				// Assign random users (1 to 3 users).
				$num_users = $this->custom_rand( 1, 3 );
				$assigned_users = $this->wp_rand_elements( $user_ids, $num_users );

				// Generate additional fields.
				$max_priority = $this->random_boolean( 0.2 );
				$archived = $this->random_boolean( 0.2 );
				$creation_date = $this->random_date( '-2 months', 'now' );
				$due_date = $this->random_date( $creation_date->format( 'Y-m-d' ), '+3 months' );
				$stack = $this->random_stack();

				Decker_Tasks::create_or_update_task(
					0,
					$post_title,
					$post_content,
					$stack,
					$board_id,
					$max_priority,
					$due_date,
					1,
					$assigned_users,
					$assigned_labels,
					$creation_date,
					$archived,
					0
				);
			}
		}
	}

	/**
	 * Generates a random hexadecimal color.
	 *
	 * @return string Color in hexadecimal format (e.g., #a3f4c1).
	 */
	private function generate_random_color() {
		return sprintf( '#%06X', $this->custom_rand( 0, 0xFFFFFF ) );
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
	 * Generates a random boolean value based on a probability.
	 *
	 * @param float $true_probability Probability of returning true (between 0 and 1).
	 * @return bool
	 */
	private function random_boolean( $true_probability = 0.5 ) {
		return ( $this->custom_rand() / mt_getrandmax() ) < $true_probability;
	}

	/**
	 * Generates a random date between two given dates.
	 *
	 * @param string $start Start date (format recognized by strtotime).
	 * @param string $end End date (format recognized by strtotime).
	 * @return DateTime Randomly generated date.
	 */
	private function random_date( $start, $end ) {
		$min = strtotime( $start );
		$max = strtotime( $end );
		$timestamp = $this->custom_rand( $min, $max );
		return ( new DateTime() )->setTimestamp( $timestamp );
	}

	/**
	 * Selects a random stack.
	 *
	 * @return string One of these values: 'to-do', 'in-progress', 'done'.
	 */
	private function random_stack() {
		$stacks = array( 'to-do', 'in-progress', 'done' );
		return $stacks[ array_rand( $stacks ) ];
	}

	/**
	 * Custom random number generator for WordPress Playground.
	 *
	 * @param int $min Minimum value.
	 * @param int $max Maximum value.
	 * @return int Random number between $min and $max.
	 */
	private function custom_rand( $min = 0, $max = PHP_INT_MAX ) {
		return rand( $min, $max );
	}
}
