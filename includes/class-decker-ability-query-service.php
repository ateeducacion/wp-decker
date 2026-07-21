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
	 * Maximum candidate tasks scanned when hidden tasks are included.
	 *
	 * The include_hidden path resolves per-user visibility in PHP (the rule
	 * cannot be expressed in SQL), so it is bounded to keep this opt-in listing
	 * from loading an unbounded board; the result is flagged truncated at the cap.
	 *
	 * @var int
	 */
	private const MAX_HIDDEN_SCAN = 2000;

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
	 * Task access rules.
	 *
	 * @var Decker_Ability_Task_Access
	 */
	private $access;

	/**
	 * Constructor.
	 *
	 * @param Decker_Ability_Task_Store      $store     Task store.
	 * @param Decker_Ability_Input_Validator $validator Input validator.
	 * @param Decker_Ability_Task_Access     $access    Task access rules.
	 */
	public function __construct( Decker_Ability_Task_Store $store, Decker_Ability_Input_Validator $validator, Decker_Ability_Task_Access $access ) {
		$this->store     = $store;
		$this->validator = $validator;
		$this->access    = $access;
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

		return $input['include_hidden'] ? $this->list_including_hidden( $input ) : $this->list_default( $input );
	}

	/**
	 * List tasks for the default (no hidden) case.
	 *
	 * Hidden tasks are excluded in SQL and Decker has no board ACL, so the query
	 * returns exactly the visible set and the database paginates and counts it —
	 * the whole board is never loaded into PHP.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed> Task collection.
	 */
	private function list_default( array $input ): array {
		$arguments                   = $this->build_task_query_arguments( $input );
		$arguments['posts_per_page'] = $input['per_page'];
		$arguments['paged']          = $input['page'];

		$query = new WP_Query( $arguments );

		return $this->format_collection( $input, $query->posts, (int) $query->found_posts, (int) $query->max_num_pages, false );
	}

	/**
	 * List tasks when hidden tasks are requested.
	 *
	 * The "author/responsible/assignee may see their own hidden task" rule cannot
	 * be expressed in SQL, so visibility is resolved in PHP over a bounded set of
	 * candidates; totals and pages describe only the tasks this user may see.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed> Task collection.
	 */
	private function list_including_hidden( array $input ): array {
		$arguments                   = $this->build_task_query_arguments( $input );
		$arguments['posts_per_page'] = self::MAX_HIDDEN_SCAN;
		$arguments['no_found_rows']  = true;

		$query     = new WP_Query( $arguments );
		$truncated = count( $query->posts ) >= self::MAX_HIDDEN_SCAN;

		$visible = array_filter(
			$query->posts,
			function ( $post ) {
				return $post instanceof WP_Post && $this->access->is_visible_in_list( $post, true );
			}
		);

		$total  = count( $visible );
		$offset = ( $input['page'] - 1 ) * $input['per_page'];
		$page   = array_slice( $visible, $offset, $input['per_page'] );

		return $this->format_collection( $input, $page, $total, (int) ceil( $total / $input['per_page'] ), $truncated );
	}

	/**
	 * Shape a task-collection response.
	 *
	 * @param array     $input       Normalized input.
	 * @param WP_Post[] $posts       Posts to format for the current page.
	 * @param int       $total       Total accessible tasks.
	 * @param int       $total_pages Total pages.
	 * @param bool      $truncated   Whether the candidate scan hit its cap.
	 * @return array<string, mixed> Task collection.
	 */
	private function format_collection( array $input, array $posts, int $total, int $total_pages, bool $truncated ): array {
		$collection = array(
			'tasks'       => array_map( array( $this->store, 'format_task' ), $posts ),
			'page'        => $input['page'],
			'per_page'    => $input['per_page'],
			'total'       => $total,
			'total_pages' => $total_pages,
		);

		if ( $truncated ) {
			$collection['truncated'] = true;
		}

		return $collection;
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
	 * Returns summaries (title, excerpt, board, modified) so a text search stays
	 * bounded in size; the full body is fetched one article at a time through
	 * get_knowledge_article().
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
				$articles[] = $this->format_article_summary( $post );
			}
		}

		return array( 'articles' => $articles );
	}

	/**
	 * Retrieve one knowledge-base article, including its full content.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>|WP_Error Article data or error.
	 */
	public function get_knowledge_article( $input ) {
		$article_id = isset( $input['article_id'] ) ? absint( $input['article_id'] ) : 0;
		$post       = $article_id > 0 ? get_post( $article_id ) : null;

		if ( ! $post instanceof WP_Post || 'decker_kb' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'decker_article_not_found',
				__( 'The requested knowledge-base article was not found.', 'decker' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error(
				'decker_article_forbidden',
				__( 'You are not allowed to access this article.', 'decker' ),
				array( 'status' => 403 )
			);
		}

		return $this->format_article( $post );
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
	 * Build the shared task-query filters (no pagination).
	 *
	 * Callers add their own paging: the default listing paginates in SQL, while
	 * the include_hidden listing caps the scan and filters in PHP. Hidden tasks
	 * are excluded in SQL for the default case — where they are never visible —
	 * so the database does not return rows only to be discarded.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed> Query arguments.
	 */
	private function build_task_query_arguments( array $input ): array {
		$arguments = array(
			'post_type'              => 'decker_task',
			'post_status'            => $input['status'],
			// Full posts with the meta cache primed so the visibility pass and page
			// formatting read from cache; terms are only needed per page.
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'orderby'                => array(
				'menu_order' => 'ASC',
				'modified'   => 'DESC',
			),
		);

		if ( '' !== $input['search'] ) {
			$arguments['s'] = $input['search'];
		}

		$meta_query = array();

		if ( '' !== $input['stack'] ) {
			$meta_query[] = array(
				'key'     => 'stack',
				'value'   => $input['stack'],
				'compare' => '=',
			);
		}

		if ( ! $input['include_hidden'] ) {
			// Hidden tasks are never returned by the default listing, so exclude
			// them in SQL; is_visible_in_list() then only re-checks candidates.
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

		if ( ! empty( $meta_query ) ) {
			$arguments['meta_query'] = $meta_query;
		}

		if ( $input['board_id'] > 0 ) {
			$arguments['tax_query'] = $this->build_board_tax_query( $input['board_id'] );
		}

		return $arguments;
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
	 * Format a knowledge-base article summary (no full body).
	 *
	 * @param WP_Post $post Article post.
	 * @return array<string, mixed> Article summary.
	 */
	private function format_article_summary( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'excerpt'  => $this->article_excerpt( $post ),
			'board_id' => $this->article_board_id( $post ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * Format a knowledge-base article, including its full content.
	 *
	 * @param WP_Post $post Article post.
	 * @return array<string, mixed> Article data.
	 */
	private function format_article( WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'content'  => (string) $post->post_content,
			'board_id' => $this->article_board_id( $post ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * Get an article's first board ID.
	 *
	 * @param WP_Post $post Article post.
	 * @return int Board term ID or zero.
	 */
	private function article_board_id( WP_Post $post ): int {
		$board_ids = wp_get_post_terms( $post->ID, 'decker_board', array( 'fields' => 'ids' ) );

		return ! is_wp_error( $board_ids ) && ! empty( $board_ids ) ? (int) $board_ids[0] : 0;
	}

	/**
	 * Build a bounded plain-text excerpt for an article.
	 *
	 * @param WP_Post $post Article post.
	 * @return string Excerpt.
	 */
	private function article_excerpt( WP_Post $post ): string {
		$source = '' !== (string) $post->post_excerpt ? $post->post_excerpt : $post->post_content;

		return wp_trim_words( wp_strip_all_tags( (string) $source ), 55 );
	}
}
