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

		// Create labels with varying lengths for better testing.
		$label_names = array(
			'Bug',
			'Feature',
			'Urgent Priority',
			'Documentation',
			'Needs Review',
			'In Progress',
			'Testing Required',
			'UI',
			'Backend Development',
			'Critical Security Issue',
		);

		foreach ( $label_names as $term_name ) {
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
	 * Creates sample boards with different visibility settings.
	 *
	 * @return array Array of board term IDs.
	 */
	private function create_boards() {
		$boards = array();
		$visibility_settings = array(
			// Board 1: Visible in both Boards and KB.
			array(
				'name' => 'Project Alpha',
				'show_in_boards' => '1',
				'show_in_kb' => '1',
			),
			// Board 2: Visible only in Boards.
			array(
				'name' => 'Marketing Campaign Q1 2024',
				'show_in_boards' => '1',
				'show_in_kb' => '0',
			),
			// Board 3: Visible only in KB.
			array(
				'name' => 'Dev',
				'show_in_boards' => '0',
				'show_in_kb' => '1',
			),
			// Board 4: Not visible in either (hidden).
			array(
				'name' => 'Customer Support and Success Team',
				'show_in_boards' => '0',
				'show_in_kb' => '0',
			),
			// Board 5: Visible in both.
			array(
				'name' => 'HR',
				'show_in_boards' => '1',
				'show_in_kb' => '1',
			),
			// Board 6: Visible in both.
			array(
				'name' => 'Infrastructure and DevOps',
				'show_in_boards' => '1',
				'show_in_kb' => '1',
			),
			// Board 7: Visible only in Boards.
			array(
				'name' => 'Research',
				'show_in_boards' => '1',
				'show_in_kb' => '0',
			),
			// Board 8: Visible only in KB.
			array(
				'name' => 'Quality Assurance and Testing',
				'show_in_boards' => '0',
				'show_in_kb' => '1',
			),
			// Board 9: Visible in both.
			array(
				'name' => 'Sales',
				'show_in_boards' => '1',
				'show_in_kb' => '1',
			),
		);

		foreach ( $visibility_settings as $board_config ) {
			$term_name = $board_config['name'];
			$term_slug = sanitize_title( $term_name );
			$term_color = $this->generate_random_color();
			$show_in_boards = $board_config['show_in_boards'];
			$show_in_kb = $board_config['show_in_kb'];

			// Check if the board already exists.
			$existing_term = term_exists( $term_slug, 'decker_board' );
			if ( $existing_term ) {
				// Update visibility settings for existing board.
				update_term_meta( $existing_term['term_id'], 'term-show-in-boards', $show_in_boards );
				update_term_meta( $existing_term['term_id'], 'term-show-in-kb', $show_in_kb );
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
				add_term_meta( $term['term_id'], 'term-show-in-boards', $show_in_boards, true );
				add_term_meta( $term['term_id'], 'term-show-in-kb', $show_in_kb, true );
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
		$lorem_ipsum = $this->get_kb_demo_lorem();

		// Get boards that are visible in KB.
		$kb_boards = get_terms(
			array(
				'taxonomy' => 'decker_board',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key' => 'term-show-in-kb',
						'value' => '1',
						'compare' => '=',
					),
				),
			)
		);

		if ( empty( $kb_boards ) ) {
			return;
		}

		// Create main categories; include deeper hierarchy for demo (3+ levels).
		$categories = $this->get_kb_demo_categories( $lorem_ipsum );

		// Create articles for each KB-visible board.
		foreach ( $kb_boards as $board_term ) {
			// For each board, create a set of articles.
			foreach ( $categories as $main_title => $subcategories ) {
				// Create main category article (no board suffix in title).
				$main_post_id = $this->insert_kb_article( $main_title, $lorem_ipsum['short'], 0, 0, $board_term->term_id, $labels );

				// Create the subcategory subtree under this root.
				$this->create_kb_subtree( $subcategories, $main_post_id, 1, $board_term->term_id, $lorem_ipsum, $labels );
			}
		}
	}

	/**
	 * Returns the lorem ipsum strings used for KB demo content.
	 *
	 * @return array Associative array with short/medium/long keys.
	 */
	private function get_kb_demo_lorem() {
		return array(
			'short' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
			'medium' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
			'long' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
		);
	}

	/**
	 * Returns the nested KB demo category tree.
	 *
	 * @param array $lorem Lorem ipsum strings keyed short/medium/long.
	 * @return array Nested category tree (title => content|subtree).
	 */
	private function get_kb_demo_categories( $lorem ) {
		return array(
			'Getting Started' => array(
				'Introduction' => $lorem['medium'],
				'Quick Start Guide' => $lorem['long'],
				'Basic Concepts' => $lorem['medium'],
			),
			'User Guide' => array(
				'Dashboard Overview' => $lorem['medium'],
				'Managing Tasks' => array(
					'Creating Tasks' => $lorem['short'],
					'Editing Tasks' => array(
						'Basic Edits' => $lorem['medium'],
						'Advanced Edits' => array(
							'Keyboard Shortcuts' => $lorem['short'],
							'Bulk Changes' => $lorem['short'],
						),
					),
					'Deleting Tasks' => $lorem['short'],
				),
				'Working with Boards' => array(
					'Board Setup' => $lorem['medium'],
					'Managing Columns' => $lorem['long'],
				),
			),
			'Advanced Features' => array(
				'API Integration' => array(
					'Authentication' => $lorem['medium'],
					'Endpoints' => array(
						'GET /tasks' => $lorem['short'],
						'POST /tasks' => $lorem['short'],
					),
				),
				'Custom Workflows' => $lorem['medium'],
				'Automation Rules' => $lorem['medium'],
			),
		);
	}

	/**
	 * Inserts a single KB article and assigns its labels and board.
	 *
	 * Side-effect order is preserved: post insert, then label terms, then board term.
	 *
	 * @param string $title         Article title.
	 * @param string $content       Article content.
	 * @param int    $parent_id     Parent post ID (0 for roots).
	 * @param int    $menu_order    Menu order for the article.
	 * @param int    $board_term_id Board term ID to assign.
	 * @param array  $labels        Array of label term IDs to draw from.
	 * @return int The created post ID.
	 */
	private function insert_kb_article( $title, $content, $parent_id, $menu_order, $board_term_id, $labels ) {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'decker_kb',
				'post_title' => $title,
				'post_content' => $content,
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'menu_order' => $menu_order,
			)
		);

		// Assign random labels (1-2) to the article.
		$article_labels = $this->wp_rand_elements( $labels, $this->custom_rand( 1, 2 ) );
		wp_set_object_terms( $post_id, $article_labels, 'decker_label' );

		// Assign the board.
		wp_set_object_terms( $post_id, array( $board_term_id ), 'decker_board' );

		return $post_id;
	}

	/**
	 * Recursively creates the KB subtree below a parent article.
	 *
	 * Reproduces the demo hierarchy rules: branch nodes use medium lorem and
	 * recurse, leaf nodes keep their own content, and depth-3 nodes are always
	 * inserted as leaves (an array grandchild collapses to short lorem and its
	 * children are dropped). Siblings are ordered sequentially at every level.
	 *
	 * @param array $nodes         Title => content|subtree map for this level.
	 * @param int   $parent_id     Parent post ID.
	 * @param int   $depth         Current depth (root children start at 1).
	 * @param int   $board_term_id Board term ID to assign.
	 * @param array $lorem         Lorem ipsum strings keyed short/medium/long.
	 * @param array $labels        Array of label term IDs to draw from.
	 */
	private function create_kb_subtree( $nodes, $parent_id, $depth, $board_term_id, $lorem, $labels ) {
		$order = 0;
		foreach ( $nodes as $title => $content ) {
			if ( 3 === $depth ) {
				// Grandchild depth: always a leaf, array children are dropped.
				$leaf_content = is_array( $content ) ? $lorem['short'] : $content;
				$this->insert_kb_article( $title, $leaf_content, $parent_id, $order, $board_term_id, $labels );
			} elseif ( is_array( $content ) ) {
				// Branch node with its own children.
				$post_id = $this->insert_kb_article( $title, $lorem['medium'], $parent_id, $order, $board_term_id, $labels );
				$this->create_kb_subtree( $content, $post_id, $depth + 1, $board_term_id, $lorem, $labels );
			} else {
				// Leaf node keeps its own content.
				$this->insert_kb_article( $title, $content, $parent_id, $order, $board_term_id, $labels );
			}
			$order++;
		}
	}

	/**
	 * Creates demo events for the current and previous month.
	 *
	 * This method generates events with random titles, categories, locations,
	 * and assigned users. Events can be all-day or have specific time slots.
	 */
	private function create_events() {
		$event_categories = array( 'bg-success', 'bg-info', 'bg-warning' );
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

		$event_urls = array(
			'https://site1.example.com',
			'https://site2.example.com',
			'https://wikipedia.org',
		);

		$event_locations = array(
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
		$this->generate_month_events( $current_month_start, $current_month_end, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids );

		// Create events for previous month.
		$prev_month_start = new DateTime( 'first day of last month' );
		$prev_month_end = new DateTime( 'last day of last month' );
		$this->generate_month_events( $prev_month_start, $prev_month_end, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids );
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
	 * @param array    $event_urls Array of possible event urls.
	 * @param array    $event_locations Array of possible event locations.
	 * @param array    $user_ids Array of user IDs for assignment.
	 */
	private function generate_month_events( $start_date, $end_date, $event_titles, $event_categories, $event_urls, $event_locations, $user_ids ) {
		$num_events = $this->custom_rand( 5, 10 ); // 5-10 events per month.

		for ( $i = 0; $i < $num_events; $i++ ) {
			// Random date within the month.
			$event_date = clone $start_date;
			$interval = $start_date->diff( $end_date )->days;
			$event_date->modify( '+' . $this->custom_rand( 0, $interval ) . ' days' );

			// 50% chance of all-day event.
			$is_all_day = $this->random_boolean( 0.5 );

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
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'decker_event',
					'post_title'  => $event_titles[ array_rand( $event_titles ) ],
					'post_content' => __( 'Demo event created automatically.', 'decker' ),
					'post_status' => 'publish',
				)
			);

			if ( ! is_wp_error( $post_id ) ) {
				// Prepare data as expected in process_and_save_meta().
				$data = array(
					'event_allday'         => $is_all_day,
					'event_start'          => $event_date->format( $is_all_day ? 'Y-m-d' : 'Y-m-d H:i:s' ),
					'event_end'            => $end_date->format( $is_all_day ? 'Y-m-d' : 'Y-m-d H:i:s' ),
					'event_location'       => $event_locations[ array_rand( $event_locations ) ],
					'event_url'            => $event_urls[ array_rand( $event_urls ) ],
					'event_category'       => $event_categories[ array_rand( $event_categories ) ],
					// Assign 1-3 random users.
					'event_assigned_users' => $this->wp_rand_elements( $user_ids, $this->custom_rand( 1, 3 ) ),
				);

				// Save the metadaa.
				$events_handler = new Decker_Events();
				$events_handler->process_and_save_meta( $post_id, $data );
			}
		}
	}

	/**
	 * Creates sample tasks for each board.
	 *
	 * This method generates tasks with random labels, assigned users, priority,
	 * due dates, and other attributes, associating them with specific boards.
	 * Only creates tasks for boards that are visible in the Boards section.
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

		// Get boards that are visible in Boards section.
		$visible_boards = get_terms(
			array(
				'taxonomy' => 'decker_board',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key' => 'term-show-in-boards',
						'value' => '1',
						'compare' => '=',
					),
				),
			)
		);

		$visible_board_ids = wp_list_pluck( $visible_boards, 'term_id' );

		foreach ( $boards as $board_id ) {
			$board = get_term( $board_id, 'decker_board' );
			if ( is_wp_error( $board ) ) {
				continue;
			}

			// Check if this board is visible in Boards section.
			$show_in_boards = get_term_meta( $board_id, 'term-show-in-boards', true );

			// Depending on board visibility, the number of tasks to create is set.
			if ( '1' === $show_in_boards ) {
				$num_tasks = 10;
			} else {
				$num_tasks = 3; // Fewer tasks are created if the board is hidden.
			}

			for ( $j = 1; $j <= $num_tasks; $j++ ) {
				$this->create_demo_task( $board, $show_in_boards, $j, $labels, $user_ids );
			}
		}
	}

	/**
	 * Creates a single demo task and its related meta and comments.
	 *
	 * @param WP_Term $board          Board term object.
	 * @param string  $show_in_boards Board visibility flag ('1' for visible).
	 * @param int     $index          Sequential task index within the board.
	 * @param array   $labels         Array of label term IDs to draw from.
	 * @param array   $user_ids       Array of user IDs to draw from.
	 */
	private function create_demo_task( $board, $show_in_boards, $index, $labels, $user_ids ) {
		$post_title = $this->generate_demo_task_title( $index, $board->name, $show_in_boards );

		$post_content = "Content for task $index in board {$board->name}.";

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
		$start_date = $this->random_date( '-2 months', 'now' );
		$duration = $this->custom_rand( 1, 14 );
		$end_date = clone $start_date;
		$end_date->modify( "+{$duration} days" );
		$stack = $this->random_stack();

		$task_id = Decker_Tasks::create_or_update_task(
			0,
			$post_title,
			$post_content,
			$stack,
			$board->term_id,
			$max_priority,
			$end_date, // due date is end of task.
			1,
			1,
			false,
			$assigned_users,
			$assigned_labels,
			$creation_date,
			$archived,
			0
		);

		if ( $task_id && ! is_wp_error( $task_id ) ) {
			// Generate user-date relations for each day in the task duration.
			$relations = $this->build_user_date_relations( $assigned_users, $start_date, $end_date );

			update_post_meta( $task_id, '_user_date_relations', $relations );
			update_post_meta( $task_id, 'startdate', $start_date->format( 'Y-m-d' ) );

			// Seed comments so the board comments popover has something to preview.
			$this->seed_task_comments( $task_id, $assigned_users, $start_date, $end_date );
		}
	}

	/**
	 * Generates a demo task title with a random length pool and optional suffix.
	 *
	 * @param int    $index          Sequential task index within the board.
	 * @param string $board_name     Board name used in medium/long titles.
	 * @param string $show_in_boards Board visibility flag ('1' for visible).
	 * @return string The generated task title.
	 */
	private function generate_demo_task_title( $index, $board_name, $show_in_boards ) {
		// Create task titles with varying lengths for better testing.
		$short_titles = array(
			'Fix bug',
			'Update docs',
			'Review PR',
			'Deploy',
			'Test',
		);

		$medium_titles = array(
			'Implement new feature',
			'Refactor database queries',
			'Update user interface',
			'Configure deployment pipeline',
			'Write unit tests',
		);

		$long_titles = array(
			'Investigate performance issues in the production environment',
			'Develop comprehensive documentation for API endpoints',
			'Implement user authentication and authorization system',
			'Optimize database queries for improved application performance',
			'Create automated testing suite for continuous integration',
		);

		// Randomly select title length (40% short, 40% medium, 20% long).
		$rand = $this->custom_rand( 1, 10 );
		if ( $rand <= 4 ) {
			$post_title = $short_titles[ array_rand( $short_titles ) ] . " #{$index}";
		} elseif ( $rand <= 8 ) {
			$post_title = $medium_titles[ array_rand( $medium_titles ) ] . " for {$board_name}";
		} else {
			$post_title = $long_titles[ array_rand( $long_titles ) ] . " - {$board_name}";
		}

		if ( '1' !== $show_in_boards ) {
			$post_title .= ' (Hidden Board)';
		}

		return $post_title;
	}

	/**
	 * Builds the _user_date_relations rows for a task.
	 *
	 * The original quadratic loop structure is preserved verbatim: the outer
	 * loop iterates each day of the period and re-randomizes per day, and the
	 * inner loop reuses $day, so row counts must not be "optimized".
	 *
	 * @param array    $assigned_users Assigned user IDs.
	 * @param DateTime $start_date     Task start date.
	 * @param DateTime $end_date       Task end date.
	 * @return array Array of user_id/date relation rows.
	 */
	private function build_user_date_relations( $assigned_users, $start_date, $end_date ) {
		$relations = array();
		$period_start = clone $start_date;
		$period_end = clone $end_date;
		$period_end->modify( '+1 day' ); // to include end date.

		$interval = new DateInterval( 'P1D' );
		$period = new DatePeriod( $period_start, $interval, $period_end );

		foreach ( $period as $day ) {
			foreach ( $assigned_users as $user_id ) {
				$dates = iterator_to_array( $period );
				$days_to_assign = $this->custom_rand( 1, count( $dates ) );
				$random_dates = $this->wp_rand_elements( $dates, $days_to_assign );

				foreach ( $random_dates as $day ) {
					$relations[] = array(
						'user_id' => $user_id,
						'date'    => $day->format( 'Y-m-d' ),
					);
				}
			}
		}

		return $relations;
	}

	/**
	 * Seeds a varied set of demo comments on a task so the board popover
	 * preview can be exercised with short, long, multi-author and link
	 * containing content.
	 *
	 * @param int      $task_id        Target task post ID.
	 * @param int[]    $assigned_users Users available as comment authors.
	 * @param DateTime $start_date     Earliest plausible comment date.
	 * @param DateTime $end_date       Latest plausible comment date.
	 */
	private function seed_task_comments( $task_id, $assigned_users, $start_date, $end_date ) {
		// 30 % no comments, 30 % a single one, 25 % a handful, 15 % a long thread.
		$count = $this->get_demo_comment_count();
		if ( 0 === $count ) {
			return;
		}

		$samples = $this->get_demo_comment_samples();

		$first_ts = $start_date->getTimestamp();
		$last_ts = $end_date->getTimestamp();
		if ( $last_ts <= $first_ts ) {
			$last_ts = $first_ts + DAY_IN_SECONDS;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$author = $this->resolve_demo_comment_author( $assigned_users );

			$comment_ts = $this->custom_rand( $first_ts, $last_ts );
			$content = $samples[ array_rand( $samples ) ];

			wp_insert_comment(
				array(
					'comment_post_ID'      => $task_id,
					'comment_author'       => $author['name'],
					'comment_author_email' => $author['email'],
					'comment_author_url'   => '',
					'comment_content'      => $content,
					'comment_type'         => 'comment',
					'user_id'              => $author['id'],
					'comment_approved'     => 1,
					'comment_date'         => gmdate( 'Y-m-d H:i:s', $comment_ts ),
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $comment_ts ),
				)
			);
		}
	}

	/**
	 * Draws the demo comment count for a task using weighted buckets.
	 *
	 * 30 % no comments, 30 % a single one, 25 % a handful, 15 % a long thread.
	 * Exactly one random draw is consumed per branch, matching the original.
	 *
	 * @return int Number of comments to create (0, 1, 2-4 or 6-10).
	 */
	private function get_demo_comment_count() {
		$bucket = $this->custom_rand( 1, 100 );
		if ( $bucket <= 30 ) {
			return 0;
		} elseif ( $bucket <= 60 ) {
			return 1;
		} elseif ( $bucket <= 85 ) {
			return $this->custom_rand( 2, 4 );
		}
		return $this->custom_rand( 6, 10 );
	}

	/**
	 * Returns the demo comment content samples.
	 *
	 * @return array Array of HTML comment bodies.
	 */
	private function get_demo_comment_samples() {
		$short_lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.';
		$medium_lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco.';
		$long_lorem = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.';

		return array(
			'<p>' . $short_lorem . '</p>',
			'<p>' . $medium_lorem . '</p>',
			'<p>' . $long_lorem . '</p>',
			'<p>Check the spec at <a href="https://example.com/spec">example.com/spec</a> before continuing.</p>',
			'<p>' . $short_lorem . '</p><p>Reference: <a href="https://example.org/docs">example.org/docs</a>.</p>',
			'<p>Quick update: ' . $short_lorem . '</p>',
			'<p>' . $medium_lorem . '</p><p>' . $short_lorem . '</p>',
		);
	}

	/**
	 * Resolves the author identity for a demo comment.
	 *
	 * Draws a random assigned user (consuming one array_rand draw, as in the
	 * original) and falls back to the 'Demo' identity when there is no user or
	 * the user no longer exists, while keeping the drawn user_id.
	 *
	 * @param int[] $assigned_users Users available as comment authors.
	 * @return array Array with id (int), name (string) and email (string).
	 */
	private function resolve_demo_comment_author( $assigned_users ) {
		$author_id = ! empty( $assigned_users )
			? $assigned_users[ array_rand( $assigned_users ) ]
			: 0;
		$author = $author_id ? get_userdata( $author_id ) : false;

		return array(
			'id'    => $author_id,
			'name'  => $author ? $author->display_name : 'Demo',
			'email' => $author ? $author->user_email : 'demo@example.com',
		);
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
