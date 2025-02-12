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
		$this->create_kb_articles( $labels );
		$this->create_events();

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
	 * @param array $labels Array of label term IDs.
	 */
	private function create_kb_articles( $labels ) {
		$lorem_ipsum = array(
			'short' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
			'medium' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
			'long' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
		);

		// Create main categories.
		$categories = array(
			'Getting Started' => array(
				'Introduction' => $lorem_ipsum['medium'],
				'Quick Start Guide' => $lorem_ipsum['long'],
				'Basic Concepts' => $lorem_ipsum['medium'],
			),
			'User Guide' => array(
				'Dashboard Overview' => $lorem_ipsum['medium'],
				'Managing Tasks' => array(
					'Creating Tasks' => $lorem_ipsum['short'],
					'Editing Tasks' => $lorem_ipsum['medium'],
					'Deleting Tasks' => $lorem_ipsum['short'],
				),
				'Working with Boards' => array(
					'Board Setup' => $lorem_ipsum['medium'],
					'Managing Columns' => $lorem_ipsum['long'],
				),
			),
			'Advanced Features' => array(
				'API Integration' => $lorem_ipsum['long'],
				'Custom Workflows' => $lorem_ipsum['medium'],
				'Automation Rules' => $lorem_ipsum['medium'],
			),
		);

		foreach ( $categories as $main_title => $subcategories ) {
			// Create main category article.
			$main_post_id = wp_insert_post(
				array(
					'post_type' => 'decker_kb',
					'post_title' => $main_title,
					'post_content' => $lorem_ipsum['short'],
					'post_status' => 'publish',
					'menu_order' => 0,
				)
			);

			// Assign random labels (1-2) to main category.
			$main_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
			wp_set_object_terms( $main_post_id, $main_labels, 'decker_label' );

			$order = 0;
			foreach ( $subcategories as $sub_title => $content ) {
				if ( is_array( $content ) ) {
					// This is a subcategory with its own children.
					$sub_post_id = wp_insert_post(
						array(
							'post_type' => 'decker_kb',
							'post_title' => $sub_title,
							'post_content' => $lorem_ipsum['medium'],
							'post_status' => 'publish',
							'post_parent' => $main_post_id,
							'menu_order' => $order,
						)
					);

					// Assign random labels to subcategory.
					$sub_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
					wp_set_object_terms( $sub_post_id, $sub_labels, 'decker_label' );

					$sub_order = 0;
					foreach ( $content as $child_title => $child_content ) {
						$child_post_id = wp_insert_post(
							array(
								'post_type' => 'decker_kb',
								'post_title' => $child_title,
								'post_content' => $child_content,
								'post_status' => 'publish',
								'post_parent' => $sub_post_id,
								'menu_order' => $sub_order,
							)
						);

						// Assign random labels to child.
						$child_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
						wp_set_object_terms( $child_post_id, $child_labels, 'decker_label' );

						$sub_order++;
					}
				} else {
					// This is a direct subcategory.
					$sub_post_id = wp_insert_post(
						array(
							'post_type' => 'decker_kb',
							'post_title' => $sub_title,
							'post_content' => $content,
							'post_status' => 'publish',
							'post_parent' => $main_post_id,
							'menu_order' => $order,
						)
					);

					// Assign random labels to subcategory.
					$sub_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
					wp_set_object_terms( $sub_post_id, $sub_labels, 'decker_label' );
				}
				$order++;
			}
		}
	}

	/**
	 * Creates demo events for the current and previous month.
	 *
	 * This method generates events with random titles, categories, locations,
	 * and assigned users. Events can be all-day or have specific time slots.
	 */
	private function create_events() {
		$event_categories = array( 'bg-success', 'bg-info', 'bg-warning', 'bg-danger', 'bg-primary', 'bg-dark' );
		$event_titles = array(
			__( 'Team Meeting', 'decker' ),
			__( 'Project Review', 'decker' ),
			__( 'Training Session', 'decker' ),
			__( 'Client Presentation', 'decker' ),
			__( 'Sprint Planning', 'decker' ),
			__( 'Code Review', 'decker' ),
			__( 'Release Day', 'decker' ),
			__( 'Maintenance Window', 'decker' ),
		);

		$locations = array(
			__( 'Meeting Room A', 'decker' ),
			__( 'Conference Room', 'decker' ),
			__( 'Training Center', 'decker' ),
			__( 'Virtual Meeting', 'decker' ),
			__( 'Main Office', 'decker' ),
		);

		// Get all users for random assignment.
		$users = get_users( array( 'fields' => array( 'ID' ) ) );
		$user_ids = wp_list_pluck( $users, 'ID' );

		// Create events for current month.
		$current_month_start = new DateTime( 'first day of this month' );
		$current_month_end = new DateTime( 'last day of this month' );
		$this->generate_month_events( $current_month_start, $current_month_end, $event_titles, $event_categories, $locations, $user_ids );

		// Create events for previous month.
		$prev_month_start = new DateTime( 'first day of last month' );
		$prev_month_end = new DateTime( 'last day of last month' );
		$this->generate_month_events( $prev_month_start, $prev_month_end, $event_titles, $event_categories, $locations, $user_ids );
	}

	/**
	 * Generates events for a specific month.
	 *
	 * This method creates a random number of events within the given date range.
	 * Each event has a randomly assigned title, category, location, time slot,
	 * and assigned users.
	 *
	 * @param DateTime $start_date Start date of the month.
	 * @param DateTime $end_date   End date of the month.
	 * @param array    $event_titles Array of possible event titles.
	 * @param array    $event_categories Array of possible event categories.
	 * @param array    $locations Array of possible event locations.
	 * @param array    $user_ids Array of user IDs for assignment.
	 */
	private function generate_month_events( $start_date, $end_date, $event_titles, $event_categories, $locations, $user_ids ) {
		$num_events = $this->custom_rand( 5, 10 ); // 5-10 events per month.

		for ( $i = 0; $i < $num_events; $i++ ) {
			// Random date within the month.
			$event_date = clone $start_date;
			$interval = $start_date->diff( $end_date )->days;
			$event_date->modify( '+' . $this->custom_rand( 0, $interval ) . ' days' );

			// 30% chance of all-day event.
			$is_all_day = $this->random_boolean( 0.3 );

			if ( ! $is_all_day ) {
				// For non-all-day events, set random time between 9 AM and 5 PM.
				$hour = $this->custom_rand( 9, 17 );
				$minute = $this->custom_rand( 0, 3 ) * 15; // 0, 15, 30, or 45.
				$event_date->setTime( $hour, $minute );

				// Duration between 30 minutes and 3 hours.
				$duration_minutes = $this->custom_rand( 1, 6 ) * 30;
				$end_date = clone $event_date;
				$end_date->modify( "+{$duration_minutes} minutes" );
			} else {
				$end_date = clone $event_date;
				// All-day events might span 1-3 days.
				$end_date->modify( '+' . $this->custom_rand( 0, 2 ) . ' days' );
			}

			// Create the event.
			$post_data = array(
				'post_type' => 'decker_event',
				'post_title' => $event_titles[ array_rand( $event_titles ) ],
				'post_content' => __( 'Demo event created automatically.', 'decker' ),
				'post_status' => 'publish',
			);

			$post_id = wp_insert_post( $post_data );

			if ( ! is_wp_error( $post_id ) ) {
				// Set event metadata.
				update_post_meta( $post_id, 'event_allday', $is_all_day );
				update_post_meta( $post_id, 'event_start', $event_date->format( 'Y-m-d\TH:i:s' ) );
				update_post_meta( $post_id, 'event_end', $end_date->format( 'Y-m-d\TH:i:s' ) );
				update_post_meta( $post_id, 'event_location', $locations[ array_rand( $locations ) ] );
				update_post_meta( $post_id, 'event_category', $event_categories[ array_rand( $event_categories ) ] );

				// Assign 1-3 random users.
				$num_users = $this->custom_rand( 1, 3 );
				$assigned_users = $this->wp_rand_elements( $user_ids, $num_users );
				update_post_meta( $post_id, 'event_assigned_users', $assigned_users );
			}
		}
	}

	/**
	 * Creates sample tasks for each board.
	 *
	 * This method generates tasks with random labels, assigned users, priority,
	 * due dates, and other attributes, associating them with specific boards.
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
					1,
					false,
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

		return wp_rand( $min, $max );
	}
}
