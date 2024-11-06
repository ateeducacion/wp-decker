<?php
/**
 * Interacts with WordPress data to get information about boards and their stacks.
 *
 * @package decker
 *
 * Configuration, fetching board lists, and handling stacks and cache.
 * Returns the information in JSON format.
 */

// Prevent direct file access for security.
defined( 'ABSPATH' ) || exit;


/**
 * Adds a unique user to the users array.
 *
 * @param array   $users     Reference to the users array where the new user will be added.
 * @param WP_User $new_user  WP_User object of the new user to add.
 */
function decker_add_unique_user( &$users, $new_user ) {
	if ( ! isset( $users[ $new_user->ID ] ) ) {
		$users[ $new_user->ID ] = array(
			'id'      => $new_user->ID,
			'uuid'      => $new_user->ID,
			'nickname'  => $new_user->nickname,
			'full_name' => $new_user->display_name,
			'today'     => array(),
			'color'     => get_user_color( $new_user ),
			'avatar'    => get_avatar_url(
				$new_user->user_email,
				array(
					'size' => 32,
					'default' => 'robohash',
				)
			),
		);
	}
}

/**
 * Generates a color based on the username if the user color is not set.
 *
 * @param WP_User $user WP_User object of the user.
 * @return string The color for the user.
 */
function get_user_color( $user ) {
	$color = get_the_author_meta( 'decker_color', $user->ID );
	if ( empty( $color ) ) {
		$hash = md5( strtolower( trim( $user->user_login ) ) );
		$color = '#' . substr( $hash, 0, 6 );
	}
	return $color;
}

/**
 * Adds a unique label to the labels array.
 *
 * @param array $labels     Reference to the labels array where the new label will be added.
 * @param array $new_label  Data of the new label to add.
 */
function decker_add_unique_label( &$labels, $new_label ) {
	if ( ! isset( $labels[ $new_label->term_id ] ) ) {
		$labels[ $new_label->term_id ] = array(
			'id'       => $new_label->term_id,
			'board_id' => $new_label->term_group,
			'title'    => $new_label->name,
			'color'    => get_term_meta( $new_label->term_id, 'term-color', true ),
		);
	}
}

/**
 * Processes and structures the data of a collection of card stacks, grouping the relevant information
 * into a defined structure, and also updates the lists of unique users and unique labels based
 * on the assignments and labels of the cards respectively.
 *
 * @param array &$structured_data Reference to the array that stores the resulting structured data,
 *                                including details of each card such as ID, board ID, processed stack title,
 *                                assigned users, labels, card title, order in the stack, and remaining time.
 * @param array &$unique_users    Reference to the array that keeps track of unique users assigned to the cards.
 *                                This array is updated with each user found in the processed cards.
 * @param array &$unique_labels   Reference to the array that keeps track of unique labels found in the cards.
 *                                This array is updated with each label found in the processed cards.
 * @param array $tasks            Array of tasks containing the cards to process. Each task includes a board ID,
 *                                a title, and a set of cards, each with its own details such as ID, assigned users,
 *                                labels, title, and due date.
 *
 * The function iterates over each task and its cards, building an array of structured data with the relevant information
 * of each card and updating the arrays of unique users and labels. It uses helper functions to process
 * stack titles and add users and labels to the unique records.
 */
