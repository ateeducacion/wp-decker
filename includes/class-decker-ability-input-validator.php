<?php
/**
 * Input normalization and validation for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and validates ability input before domain writes.
 */
class Decker_Ability_Input_Validator {

	/**
	 * Supported task stacks.
	 *
	 * @var string[]
	 */
	private const STACKS = array( 'to-do', 'in-progress', 'done' );

	/**
	 * Build validated state for a new task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Validated task state or error.
	 */
	public function prepare_create_state( array $input ) {
		$author = $this->resolve_author( $input );
		if ( is_wp_error( $author ) ) {
			return $author;
		}

		$defaults = array(
			'title'               => '',
			'description'         => '',
			'stack'               => 'to-do',
			'board_id'            => 0,
			'max_priority'        => false,
			'due_date'            => '',
			'author_id'           => $author,
			'responsible_user_id' => $author,
			'hidden'              => false,
			'assignee_ids'        => array(),
			'label_ids'           => array(),
			'archived'            => false,
		);

		$state              = wp_parse_args( $input, $defaults );
		$state['author_id'] = $author;

		return $this->normalize_and_validate_state( $state );
	}

	/**
	 * Merge selected input fields into existing task state.
	 *
	 * @param array $state   Existing task state.
	 * @param array $input   Ability input.
	 * @param int   $task_id Task ID.
	 * @return array<string, mixed>|WP_Error Validated task state or error.
	 */
	public function merge_task_state( array $state, array $input, int $task_id ) {
		$fields = array(
			'title',
			'description',
			'stack',
			'board_id',
			'max_priority',
			'due_date',
			'author_id',
			'responsible_user_id',
			'hidden',
			'assignee_ids',
			'label_ids',
			'archived',
		);

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$state[ $field ] = $input[ $field ];
			}
		}

		$author_check = $this->validate_author_change( $state, $task_id );
		if ( is_wp_error( $author_check ) ) {
			return $author_check;
		}

		return $this->normalize_and_validate_state( $state );
	}

	/**
	 * Validate a board ID.
	 *
	 * @param int $board_id Board term ID.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	public function validate_board( int $board_id ) {
		if ( $board_id < 1 || ! term_exists( $board_id, 'decker_board' ) ) {
			return new WP_Error( 'decker_invalid_board', 'The requested board does not exist.', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Resolve the author for a new task.
	 *
	 * @param array $input Ability input.
	 * @return int|WP_Error Author ID or error.
	 */
	private function resolve_author( array $input ) {
		$current_user_id = get_current_user_id();
		$author_id       = isset( $input['author_id'] ) ? absint( $input['author_id'] ) : $current_user_id;

		if ( $author_id !== $current_user_id && ! current_user_can( 'edit_others_posts' ) ) {
			return $this->permission_error( 'decker_author_forbidden', 'You are not allowed to create tasks for another author.' );
		}

		return $author_id;
	}

	/**
	 * Validate a requested author change.
	 *
	 * @param array $state   Task state.
	 * @param int   $task_id Task ID.
	 * @return true|WP_Error True when allowed, otherwise an error.
	 */
	private function validate_author_change( array $state, int $task_id ) {
		$stored_author = (int) get_post_field( 'post_author', $task_id );
		$new_author    = absint( $state['author_id'] );

		if ( $stored_author !== $new_author && ! current_user_can( 'edit_others_posts' ) ) {
			return $this->permission_error( 'decker_author_forbidden', 'You are not allowed to change the task author.' );
		}

		return true;
	}

	/**
	 * Normalize and validate complete task state.
	 *
	 * @param array $state Task state.
	 * @return array<string, mixed>|WP_Error Validated state or error.
	 */
	private function normalize_and_validate_state( array $state ) {
		$state = $this->normalize_state( $state );

		$validation = $this->validate_state( $state );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$due_date = $this->parse_due_date( $state['due_date'] );
		if ( is_wp_error( $due_date ) ) {
			return $due_date;
		}

		$state['due_date_object'] = $due_date;
		return $state;
	}

	/**
	 * Normalize task state values.
	 *
	 * @param array $state Task state.
	 * @return array<string, mixed> Normalized state.
	 */
	private function normalize_state( array $state ): array {
		$state['title']               = sanitize_text_field( $state['title'] );
		$state['description']         = wp_kses( $state['description'], Decker::get_allowed_tags() );
		$state['stack']               = sanitize_key( $state['stack'] );
		$state['board_id']            = absint( $state['board_id'] );
		$state['max_priority']        = (bool) $state['max_priority'];
		$state['due_date']            = (string) $state['due_date'];
		$state['author_id']           = absint( $state['author_id'] );
		$state['responsible_user_id'] = absint( $state['responsible_user_id'] );
		$state['hidden']              = (bool) $state['hidden'];
		$state['assignee_ids']        = $this->normalize_ids( $state['assignee_ids'] );
		$state['label_ids']           = $this->normalize_ids( $state['label_ids'] );
		$state['archived']            = (bool) $state['archived'];
		return $state;
	}

	/**
	 * Validate normalized task state.
	 *
	 * @param array $state Task state.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	private function validate_state( array $state ) {
		if ( '' === $state['title'] ) {
			return new WP_Error( 'decker_invalid_title', 'The task title is required.', array( 'status' => 400 ) );
		}

		if ( ! in_array( $state['stack'], self::STACKS, true ) ) {
			return new WP_Error( 'decker_invalid_stack', 'The supplied task stack is invalid.', array( 'status' => 400 ) );
		}

		$board_validation = $this->validate_board( $state['board_id'] );
		if ( is_wp_error( $board_validation ) ) {
			return $board_validation;
		}

		$user_ids = array_merge( array( $state['author_id'], $state['responsible_user_id'] ), $state['assignee_ids'] );
		$user_validation = $this->validate_user_ids( $user_ids );
		if ( is_wp_error( $user_validation ) ) {
			return $user_validation;
		}

		return $this->validate_term_ids( $state['label_ids'], 'decker_label' );
	}

	/**
	 * Parse an ISO date.
	 *
	 * @param string $value Date in Y-m-d format or an empty string.
	 * @return DateTime|null|WP_Error Parsed date, null, or error.
	 */
	private function parse_due_date( string $value ) {
		if ( '' === $value ) {
			return null;
		}

		$date   = DateTime::createFromFormat( '!Y-m-d', $value );
		$errors = DateTime::getLastErrors();

		if ( false === $date ) {
			return $this->invalid_due_date_error();
		}

		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return $this->invalid_due_date_error();
		}

		if ( $date->format( 'Y-m-d' ) !== $value ) {
			return $this->invalid_due_date_error();
		}

		return $date;
	}

	/**
	 * Validate user IDs.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	private function validate_user_ids( array $user_ids ) {
		foreach ( $this->normalize_ids( $user_ids ) as $user_id ) {
			if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
				return new WP_Error( 'decker_invalid_user', 'One or more supplied user IDs are invalid.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Validate taxonomy term IDs.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy name.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	private function validate_term_ids( array $term_ids, string $taxonomy ) {
		foreach ( $this->normalize_ids( $term_ids ) as $term_id ) {
			if ( $term_id < 1 || ! term_exists( $term_id, $taxonomy ) ) {
				return new WP_Error( 'decker_invalid_term', 'One or more supplied taxonomy term IDs are invalid.', array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Normalize a list of IDs.
	 *
	 * @param mixed $ids Supplied IDs.
	 * @return int[] Unique positive integer IDs.
	 */
	private function normalize_ids( $ids ): array {
		return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Build an invalid due-date error.
	 *
	 * @return WP_Error Invalid date error.
	 */
	private function invalid_due_date_error(): WP_Error {
		return new WP_Error( 'decker_invalid_due_date', 'The due date must be a valid date in Y-m-d format.', array( 'status' => 400 ) );
	}

	/**
	 * Build a permission error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error Permission error.
	 */
	private function permission_error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 403 ) );
	}
}
