<?php
/**
 * Read operations for Decker abilities.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Executes task, board, and knowledge-base queries.
 */
class Decker_Ability_Query_Service {

	/**
	 * Task store.
	 *
	 * @var Decker_Ability_Task_Store
	 */
	private $store;

	/**
	 * Input validator.
	 *
	 * @var Decker_Ability_Input_Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Decker_Ability_Task_Store      $store     Task store.
	 * @param Decker_Ability_Input_Validator $validator Input validator.
	 */
	public function __construct( Decker_Ability_Task_Store $store, Decker_Ability_Input_Validator $validator ) {
		$this->store     = $store;
		$this->validator = $validator;
	}

	/**
	 * List visible tasks.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Task collection or error.
	 */
	public function list_tasks( $input ) {
		$input      = $this->normalize_list_input( $input );
		$validation = $this->validate_optional_board( $input );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$query = new WP_Query( $this->build_task_query_arguments( $input ) );

		return array(
			'tasks'       => $this->collect_visible_tasks( $query->posts ),
			'page'        => $input['page'],
			'per_page'    => $input['per_page'],
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Retrieve one task.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Task data or error.
	 */
	public function get_task( $input ) {
		$task_id = isset( $input['task_id'] ) ? absint( $input['task_id'] ) : 0;
		$post    = $this->store->get_task_post( $task_id );

		return is_wp_error( $post ) ? $post : $this->store->format_task( $post );
	}

	/**
	 * List available boards.
	 *
	 * @return array<string, array<int, array<string, mixed>>>|WP_Error Boards or error.
	 */
	public function list_boards() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'decker_board',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		return array( 'boards' => array_map( array( $this, 'format_board' ), $terms ) );
	}

	/**
	 * Search knowledge-base articles.
	 *
	 * @param array $input Ability input.
	 * @return array<string, array<int, array<string, mixed>>>|WP_Error Articles or error.
	 */
	public function search_knowledge_base( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$validation = $this->validate_optional_board( $input );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$articles = array();
		foreach ( Decker_Kb::get_articles( $this->build_knowledge_base_arguments( $input ) ) as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$articles[] = $this->format_article( $post );
			}
		}

		return array( 'articles' => $articles );
	}

	/**
	 * Normalize list input.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed> Normalized input.
	 */
	private function normalize_list_input( $input ): array {
		$input = wp_parse_args(
			is_array( $input ) ? $input : array(),
			array(
				'search'         => '',
				'board_id'       => 0,
				'stack'          => '',
				'status'         => 'publish',
				'page'           => 1,
				'per_page'       => 20,
				'include_hidden' => false,
			)
		);

		$input['search']         = sanitize_text_field( $input['search'] );
		$input['board_id']       = absint( $input['board_id'] );
		$input['stack']          = sanitize_key( $input['stack'] );
		$input['status']         = sanitize_key( $input['status'] );
		$input['page']           = max( 1, absint( $input['page'] ) );
		$input['per_page']       = min( 100, max( 1, absint( $input['per_page'] ) ) );
		$input['include_hidden'] = (bool) $input['include_hidden'];

		return $input;
	}

	/**
	 * Validate an optional board filter.
	 *
	 * @param array $input Ability input.
	 * @return true|WP_Error Validation result.
	 */
	private function validate_optional_board( array $input ) {
		return empty( $input['board_id'] ) ? true : $this->validator->validate_board( absint( $input['board_id'] ) );
	}

	/**
	 * Build task query arguments.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed> Query arguments.
	 */
	private function build_task_query_arguments( array $input ): array {
		$arguments = array(
			'post_type'      => 'decker_task',
			'post_status'    => $input['status'],
			'posts_per_page' => $input['per_page'],
			'paged'          => $input['page'],
			'orderby'        => array(
				'menu_order' => 'ASC',
				'modified'   => 'DESC',
			),
		);

		if ( '' !== $input['search'] ) {
			$arguments['s'] = $input['search'];
		}

		$meta_query = $this->build_meta_query( $input );
		if ( ! empty( $meta_query ) ) {
			$arguments['meta_query'] = $meta_query;
		}

		if ( $input['board_id'] > 0 ) {
			$arguments['tax_query'] = $this->build_board_tax_query( $input['board_id'] );
		}

		return $arguments;
	}

	/**
	 * Build metadata query clauses.
	 *
	 * @param array $input Normalized input.
	 * @return array<int, array<string, mixed>> Metadata query.
	 */
	private function build_meta_query( array $input ): array {
		$meta_query = array();

		if ( '' !== $input['stack'] ) {
			$meta_query[] = array(
				'key'     => 'stack',
				'value'   => $input['stack'],
				'compare' => '=',
			);
		}

		if ( ! $input['include_hidden'] ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => 'hidden',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'hidden',
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		return $meta_query;
	}

	/**
	 * Build a board taxonomy query.
	 *
	 * @param int $board_id Board term ID.
	 * @return array<int, array<string, mixed>> Taxonomy query.
	 */
	private function build_board_tax_query( int $board_id ): array {
		return array(
			array(
				'taxonomy' => 'decker_board',
				'field'    => 'term_id',
				'terms'    => array( $board_id ),
			),
		);
	}

	/**
	 * Collect visible tasks.
	 *
	 * @param WP_Post[] $posts Task posts.
	 * @return array<int, array<string, mixed>> Formatted tasks.
	 */
	private function collect_visible_tasks( array $posts ): array {
		$tasks = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$tasks[] = $this->store->format_task( $post );
			}
		}

		return $tasks;
	}

	/**
	 * Format a board term.
	 *
	 * @param WP_Term $term Board term.
	 * @return array<string, mixed> Board data.
	 */
	private function format_board( WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
		);
	}

	/**
	 * Build knowledge-base query arguments.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed> Query arguments.
	 */
	private function build_knowledge_base_arguments( array $input ): array {
		$arguments = array(
			's'              => sanitize_text_field( $input['search'] ?? '' ),
			'posts_per_page' => isset( $input['limit'] ) ? min( 50, max( 1, absint( $input['limit'] ) ) ) : 10,
			'post_status'    => 'publish',
		);

		if ( ! empty( $input['board_id'] ) ) {
			$arguments['tax_query'] = $this->build_board_tax_query( absint( $input['board_id'] ) );
		}

		return $arguments;
	}

	/**
	 * Format a knowledge-base article.
	 *
	 * @param WP_Post $post Article post.
	 * @return array<string, mixed> Article data.
	 */
	private function format_article( WP_Post $post ): array {
		$board_ids = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );
		$board_id  = ! is_wp_error( $board_ids ) && ! empty( $board_ids ) ? (int) $board_ids[0] : 0;

		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'content'  => (string) $post->post_content,
			'board_id' => $board_id,
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}
}
