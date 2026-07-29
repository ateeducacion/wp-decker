<?php
/**
 * Demo data generator for the Decker plugin.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

require_once __DIR__ . '/class-decker-demo-randomizer.php';
require_once __DIR__ . '/class-decker-demo-tasks.php';

/**
 * Class for generating demo data.
 */
class Decker_Demo_Data {

	/**
	 * Shared source of random choices.
	 *
	 * @var Decker_Demo_Randomizer
	 */
	private $random;

	/**
	 * Set up the shared randomizer.
	 */
	public function __construct() {
		$this->random = new Decker_Demo_Randomizer();
	}

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
		( new Decker_Demo_Tasks( $this->random ) )->create_tasks( $boards, $labels );
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
			$term_color = $this->random->generate_random_color();

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
			$term_color = $this->random->generate_random_color();
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
		$article_labels = $this->random->wp_rand_elements( $labels, $this->random->custom_rand( 1, 2 ) );
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
		$num_events = $this->random->custom_rand( 5, 10 ); // 5-10 events per month.

		for ( $i = 0; $i < $num_events; $i++ ) {
			// Random date within the month.
			$event_date = clone $start_date;
			$interval = $start_date->diff( $end_date )->days;
			$event_date->modify( '+' . $this->random->custom_rand( 0, $interval ) . ' days' );

			// 50% chance of all-day event.
			$is_all_day = $this->random->random_boolean( 0.5 );

			if ( ! $is_all_day ) {
				// For non-all-day events, set random time between 9 AM and 5 PM.
				$hour = $this->random->custom_rand( 9, 17 );
				$minute = $this->random->custom_rand( 0, 3 ) * 15; // 0, 15, 30, or 45.
				$event_date->setTime( $hour, $minute );

				// Duration between 30 minutes and 3 hours.
				$duration_minutes = $this->random->custom_rand( 1, 6 ) * 30;
				$end_date = clone $event_date;
				$end_date->modify( "+{$duration_minutes} minutes" );
			} else {
				$end_date = clone $event_date;
				// All-day events might span 1-3 days.
				$end_date->modify( '+' . $this->random->custom_rand( 0, 2 ) . ' days' );
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
					'event_assigned_users' => $this->random->wp_rand_elements( $user_ids, $this->random->custom_rand( 1, 3 ) ),
				);

				// Save the metadaa.
				$events_handler = new Decker_Events();
				$events_handler->process_and_save_meta( $post_id, $data );
			}
		}
	}

}
