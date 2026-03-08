<?php
/**
 * Protects REST API endpoints for comments on custom post types.
 *
 * This class adds authentication checks to the WordPress REST API for comments
 * associated with protected custom post types, preventing unauthenticated users
 * from reading or writing comments on these posts.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
/**
 * Class Decker_REST_Comment_Protection
 *
 * Adds REST API protections for reading and writing comments associated with
 * protected custom post types (e.g., decker_task). Ensures unauthenticated
 * users cannot list, read, create, update, or delete such comments via REST.
 */
class Decker_REST_Comment_Protection {

	/**
	 * The post types to protect.
	 *
	 * @access private
	 * @var array
	 */
	private $protected_post_types;

	/**
	 * Initialize the class and register hooks on rest_api_init for correct timing.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_comment_protection_hooks' ) );
	}

	/**
	 * Register the comment protection hooks during REST initialization.
	 *
	 * Ensures hooks are only added when REST routes are loaded and avoids relying on REST_REQUEST timing.
	 */
	public function register_rest_comment_protection_hooks() {
		// Get protected post types once.
		$this->protected_post_types = $this->get_protected_post_types();

		if ( empty( $this->protected_post_types ) ) {
			return;
		}

		add_filter( 'rest_comment_query', array( $this, 'prepare_comment_collection_query' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( $this, 'protect_single_comment_access' ), 10, 3 );
		add_filter( 'rest_pre_insert_comment', array( $this, 'protect_comment_creation' ), 10, 2 );
		add_filter( 'rest_authentication_errors', array( $this, 'protect_comment_modification' ) );
	}

	/**
	 * Get the list of protected post types.
	 *
	 * @return array The list of protected post types.
	 */
	private function get_protected_post_types() {
		/**
		 * Filters the list of post types whose comments are protected from unauthenticated access.
		 *
		 * @param array $post_types An array of post type slugs. Default: ['decker_task'].
		 */
		return apply_filters( 'decker/protected_comment_post_types', array( 'decker_task' ) );
	}

	/**
	 * Prepares to filter the comment collection query for unauthenticated users.
	 *
	 * This function hooks into `comments_clauses` only when a REST API request for comments
	 * is being processed, ensuring the filter is not applied globally.
	 *
	 * @param array           $args    Request arguments.
	 * @param WP_REST_Request $request The request object.
	 * @return array The original arguments.
	 */
	public function prepare_comment_collection_query( $args, $request ) {
		if ( ! is_user_logged_in() ) {
			add_filter( 'comments_clauses', array( $this, 'filter_comment_collection_query' ) );
		}
		return $args;
	}

	/**
	 * Exclude comments from protected post types in collection queries for unauthenticated users.
	 *
	 * This function is hooked dynamically by `prepare_comment_collection_query`.
	 *
	 * @param array $clauses The clauses for the comments query.
	 * @return array The modified clauses.
	 */
	public function filter_comment_collection_query( $clauses ) {
		// Remove the filter immediately to prevent it from affecting other queries.
		remove_filter( 'comments_clauses', array( $this, 'filter_comment_collection_query' ) );

		global $wpdb;

		// Add a JOIN clause to link comments to the posts table.
		$clauses['join'] .= " LEFT JOIN {$wpdb->posts} p ON p.ID = {$wpdb->comments}.comment_post_ID";

		// Add a WHERE clause to exclude comments from protected post types.
		$where_clause = $wpdb->prepare(
			' AND (p.post_type IS NULL OR p.post_type NOT IN (' . implode( ', ', array_fill( 0, count( $this->protected_post_types ), '%s' ) ) . '))',
			$this->protected_post_types
		);

		// Also check for comments without a parent post (comment_post_ID = 0) and allow them.
		$clauses['where'] .= " AND ({$wpdb->comments}.comment_post_ID = 0 OR " . substr( trim( $where_clause ), 4 ) . ')';

		return $clauses;
	}

	/**
	 * Block access to single comments on protected post types for unauthenticated users.
	 *
	 * @param mixed           $result  Dispatch result, will be used if not null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed A WP_Error if access is denied, otherwise the original $result.
	 */
	public function protect_single_comment_access( $result, $server, $request ) {
		if ( is_user_logged_in() ) {
			return $result;
		}

		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		// Block unauthenticated creation on protected post types.
		if ( 0 === strpos( $route, '/wp/v2/comments' ) && 'POST' === $method ) {
			$post_id = (int) $request->get_param( 'post' );
			if ( $post_id && in_array( get_post_type( $post_id ), $this->protected_post_types, true ) ) {
				return new WP_Error(
					'rest_cannot_create_comment',
					__( 'You are not authorized to access this resource.', 'decker' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
			return $result;
		}

		// Handle single comment routes.
		if ( preg_match( '#^/wp/v2/comments/(?P<id>\d+)#', $route, $matches ) ) {
			$comment_id = (int) $matches['id'];
			$comment    = get_comment( $comment_id );

			if ( ! $comment ) {
				return $result;
			}

			$is_protected = in_array( get_post_type( $comment->comment_post_ID ), $this->protected_post_types, true );
			if ( ! $is_protected ) {
				return $result;
			}

			if ( 'GET' === $method ) {
				return new WP_Error(
					'rest_forbidden_comment',
					__( 'You are not authorized to access this resource.', 'decker' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			if ( in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				return new WP_Error(
					'rest_cannot_edit_comment',
					__( 'You are not authorized to access this resource.', 'decker' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return $result;
	}

	/**
	 * Prevent unauthenticated users from creating comments on protected post types.
	 *
	 * @param array|WP_Error  $prepared_comment An array of comment data or a WP_Error.
	 * @param WP_REST_Request $request          The request object.
	 * @return array|WP_Error The comment data or a WP_Error if denied.
	 */
	public function protect_comment_creation( $prepared_comment, $request ) {
		if ( is_user_logged_in() || is_wp_error( $prepared_comment ) ) {
			return $prepared_comment;
		}

		$post_id = (int) $request['post'];
		if ( $post_id && in_array( get_post_type( $post_id ), $this->protected_post_types, true ) ) {
			return new WP_Error(
				'rest_cannot_create_comment',
				__( 'You are not authorized to access this resource.', 'decker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $prepared_comment;
	}

	/**
	 * Prevent unauthenticated users from updating or deleting comments on protected post types.
	 *
	 * @param WP_Error|null|true $result WP_Error if authentication error, null if authentication method wasn't used, true if authentication succeeded.
	 * @return WP_Error|null|true
	 */
	public function protect_comment_modification( $result ) {
		// Let existing errors through, and allow authenticated users.
		if ( is_user_logged_in() || is_wp_error( $result ) ) {
			return $result;
		}

		// This hook runs on all authenticated REST requests. We must check if this is a comment modification request.
		$request_uri = ! empty( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! preg_match( '#/wp/v2/comments/(?P<id>\d+)#', $request_uri, $matches ) ) {
			return $result;
		}

		$request_method = ! empty( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( ! in_array( $request_method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $result;
		}

		$comment_id = (int) $matches['id'];
		$comment    = get_comment( $comment_id );

		if ( $comment && in_array( get_post_type( $comment->comment_post_ID ), $this->protected_post_types, true ) ) {
			return new WP_Error(
				'rest_cannot_edit_comment',
				__( 'You are not authorized to access this resource.', 'decker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $result;
	}
}

// Instantiate the protection class to ensure hooks are registered during REST requests.
if ( class_exists( 'Decker_REST_Comment_Protection' ) ) {
	new Decker_REST_Comment_Protection();
}
