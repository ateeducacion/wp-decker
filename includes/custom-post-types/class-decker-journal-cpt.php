<?php
/**
 * Journal Post Type for the Decker Plugin.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Journal_CPT.
 *
 * Handles the Custom Post Type for journals.
 */
class Decker_Journal_CPT {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define Hooks.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta_fields' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'rest_pre_insert_decker_journal', array( $this, 'rest_validate_journal' ), 10, 2 );
	}

	/**
	 * Register the decker_journal post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'               => _x( 'Journal Entries', 'post type general name', 'decker' ),
			'singular_name'      => _x( 'Journal Entry', 'post type singular name', 'decker' ),
			'menu_name'          => _x( 'Journal Entries', 'admin menu', 'decker' ),
			'name_admin_bar'     => _x( 'Journal Entry', 'add new on admin bar', 'decker' ),
			'add_new'            => _x( 'Add New', 'journal entry', 'decker' ),
			'add_new_item'       => __( 'Add New Journal Entry', 'decker' ),
			'new_item'           => __( 'New Journal Entry', 'decker' ),
			'edit_item'          => __( 'Edit Journal Entry', 'decker' ),
			'view_item'          => __( 'View Journal Entry', 'decker' ),
			'all_items'          => __( 'All Journal Entries', 'decker' ),
			'search_items'       => __( 'Search Journal Entries', 'decker' ),
			'not_found'          => __( 'No journal entries found.', 'decker' ),
			'not_found_in_trash' => __( 'No journal entries found in Trash.', 'decker' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=decker_task',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'decker-journals' ),
			'capability_type'    => 'decker_journal',
			'map_meta_cap'       => true,
			'capabilities'       => array(
				'edit_post'          => 'edit_decker_journal',
				'read_post'          => 'read_decker_journal',
				'delete_post'        => 'delete_decker_journal',
				'edit_posts'         => 'edit_decker_journals',
				'edit_others_posts'  => 'edit_others_decker_journals',
				'publish_posts'      => 'publish_decker_journals',
				'read_private_posts' => 'read_private_decker_journals',
			),
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-media-document',
			'supports'           => array( 'title', 'editor', 'author', 'revisions', 'custom-fields', 'excerpt' ),
			'taxonomies'         => array( 'decker_board', 'decker_label' ),
			'show_in_rest'       => true,
			'rest_base'          => 'decker-journals',
		);

		register_post_type( 'decker_journal', $args );
	}

	/**
	 * Register meta fields for the decker_journal post type.
	 */
	public function register_meta_fields() {
		$meta_fields = array(
			'journal_date' => array(
				'type'              => 'string',
				'description'       => __( 'The date of the journal entry in YYYY-MM-DD format.', 'decker' ),
				'sanitize_callback' => array( __CLASS__, 'sanitize_date' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'   => 'string',
						'format' => 'date',
					),
				),
			),
			'journal_users' => array(
				'type'              => 'array',
				'description'       => __( 'List of users associated with the journal.', 'decker' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_integer_array' ),
			),
			'topic' => array(
				'type'              => 'string',
				'description'       => __( 'The main topic of the journal entry.', 'decker' ),
				'sanitize_callback' => 'sanitize_text_field',
				'single'            => true,
				'show_in_rest'      => true,
			),
			'agreements' => array(
				'type'              => 'array',
				'description'       => __( 'List of agreements.', 'decker' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_string_array' ),
			),
			'derived_tasks' => array(
				'type'              => 'array',
				'description'       => __( 'Tasks derived from the journal entry.', 'decker' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'description'      => array(
									'type' => 'string',
								),
								'responsible_team' => array(
									'type' => 'string',
								),
								'task_post_id'     => array(
									'type' => 'integer',
								),
								'task_link'        => array(
									'type'   => 'string',
									'format' => 'uri',
								),
							),
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_derived_tasks' ),
			),
			'notes' => array(
				'type'              => 'array',
				'description'       => __( 'Checklist of notes.', 'decker' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'text'    => array( 'type' => 'string' ),
								'checked' => array( 'type' => 'boolean' ),
							),
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_notes' ),
			),
			'related_task_ids' => array(
				'type'              => 'array',
				'description'       => __( 'List of related task IDs.', 'decker' ),
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_integer_array' ),
			),
		);

		foreach ( $meta_fields as $key => $args ) {
			register_post_meta( 'decker_journal', $key, $args );
		}
	}

	/**
	 * Sanitize a date string.
	 *
	 * @param string $value The date string to sanitize.
	 * @return string|null
	 */
	public static function sanitize_date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = new DateTime( $value );
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Sanitize an array of strings.
	 *
	 * @param array $value The array to sanitize.
	 * @return array
	 */
	public static function sanitize_string_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Sanitize an array of integers.
	 *
	 * @param array $value The array to sanitize.
	 * @return array
	 */
	public static function sanitize_integer_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'absint', $value );
	}

	/**
	 * Sanitize the derived_tasks meta field.
	 *
	 * @param array $tasks The array of tasks to sanitize.
	 * @return array
	 */
	public static function sanitize_derived_tasks( $tasks ) {
		if ( ! is_array( $tasks ) ) {
			return array();
		}
		$sanitized_tasks = array();
		foreach ( $tasks as $task ) {
			$sanitized_task = array();
			if ( isset( $task['description'] ) ) {
				$sanitized_task['description'] = sanitize_text_field( $task['description'] );
			}
			if ( isset( $task['responsible_team'] ) ) {
				$sanitized_task['responsible_team'] = sanitize_text_field( $task['responsible_team'] );
			}
			if ( isset( $task['task_post_id'] ) ) {
				$sanitized_task['task_post_id'] = absint( $task['task_post_id'] );
			}
			if ( isset( $task['task_link'] ) ) {
				$sanitized_task['task_link'] = esc_url_raw( $task['task_link'] );
			}
			$sanitized_tasks[] = $sanitized_task;
		}
		return $sanitized_tasks;
	}

	/**
	 * Sanitize the notes meta field.
	 *
	 * @param array $notes The array of notes to sanitize.
	 * @return array
	 */
	public static function sanitize_notes( $notes ) {
		if ( ! is_array( $notes ) ) {
			return array();
		}
		$sanitized_notes = array();
		foreach ( $notes as $note ) {
			$sanitized_note = array();
			if ( isset( $note['text'] ) ) {
				$sanitized_note['text'] = sanitize_text_field( $note['text'] );
			}
			if ( isset( $note['checked'] ) ) {
				$sanitized_note['checked'] = rest_sanitize_boolean( $note['checked'] );
			}
			$sanitized_notes[] = $sanitized_note;
		}
		return $sanitized_notes;
	}

	/**
	 * Register custom REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'decker/v1',
			'/journals',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_journal_by_board_and_date' ),
				'permission_callback' => array( $this, 'can_read_journals' ),
				'args'                => array(
					'board' => array(
						'required'    => true,
						'description' => __( 'Board ID or slug.', 'decker' ),
						'type'        => 'string',
					),
					'date'  => array(
						'required'    => true,
						'description' => __( 'Date in YYYY-MM-DD format.', 'decker' ),
						'type'        => 'string',
						'format'      => 'date',
					),
				),
			)
		);
	}

	/**
	 * Get a journal entry by board and date.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_journal_by_board_and_date( $request ) {
		$board_arg = $request->get_param( 'board' );
		$date = $request->get_param( 'date' );

		$board_term = is_numeric( $board_arg ) ? get_term( $board_arg, 'decker_board' ) : get_term_by( 'slug', $board_arg, 'decker_board' );
		if ( ! $board_term || is_wp_error( $board_term ) ) {
			return new WP_Error( 'rest_board_not_found', __( 'Board not found.', 'decker' ), array( 'status' => 404 ) );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'decker_journal',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => 'journal_date',
				'meta_value'     => $date,
				'tax_query'      => array(
					array(
						'taxonomy' => 'decker_board',
						'field'    => 'term_id',
						'terms'    => $board_term->term_id,
					),
				),
			)
		);

		if ( ! $query->have_posts() ) {
			return new WP_Error( 'rest_journal_not_found', __( 'Journal entry not found.', 'decker' ), array( 'status' => 404 ) );
		}

		$post = $query->posts[0];
		$controller = new WP_REST_Posts_Controller( 'decker_journal' );
		$response = $controller->prepare_item_for_response( $post, $request );

		return $response;
	}

	/**
	 * Check if the user can read journals.
	 *
	 * @return bool
	 */
	public function can_read_journals() {
		return current_user_can( 'read' );
	}

	/**
	 * Validate journal data from a REST request.
	 *
	 * @param stdClass        $prepared_post An object representing a single post prepared for inserting or updating.
	 * @param WP_REST_Request $request       The request object.
	 * @return WP_Error|true
	 */
	public function rest_validate_journal( $prepared_post, $request ) {
		$board_id = 0;
		if ( ! empty( $request['decker_board'] ) ) {
			$board_id = absint( $request['decker_board'] );
		}

		$journal_date = '';
		if ( ! empty( $request['meta']['journal_date'] ) ) {
			$journal_date = sanitize_text_field( $request['meta']['journal_date'] );
		}

		// 1. Board is required.
		if ( empty( $board_id ) ) {
			return new WP_Error( 'board_required', __( 'A board is required to save a journal entry.', 'decker' ), array( 'status' => 400 ) );
		}

		// 2. Uniqueness (Board + Date).
		$query_args = array(
			'post_type'      => 'decker_journal',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
			'meta_key'       => 'journal_date',
			'meta_value'     => $journal_date,
			'tax_query'      => array(
				array(
					'taxonomy' => 'decker_board',
					'field'    => 'term_id',
					'terms'    => $board_id,
				),
			),
			'posts_per_page' => 1,
		);

		if ( ! empty( $request['id'] ) ) {
			$query_args['post__not_in'] = array( $request['id'] );
		}

		$query = new WP_Query( $query_args );

		if ( $query->have_posts() ) {
			return new WP_Error( 'duplicate_journal', __( 'A journal entry for this board and date already exists.', 'decker' ), array( 'status' => 400 ) );
		}

		return $prepared_post;
	}

	/**
	 * Get journal entries.
	 *
	 * @param array $args WP_Query arguments.
	 * @return array Array of post objects.
	 */
	public static function get_journals( $args = array() ) {
		$default_args = array(
			'post_type'      => 'decker_journal',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'meta_value',
			'meta_key'       => 'journal_date',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $default_args );
		return get_posts( $args );
	}
}

// Instantiate the class.
if ( class_exists( 'Decker_Journal_CPT' ) ) {
	new Decker_Journal_CPT();
}
