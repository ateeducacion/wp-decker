<?php
/**
 * Characterization tests for the demo data generator.
 *
 * Demo data is non-deterministic (wp_rand is unseedable), so these tests pin
 * only STRUCTURAL invariants and ranges (counts, buckets, formats) — never
 * exact random values.
 *
 * @package Decker
 */

class DeckerDemoDataTest extends Decker_Test_Base {

	/**
	 * Seeds the sample data once per test.
	 */
	public function set_up(): void {
		parent::set_up();
		do_action( 'init' );

		require_once plugin_dir_path( DECKER_PLUGIN_FILE ) . 'includes/class-decker-demo-data.php';
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'decker_settings' );
		parent::tear_down();
	}

	/**
	 * Helper: count posts of a type carrying a given board term.
	 *
	 * @param string $post_type Post type.
	 * @param int    $board_id  Board term ID.
	 * @return WP_Post[]
	 */
	private function posts_for_board( $post_type, $board_id ) {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => 'any',
				'numberposts' => -1,
				'tax_query'   => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_id,
					),
				),
			)
		);
	}

	/**
	 * Helper: get a board term by slug.
	 *
	 * @param string $slug Board slug.
	 * @return WP_Term|false
	 */
	private function board( $slug ) {
		return get_term_by( 'slug', $slug, 'decker_board' );
	}

	/**
	 * Labels and boards are created with their visibility meta.
	 */
	public function test_create_sample_data_creates_ten_labels_and_nine_boards_with_visibility_meta() {
		( new Decker_Demo_Data() )->create_sample_data();

		$labels = get_terms(
			array(
				'taxonomy'   => 'decker_label',
				'hide_empty' => false,
			)
		);
		$this->assertCount( 10, $labels );
		foreach ( $labels as $label ) {
			$color = get_term_meta( $label->term_id, 'term-color', true );
			$this->assertMatchesRegularExpression( '/^#[0-9A-Fa-f]{6}$/', $color );
		}

		$boards = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
			)
		);
		$this->assertCount( 9, $boards );

		$show_in_boards = array( 'project-alpha', 'marketing-campaign-q1-2024', 'hr', 'infrastructure-and-devops', 'research', 'sales' );
		$hidden_boards  = array( 'dev', 'customer-support-and-success-team', 'quality-assurance-and-testing' );

		foreach ( $show_in_boards as $slug ) {
			$this->assertSame( '1', get_term_meta( $this->board( $slug )->term_id, 'term-show-in-boards', true ), $slug );
		}
		foreach ( $hidden_boards as $slug ) {
			$this->assertSame( '0', get_term_meta( $this->board( $slug )->term_id, 'term-show-in-boards', true ), $slug );
		}

		$kb_visible = array( 'project-alpha', 'dev', 'hr', 'infrastructure-and-devops', 'quality-assurance-and-testing', 'sales' );
		foreach ( $kb_visible as $slug ) {
			$this->assertSame( '1', get_term_meta( $this->board( $slug )->term_id, 'term-show-in-kb', true ), $slug );
		}
	}

	/**
	 * Tasks per board follow the visibility branch and the hidden-board suffix.
	 */
	public function test_create_sample_data_creates_tasks_per_board_visibility() {
		( new Decker_Demo_Data() )->create_sample_data();

		$show_in_boards = array( 'project-alpha', 'marketing-campaign-q1-2024', 'hr', 'infrastructure-and-devops', 'research', 'sales' );
		$hidden_boards  = array( 'dev', 'customer-support-and-success-team', 'quality-assurance-and-testing' );

		foreach ( $show_in_boards as $slug ) {
			$tasks = $this->posts_for_board( 'decker_task', $this->board( $slug )->term_id );
			$this->assertCount( 10, $tasks, $slug );
			foreach ( $tasks as $task ) {
				$this->assertStringNotContainsString( '(Hidden Board)', $task->post_title );
			}
		}

		foreach ( $hidden_boards as $slug ) {
			$tasks = $this->posts_for_board( 'decker_task', $this->board( $slug )->term_id );
			$this->assertCount( 3, $tasks, $slug );
			foreach ( $tasks as $task ) {
				$this->assertStringEndsWith( ' (Hidden Board)', $task->post_title );
			}
		}
	}

	/**
	 * Each task gets a startdate and well-formed user-date relations.
	 */
	public function test_create_sample_data_seeds_task_relations_and_start_date() {
		( new Decker_Demo_Data() )->create_sample_data();

		$tasks = get_posts(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$this->assertNotEmpty( $tasks );

		foreach ( $tasks as $task ) {
			$startdate = get_post_meta( $task->ID, 'startdate', true );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $startdate );

			$assigned = get_post_meta( $task->ID, 'assigned_users', true );
			$this->assertIsArray( $assigned );
			$this->assertGreaterThanOrEqual( 1, count( $assigned ) );
			$this->assertLessThanOrEqual( 3, count( $assigned ) );

			$labels = wp_get_object_terms( $task->ID, 'decker_label', array( 'fields' => 'ids' ) );
			$this->assertLessThanOrEqual( 3, count( $labels ) );

			$relations = get_post_meta( $task->ID, '_user_date_relations', true );
			$this->assertIsArray( $relations );

			$start_ts = strtotime( $startdate );
			$max_ts   = $start_ts + ( 15 * DAY_IN_SECONDS );
			foreach ( $relations as $row ) {
				$this->assertArrayHasKey( 'user_id', $row );
				$this->assertArrayHasKey( 'date', $row );
				$this->assertContains( (int) $row['user_id'], array_map( 'intval', $assigned ) );
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $row['date'] );
				$date_ts = strtotime( $row['date'] );
				$this->assertGreaterThanOrEqual( $start_ts, $date_ts );
				$this->assertLessThanOrEqual( $max_ts, $date_ts );
			}
		}
	}

	/**
	 * Comment counts always fall into one of the defined buckets.
	 */
	public function test_create_sample_data_comment_counts_match_buckets() {
		( new Decker_Demo_Data() )->create_sample_data();

		$tasks = get_posts(
			array(
				'post_type'   => 'decker_task',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		foreach ( $tasks as $task ) {
			$comments = get_comments( array( 'post_id' => $task->ID ) );
			$n        = count( $comments );
			$this->assertTrue(
				0 === $n || 1 === $n || ( $n >= 2 && $n <= 4 ) || ( $n >= 6 && $n <= 10 ),
				"Unexpected comment count {$n} for task {$task->ID}"
			);

			foreach ( $comments as $comment ) {
				$this->assertEquals( '1', $comment->comment_approved );
				$this->assertEquals( 'comment', $comment->comment_type );
				$this->assertNotEmpty( $comment->comment_author );
				$this->assertNotEmpty( $comment->comment_author_email );
				$this->assertStringStartsWith( '<p>', $comment->comment_content );
			}
		}
	}

	/**
	 * The KB tree per KB-visible board reproduces the depth-3 nesting and order.
	 */
	public function test_create_sample_data_builds_kb_tree_per_kb_visible_board() {
		( new Decker_Demo_Data() )->create_sample_data();

		$kb_visible = array( 'project-alpha', 'dev', 'hr', 'infrastructure-and-devops', 'quality-assurance-and-testing', 'sales' );

		foreach ( $kb_visible as $slug ) {
			$board_id = $this->board( $slug )->term_id;
			$articles = $this->posts_for_board( 'decker_kb', $board_id );

			// The current generator hard-stops at depth 3, so an array node at the
			// grandchild level becomes a single leaf and its children are dropped.
			// This yields exactly 23 posts per KB-visible board.
			$this->assertCount( 23, $articles, $slug );

			$roots = array_filter(
				$articles,
				function ( $p ) {
					return 0 === (int) $p->post_parent;
				}
			);
			$this->assertCount( 3, $roots, $slug );
			$root_titles = wp_list_pluck( $roots, 'post_title' );
			sort( $root_titles );
			$this->assertSame( array( 'Advanced Features', 'Getting Started', 'User Guide' ), $root_titles, $slug );

			// Each article has exactly one board and 1-2 labels.
			foreach ( $articles as $article ) {
				$labels = wp_get_object_terms( $article->ID, 'decker_label', array( 'fields' => 'ids' ) );
				$this->assertGreaterThanOrEqual( 1, count( $labels ) );
				$this->assertLessThanOrEqual( 2, count( $labels ) );
				$boards = wp_get_object_terms( $article->ID, 'decker_board', array( 'fields' => 'ids' ) );
				$this->assertCount( 1, $boards );
			}

			$titles = wp_list_pluck( $articles, 'post_title' );

			// The depth-3 cutoff drops the grandchildren of 'Advanced Edits'.
			$this->assertNotContains( 'Bulk Changes', $titles, $slug );
			$this->assertNotContains( 'Keyboard Shortcuts', $titles, $slug );

			// 'Advanced Edits' is itself inserted as a depth-3 leaf.
			$advanced = null;
			foreach ( $articles as $article ) {
				if ( 'Advanced Edits' === $article->post_title ) {
					$advanced = $article;
					break;
				}
			}
			$this->assertNotNull( $advanced, $slug );
			$ancestors = get_post_ancestors( $advanced->ID );
			$this->assertCount( 3, $ancestors, $slug );
			$ancestor_titles = array();
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor_titles[] = get_post( $ancestor_id )->post_title;
			}
			$this->assertSame( array( 'Editing Tasks', 'Managing Tasks', 'User Guide' ), $ancestor_titles, $slug );

			// The depth-3 leaf has no children of its own.
			$advanced_children = get_children(
				array(
					'post_type'   => 'decker_kb',
					'post_parent' => $advanced->ID,
					'numberposts' => -1,
				)
			);
			$this->assertCount( 0, $advanced_children, $slug );

			// 'User Guide' children keep menu_order 0,1,2 in title order.
			$user_guide = null;
			foreach ( $articles as $article ) {
				if ( 'User Guide' === $article->post_title && 0 === (int) $article->post_parent ) {
					$user_guide = $article;
					break;
				}
			}
			$this->assertNotNull( $user_guide, $slug );
			$children = get_children(
				array(
					'post_type'   => 'decker_kb',
					'post_parent' => $user_guide->ID,
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
					'numberposts' => -1,
				)
			);
			$ordered = array();
			foreach ( $children as $child ) {
				$ordered[ (int) $child->menu_order ] = $child->post_title;
			}
			$this->assertSame(
				array(
					0 => 'Dashboard Overview',
					1 => 'Managing Tasks',
					2 => 'Working with Boards',
				),
				$ordered,
				$slug
			);
		}
	}

	/**
	 * Demo events are created with expected count and meta.
	 */
	public function test_create_sample_data_creates_demo_events_with_meta() {
		( new Decker_Demo_Data() )->create_sample_data();

		$events = get_posts(
			array(
				'post_type'   => 'decker_event',
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);
		$this->assertGreaterThanOrEqual( 10, count( $events ) );
		$this->assertLessThanOrEqual( 20, count( $events ) );

		foreach ( $events as $event ) {
			// 'event_allday' is registered with a rest_sanitize_boolean callback, so the
			// '1'/'0' string saved by process_and_save_meta() is coerced to a boolean and
			// stored by WordPress as '1' (true) or '' (false, the empty-string meta value).
			$allday = get_post_meta( $event->ID, 'event_allday', true );
			$this->assertContains( (string) $allday, array( '1', '' ) );

			$start = get_post_meta( $event->ID, 'event_start', true );
			$end   = get_post_meta( $event->ID, 'event_end', true );
			$this->assertNotFalse( strtotime( $start ) );
			$this->assertNotFalse( strtotime( $end ) );
			$this->assertLessThanOrEqual( strtotime( $end ), strtotime( $start ) );

			$assigned = get_post_meta( $event->ID, 'event_assigned_users', true );
			$this->assertIsArray( $assigned );
			$this->assertGreaterThanOrEqual( 1, count( $assigned ) );
			$this->assertLessThanOrEqual( 3, count( $assigned ) );
		}
	}

	/**
	 * The alert option is written and the original current user is restored.
	 */
	public function test_create_sample_data_sets_alert_option_and_restores_current_user() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		( new Decker_Demo_Data() )->create_sample_data();

		$this->assertSame( $subscriber, get_current_user_id() );

		$options = get_option( 'decker_settings' );
		$this->assertSame( 'danger', $options['alert_color'] );
		$this->assertStringContainsString( 'demo data', $options['alert_message'] );
	}

	/**
	 * A second seeding run keeps every existing term and appends tasks.
	 *
	 * create_labels()/create_boards() never delete or reset terms, so a second
	 * run can only keep or grow the term set: the per-run name lists (10 labels,
	 * 9 boards) act as a floor. Tasks carry no dedupe and therefore double.
	 */
	public function test_create_sample_data_second_run_keeps_terms_and_appends_tasks() {
		( new Decker_Demo_Data() )->create_sample_data();

		$first_label_count = count(
			get_terms(
				array(
					'taxonomy'   => 'decker_label',
					'hide_empty' => false,
				)
			)
		);
		$first_board_count = count(
			get_terms(
				array(
					'taxonomy'   => 'decker_board',
					'hide_empty' => false,
				)
			)
		);
		$first_task_count = count(
			get_posts(
				array(
					'post_type'   => 'decker_task',
					'post_status' => 'any',
					'numberposts' => -1,
				)
			)
		);

		( new Decker_Demo_Data() )->create_sample_data();

		// Seeding is never destructive: the first run's terms always survive.
		$this->assertGreaterThanOrEqual(
			$first_label_count,
			count(
				get_terms(
					array(
						'taxonomy'   => 'decker_label',
						'hide_empty' => false,
					)
				)
			)
		);
		$this->assertGreaterThanOrEqual(
			$first_board_count,
			count(
				get_terms(
					array(
						'taxonomy'   => 'decker_board',
						'hide_empty' => false,
					)
				)
			)
		);

		$second_task_count = count(
			get_posts(
				array(
					'post_type'   => 'decker_task',
					'post_status' => 'any',
					'numberposts' => -1,
				)
			)
		);
		$this->assertSame( $first_task_count * 2, $second_task_count );
	}
}