function process_tasks( &$structured_data, &$unique_users, &$unique_labels, $tasks ) {

	$today = gmdate( 'Y-m-d' ); // Get today's date.

	foreach ( $tasks as $task ) {
		$assigned_users = get_post_meta( $task->ID, 'assigned_users', true );
		if ( ! $assigned_users ) {
			$assigned_users = array();
		}
		$labels         = wp_get_post_terms( $task->ID, 'decker_label' );

		// Add card.
		$structured_data['cards'][] = array(
			'id'             => $task->ID,
			'board_id'       => wp_get_post_terms( $task->ID, 'decker_board', array( 'fields' => 'ids' ) )[0],
			'stack'          => get_post_meta( $task->ID, 'stack', true ),
			'stack_id'       => get_post_meta( $task->ID, 'stack_id', true ),
			'assigned_users' => $assigned_users,
			'labels'         => array_map(
				function ( $label ) {
					return $label->term_id; },
				$labels
			),
			'title'          => $task->post_title,
			'order'          => get_post_meta( $task->ID, 'order', true ),
			'remaining_time' => get_post_meta( $task->ID, 'duedate', true ),
			'comments_count' => get_post_meta( $task->ID, 'comments_count', true ),
			'created_at'     => $task->post_date,
			'last_modified'  => $task->post_modified,
			'max_priority'   => get_post_meta( $task->ID, 'max_priority', true ),
		);

		// Add unique users and labels.
		foreach ( $assigned_users as $user_id ) {
			$user = get_user_by( 'ID', $user_id );
			if ( $user ) {
				decker_add_unique_user( $unique_users, $user );

				// Check if the task is assigned for today.
				$relations = get_post_meta( $task->ID, '_user_date_relations', true );
				if ( is_array( $relations ) ) {
					foreach ( $relations as $relation ) {
						if ( $relation['user_id'] == $user_id && $relation['date'] == $today ) {
							$unique_users[ $user->ID ]['today'][] = $task->ID;
						}
					}
				}
			}
		}
		foreach ( $labels as $label ) {
			decker_add_unique_label( $unique_labels, $label );
		}
	}
}



/**
 * Gets the boards, processes them, and returns a JSON file.
 *
 * @param bool $is_archived Indicates whether to fetch archived tasks.
 * @return void
 */
function process_deck_api( $is_archived ) {

	write_log( '-------------------' );
	write_log( 'Request start' );
	write_log( '-------------------' );

	// Prevent page caching.
	header( 'Cache-Control: no-cache, no-store, must-revalidate' ); // HTTP 1.1.
	header( 'Pragma: no-cache' ); // HTTP 1.0.
	header( 'Expires: 0' ); // Proxies.

	global $global_etag; // Variable global para almacenar el ETag.
	$global_etag = '';

	// Get boards (decker_board).
	$boards = get_terms(
		array(
			'taxonomy' => 'decker_board',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $boards ) ) {
		$error_message = __( 'Error fetching boards.', 'decker' );
		write_log( $error_message );
		wp_send_json_error( $error_message );
	}

	// New array to store the desired structure.
	$structured_data = array(
		'users'  => array(),
		'boards' => array(),
		'labels' => array(),
		'stacks' => array( 'Hay que', 'En progreso', 'En Revisión', 'Completada' ),
		'cards'  => array(),
		'etag'   => $global_etag, // Almacenar el ETag en el JSON.
	);

	// Initialize associative arrays.
	$unique_users  = array();
	$unique_labels = array();

	foreach ( $boards as $board ) {
		$board_id = $board->term_id;

		// Add board details.
		$structured_data['boards'][] = array(
			'id'                  => $board_id,
			'title'               => $board->name,
			'slug'                => $board->slug,
			'color'               => get_term_meta( $board_id, 'term-color', true ),
			'stack_todo_id'       => 0,
			'stack_inprogress_id' => 0,
			'stack_inreview_id'   => 0,
			'stack_completed_id'  => 0,
		);

		// Get tasks related to this board.
		$args = array(
			'post_type'      => 'decker_task',
			'post_status'    => $is_archived ? 'trash' : 'publish',
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'decker_board',
					'field'    => 'term_id',
					'terms'    => $board_id,
				),
			),
		);

		$tasks = get_posts( $args );

		$structured_data['stacks'];

		process_tasks( $structured_data, $unique_users, $unique_labels, $tasks );
	}

	// Convert associative arrays to indexed lists.
	$structured_data['users']  = array_values( $unique_users );
	$structured_data['labels'] = array_values( $unique_labels );

	// Sort elements.
	// Sort users by full_name.
	usort(
		$structured_data['users'],
		function ( $a, $b ) {
			return strcmp( $a['full_name'], $b['full_name'] );
		}
	);

	// Sort boards by title.
	usort(
		$structured_data['boards'],
		function ( $a, $b ) {
			return strcmp( $a['title'], $b['title'] );
		}
	);

	// Sort labels by title.
	usort(
		$structured_data['labels'],
		function ( $a, $b ) {
			return strcmp( $a['title'], $b['title'] );
		}
	);

	write_log( '-------------------' );
	write_log( 'Request end' );
	write_log( '-------------------' );

	// Return the information in JSON format.
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $structured_data );
	exit;
}
