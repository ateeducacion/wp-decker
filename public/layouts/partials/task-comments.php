<?php
/**
 * Task comment rendering helper.
 *
 * Extracted from task-card.php so the rendering logic can be reused by the
 * template and covered by unit tests.
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'render_comments' ) ) {
	/**
	 * Organizes and renders comments in a hierarchical structure.
	 *
	 * This function processes an array of comments, organizing them
	 * into a nested structure based on their parent ID. It also allows
	 * for specific handling of comments based on the current user's ID.
	 *
	 * @param array $task_comments        An array of comment objects or arrays,
	 *                               each containing information about a comment.
	 * @param int   $parent_id       The ID of the parent comment. Use 0 for top-level comments.
	 * @param int   $current_user_id The ID of the currently logged-in user.
	 *                               Used to customize rendering for the user.
	 *
	 * @return void
	 */
	function render_comments( array $task_comments, int $parent_id, int $current_user_id ) {
		foreach ( $task_comments as $comment ) {
			if ( $comment->comment_parent == $parent_id ) {
					   // Get replies recursively.
				echo '<div class="d-flex align-items-start mb-2" style="margin-left:' . ( $comment->comment_parent ? '20px' : '0' ) . ';">';
				echo '<img class="me-2 rounded-circle" src="' . esc_url( get_avatar_url( $comment->user_id, array( 'size' => 48 ) ) ) . '" alt="Avatar" height="32" />';
				echo '<div class="w-100">';
				echo '<h5 class="mt-0">' . esc_html( $comment->comment_author ) . ' <small class="text-muted float-end">' . esc_html( get_comment_date( '', $comment ) ) . '</small></h5>';
				// Use the 'comment_text' filter (not 'the_content') so URLs become
				// clickable links, matching the REST API output used by the AJAX path.
				echo wp_kses_post( apply_filters( 'comment_text', $comment->comment_content, $comment ) );

					   // Show delete link if the comment belongs to the current user.
				if ( get_current_user_id() == $comment->user_id ) {
					echo '<a href="javascript:void(0);" onclick="deleteComment(' . esc_attr( $comment->comment_ID ) . ');" class="text-muted d-inline-block mt-2 comment-delete" data-comment-id="' . esc_attr( $comment->comment_ID ) . '"><i class="ri-delete-bin-line"></i> ' . esc_html__( 'Delete', 'decker' ) . '</a> ';

				}

				/* echo '<a href="javascript:void(0);" class="text-muted d-inline-block mt-2 comment-reply" data-comment-id="' . esc_attr( $comment->comment_ID ) . '"><i class="ri-reply-line"></i> Reply</a>'; */

				echo '</div>';
				echo '</div>';

					   // Recursive call to render replies.
				render_comments( $task_comments, $comment->comment_ID, $current_user_id );
			}
		}
	}
}
