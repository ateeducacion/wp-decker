/**
 * Handle AJAX comment submission for tasks
 */
function handle_task_comment_ajax() {
    check_ajax_referer('task_comment_nonce', 'nonce');
    
    $task_id = intval($_POST['task_id']);
    $content = wp_kses_post($_POST['comment_content']);
    $parent_id = intval($_POST['parent_id']);
    
    if (empty($content)) {
        wp_send_json_error(['message' => 'Comment content is required.']);
    }
    
    $comment_data = array(
        'comment_post_ID' => $task_id,
        'comment_content' => $content,
        'comment_parent' => $parent_id,
        'user_id' => get_current_user_id(),
        'comment_approved' => 1
    );
    
    $comment_id = wp_insert_comment($comment_data);
    
    if ($comment_id) {
        $comment = get_comment($comment_id);
        $response = array(
            'success' => true,
            'comment_id' => $comment_id,
            'content' => apply_filters('the_content', $comment->comment_content),
            'author' => get_comment_author($comment_id),
            'date' => get_comment_date('', $comment_id),
            'avatar_url' => get_avatar_url($comment->user_id, ['size' => 48])
        );
        wp_send_json_success($response);
    } else {
        wp_send_json_error(['message' => 'Failed to add comment.']);
    }
}
add_action('wp_ajax_add_task_comment', 'handle_task_comment_ajax');
