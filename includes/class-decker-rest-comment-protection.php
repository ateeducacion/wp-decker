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

		// The classic global comments feed (/comments/feed/) is served by WP_Query, not REST.
		// Protect it independently of the REST request lifecycle.
		add_filter( 'comment_feed_where', array( $this, 'filter_comment_feed_where' ), 10, 2 );
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
		return apply_filters( 'decker/protected_comment_post_types', array( 'decker_task', 'decker_kb' ) );
	}

	/**
	 * Check whether the current user may reach Decker's protected post types at all.
	 *
	 * This mirrors the capability the REST guards of `decker_kb`, `tasks` and
	 * `decker_event` require, so a comment is never easier to read than the post
	 * it belongs to. Being logged in is not enough: a subscriber has no access to
	 * a task, and therefore none to its comments either.
	 *
	 * @return bool True when the user may access Decker content.
	 */
	private function can_access_protected_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Check whether the current user may read the comments of a given post.
	 *
	 * Adds the per-post checks on top of the collection-level capability, so a post
	 * the user cannot read (a draft, a private or a password-protected one) does
	 * not expose its comments, and neither does a task hidden from them.
	 *
	 * @param int $post_id Post the comment belongs to.
	 * @return bool True when the user may read that post's comments.
	 */
	private function can_access_protected_post( $post_id ) {
		if ( ! $this->can_access_protected_posts() ) {
			return false;
		}

		if ( ! $post_id ) {
			return true;
		}

		if ( ! current_user_can( 'read_post', $post_id ) ) {
			return false;
		}

		// A task hidden from this user hides its comments with it.
		return ! Decker_Tasks::is_hidden_from_current_user( $post_id );
	}

	/**
	 * Restrict the comment collection to post types the current user may read.
	 *
	 * The restriction is applied to the query variables rather than to the SQL
	 * clauses. WP_Comment_Query caches its results under a key built from the
	 * query vars alone (`WP_Comment_Query::get_comment_ids()`), so narrowing the
	 * clauses would leave the cache key identical for authorized and
	 * unauthorized users: an authorized user's cached result set would then be
	 * served verbatim to the next anonymous request with the same arguments.
	 * `post_type` is a real query var, so restricting it both filters the query
	 * and varies the cache key.
	 *
	 * Users who may access the protected post types see their comments as before.
	 *
	 * @param array           $args    Query arguments handed to WP_Comment_Query.
	 * @param WP_REST_Request $request Request object (unused).
	 * @return array The possibly restricted arguments.
	 */
	public function prepare_comment_collection_query( $args, $request ) {
		if ( $this->can_access_protected_posts() ) {
			return $args;
		}

		$allowed = array_values( array_diff( get_post_types(), $this->protected_post_types ) );

		if ( ! empty( $allowed ) ) {
			$args['post_type'] = $allowed;
		}

		return $args;
	}

	/**
	 * Exclude comments on protected post types from the classic comments feed.
	 *
	 * The global comments feed (/comments/feed/, /?feed=comments-rss2) is served by
	 * WP_Query without any post_type restriction, so approved comments on published
	 * protected posts (e.g. decker_task) would otherwise leak to anyone. This appends
	 * a WHERE clause excluding any comment whose parent post is of a protected type.
	 *
	 * @param string   $cwhere The WHERE clause of the comment feed query.
	 * @param WP_Query $query  The current WP_Query instance (unused).
	 * @return string The modified WHERE clause.
	 */
	public function filter_comment_feed_where( $cwhere, $query = null ) {
		$protected_post_types = $this->get_protected_post_types();

		if ( empty( $protected_post_types ) ) {
			return $cwhere;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $protected_post_types ), '%s' ) );

		// Exclude comments whose parent post is of a protected post type via a subquery,
		// independent of any table alias used by the feed query's own JOIN.
		$cwhere .= $wpdb->prepare(
			" AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $placeholders ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$protected_post_types
		);

		return $cwhere;
	}

	/**
	 * Block access to single comments on protected post types.
	 *
	 * Access follows the parent post: only a user who may reach the protected post
	 * type, and may read that particular post, may read or modify its comments.
	 *
	 * @param mixed           $result  Dispatch result, will be used if not null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed A WP_Error if access is denied, otherwise the original $result.
	 */
	public function protect_single_comment_access( $result, $server, $request ) {
		$route  = $request->get_route();
		$method = strtoupper( $request->get_method() );

		// Block unauthenticated creation on protected post types.
		if ( Decker::rest_route_matches( $route, '/wp/v2/comments' ) && 'POST' === $method ) {
			return $this->deny_protected_comment_creation( $request ) ?? $result;
		}

		// Handle single comment routes.
		if ( preg_match( '#^/wp/v2/comments/(?P<id>\d+)#i', $route, $matches ) ) {
			return $this->deny_protected_comment_route( (int) $matches['id'], $method ) ?? $result;
		}

		return $result;
	}

	/**
	 * Refuse comment creation on a protected post type by a user without access.
	 *
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return WP_Error|null The refusal, or null when the request may proceed.
	 */
	private function deny_protected_comment_creation( $request ) {
		$post_id = (int) $request->get_param( 'post' );

		if ( ! $post_id || ! in_array( get_post_type( $post_id ), $this->protected_post_types, true ) ) {
			return null;
		}

		if ( ! $this->can_access_protected_post( $post_id ) ) {
			return $this->unauthorized( 'rest_cannot_create_comment' );
		}

		return null;
	}

	/**
	 * Refuse access to a single comment on a protected post type.
	 *
	 * @param int    $comment_id Comment addressed by the route.
	 * @param string $method     Upper-cased HTTP method.
	 * @return WP_Error|null The refusal, or null when the request may proceed.
	 */
	private function deny_protected_comment_route( $comment_id, $method ) {
		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return null;
		}

		if ( ! in_array( get_post_type( $comment->comment_post_ID ), $this->protected_post_types, true ) ) {
			return null;
		}

		if ( $this->can_access_protected_post( (int) $comment->comment_post_ID ) ) {
			return null;
		}

		if ( 'GET' === $method || 'HEAD' === $method ) {
			return $this->unauthorized( 'rest_forbidden_comment' );
		}

		if ( in_array( $method, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $this->unauthorized( 'rest_cannot_edit_comment' );
		}

		return null;
	}

	/**
	 * Build the standard refusal returned for protected comments.
	 *
	 * @param string $code Error code identifying which rule refused the request.
	 * @return WP_Error
	 */
	private function unauthorized( $code ) {
		return new WP_Error(
			$code,
			__( 'You are not authorized to access this resource.', 'decker' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Prevent users without access to a protected post from commenting on it.
	 *
	 * @param array|WP_Error  $prepared_comment An array of comment data or a WP_Error.
	 * @param WP_REST_Request $request          The request object.
	 * @return array|WP_Error The comment data or a WP_Error if denied.
	 */
	public function protect_comment_creation( $prepared_comment, $request ) {
		if ( is_wp_error( $prepared_comment ) ) {
			return $prepared_comment;
		}

		$post_id = (int) $request['post'];
		if ( $post_id
			&& in_array( get_post_type( $post_id ), $this->protected_post_types, true )
			&& ! $this->can_access_protected_post( $post_id ) ) {
			return new WP_Error(
				'rest_cannot_create_comment',
				__( 'You are not authorized to access this resource.', 'decker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $prepared_comment;
	}

	/**
	 * Prevent users without access from updating or deleting comments on protected post types.
	 *
	 * @param WP_Error|null|true $result WP_Error if authentication error, null if authentication method wasn't used, true if authentication succeeded.
	 * @return WP_Error|null|true
	 */
	public function protect_comment_modification( $result ) {
		// Let existing errors through, and allow users who may access Decker content.
		if ( is_wp_error( $result ) || $this->can_access_protected_posts() ) {
			return $result;
		}

		// This hook runs on all authenticated REST requests. We must check if this is a comment modification request.
		$request_uri = ! empty( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( ! preg_match( '#/wp/v2/comments/(?P<id>\d+)#i', $request_uri, $matches ) ) {
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
