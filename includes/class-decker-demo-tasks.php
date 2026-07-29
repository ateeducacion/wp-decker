<?php
/**
 * Seeds the demo tasks and their comments.
 *
 * One demo task per slot on each board, with titles that encode the board's
 * visibility, randomized assignees, date relations for the "for today" view,
 * and a plausible comment thread.
 *
 * @package Decker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Demo_Tasks
 */
class Decker_Demo_Tasks {

	/**
	 * Shared source of random choices.
	 *
	 * @var Decker_Demo_Randomizer
	 */
	private $random;

	/**
	 * Take the shared randomizer.
	 *
	 * @param Decker_Demo_Randomizer $random Shared source of random choices.
	 */
	public function __construct( Decker_Demo_Randomizer $random ) {
		$this->random = $random;
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
	public function create_tasks( $boards, $labels ) {
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
		$num_labels = $this->random->custom_rand( 0, 3 );
		$assigned_labels = ( $num_labels > 0 && ! empty( $labels ) )
			? $this->random->wp_rand_elements( $labels, $num_labels )
			: array();

		// Assign random users (1 to 3 users).
		$num_users = $this->random->custom_rand( 1, 3 );
		$assigned_users = $this->random->wp_rand_elements( $user_ids, $num_users );

		// Generate additional fields.
		$max_priority = $this->random->random_boolean( 0.2 );
		$archived = $this->random->random_boolean( 0.2 );
		$creation_date = $this->random->random_date( '-2 months', 'now' );
		$start_date = $this->random->random_date( '-2 months', 'now' );
		$duration = $this->random->custom_rand( 1, 14 );
		$end_date = clone $start_date;
		$end_date->modify( "+{$duration} days" );
		$stack = $this->random->random_stack();

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
		$rand = $this->random->custom_rand( 1, 10 );
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
				$days_to_assign = $this->random->custom_rand( 1, count( $dates ) );
				$random_dates = $this->random->wp_rand_elements( $dates, $days_to_assign );

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

			$comment_ts = $this->random->custom_rand( $first_ts, $last_ts );
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
		$bucket = $this->random->custom_rand( 1, 100 );
		if ( $bucket <= 30 ) {
			return 0;
		} elseif ( $bucket <= 60 ) {
			return 1;
		} elseif ( $bucket <= 85 ) {
			return $this->random->custom_rand( 2, 4 );
		}
		return $this->random->custom_rand( 6, 10 );
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
}
