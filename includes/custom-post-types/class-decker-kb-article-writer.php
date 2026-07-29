<?php
/**
 * Creates and updates Knowledge Base articles over REST.
 *
 * Owns the save endpoint's whole write path: resolving whether the request
 * targets an existing article, building the post data, assigning board and
 * labels, and renumbering siblings when the article moved between parents.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Kb_Article_Writer
 */
class Decker_Kb_Article_Writer {

	/**
	 * Sibling renumbering used after a parent change.
	 *
	 * @var Decker_Kb_Reorder
	 */
	private $reorder;

	/**
	 * Take the reorder collaborator.
	 *
	 * @param Decker_Kb_Reorder $reorder Sibling renumbering.
	 */
	public function __construct( Decker_Kb_Reorder $reorder ) {
		$this->reorder = $reorder;
	}

	/**
	 * Save or update KB article
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save_article( $request ) {
		$params = $request->get_params();

		$desired_position = isset( $params['menu_order'] ) ? max( 0, intval( $params['menu_order'] ) ) : null;

		// Validate that board is provided only on create (no ID).
		if ( empty( $params['id'] ) && empty( $params['board'] ) ) {
			return Decker_Kb::error_response( __( 'Board is required', 'decker' ) );
		}

		// Look up the existing post (with IDOR guards) when updating.
		$existing_post = $this->get_existing_article( $params );
		if ( $existing_post instanceof WP_REST_Response ) {
			return $existing_post;
		}

		$old_parent_id = $existing_post ? intval( $existing_post->post_parent ) : null;

		$post_data = $this->build_article_post_data( $params, $existing_post );

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return Decker_Kb::error_response( $post_id->get_error_message() );
		}

		// Assign labels and board; a non-null return is a terminal error response.
		$terms_error = $this->assign_article_terms( $post_id, $params );
		if ( $terms_error ) {
			return $terms_error;
		}

		$new_parent_id = intval( $post_data['post_parent'] );
		$this->maybe_reorder_after_save( $params, $post_id, $old_parent_id, $new_parent_id, $desired_position );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Article saved successfully', 'decker' ),
				'id'     => $post_id,
			),
			200
		);
	}

	/**
	 * Look up the existing KB article for an update request.
	 *
	 * Returns null when creating (no ID), the WP_Post when the update target is a
	 * valid editable KB article, or a WP_REST_Response error when the IDOR guards
	 * (missing/non-KB post or insufficient capability) reject the request.
	 *
	 * @param array $params Request params.
	 * @return WP_Post|WP_REST_Response|null
	 */
	private function get_existing_article( array $params ) {
		if ( empty( $params['id'] ) ) {
			return null;
		}

		$post_id       = intval( $params['id'] );
		$existing_post = get_post( $post_id );

		// Ensure the target post exists and is actually a KB article.
		if ( ! $existing_post || 'decker_kb' !== $existing_post->post_type ) {
			return Decker_Kb::error_response( __( 'Article not found', 'decker' ), 404 );
		}

		// Require per-post edit capability for updates.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return Decker_Kb::error_response( __( 'You do not have permission to edit this article', 'decker' ), 403 );
		}

		return $existing_post;
	}

	/**
	 * Build the wp_insert_post() array for a save_article() request.
	 *
	 * Always sets post_parent and menu_order (provided param -> existing value -> 0)
	 * so the caller can read post_parent unconditionally.
	 *
	 * @param array        $params        Request params.
	 * @param WP_Post|null $existing_post Existing post on update, null on create.
	 * @return array
	 */
	private function build_article_post_data( array $params, $existing_post ) {
		$post_data = array(
			'post_type'    => 'decker_kb',
			'post_status'  => 'publish',
		);

		// Title and content always allowed.
		if ( isset( $params['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( isset( $params['content'] ) ) {
			$post_data['post_content'] = wp_kses_post( $params['content'] );
		}

		if ( ! empty( $params['id'] ) ) {
			$post_data['ID'] = intval( $params['id'] );
		}

		// Parent and order: only set if provided. If not, preserve existing values on update.
		if ( isset( $params['parent_id'] ) ) {
			$post_data['post_parent'] = intval( $params['parent_id'] );
		} elseif ( $existing_post ) {
			$post_data['post_parent'] = $existing_post->post_parent;
		} else {
			$post_data['post_parent'] = 0;
		}

		if ( isset( $params['menu_order'] ) ) {
			$post_data['menu_order'] = intval( $params['menu_order'] );
		} elseif ( $existing_post ) {
			$post_data['menu_order'] = $existing_post->menu_order;
		} else {
			$post_data['menu_order'] = 0;
		}

		return $post_data;
	}

	/**
	 * Assign labels and board taxonomy terms for a saved article.
	 *
	 * Returns null on success, or a WP_REST_Response error when an invalid board
	 * is supplied on create (the freshly created post is hard-deleted first).
	 *
	 * @param int   $post_id Saved post ID.
	 * @param array $params  Request params.
	 * @return WP_REST_Response|null
	 */
	private function assign_article_terms( $post_id, array $params ) {
		// Handle labels: only update if provided; otherwise keep.
		if ( isset( $params['labels'] ) && is_array( $params['labels'] ) ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $params['labels'] ), 'decker_label' );
		}

		// Handle board: update only if provided; otherwise keep current for updates.
		if ( isset( $params['board'] ) ) {
			$board_id = intval( $params['board'] );
			if ( $board_id > 0 ) {
				wp_set_object_terms( $post_id, array( $board_id ), 'decker_board' );
			} elseif ( empty( $params['id'] ) ) {
				// If creating and invalid board, reject.
				wp_delete_post( $post_id, true );
				return Decker_Kb::error_response( __( 'Invalid board ID', 'decker' ) );
			}
		}

		return null;
	}

	/**
	 * Recalculate siblings after a save when order or parent changed in the request.
	 *
	 * @param array    $params           Request params.
	 * @param int      $post_id          Saved post ID.
	 * @param int|null $old_parent_id    Parent before the save (null on create).
	 * @param int      $new_parent_id    Parent after the save.
	 * @param int|null $desired_position Desired index (null appends).
	 */
	private function maybe_reorder_after_save( array $params, $post_id, $old_parent_id, $new_parent_id, $desired_position ) {
		$parent_changed = ( null !== $old_parent_id && $old_parent_id !== $new_parent_id );

		if ( ! isset( $params['menu_order'] ) && ! $parent_changed ) {
			return;
		}

		$this->reorder->recalculate_siblings_with_position( $new_parent_id, $post_id, $desired_position );
		if ( $parent_changed ) {
			$this->reorder->recalculate_siblings( $old_parent_id );
		}
	}
}
