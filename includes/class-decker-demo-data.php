<?php
/**
 * Demo data generator for the Decker plugin.
 *
 * @package Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

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
}
