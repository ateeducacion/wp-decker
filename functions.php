/**
 * Handle AJAX comment submission for tasks using WordPress native functions
 */
function handle_task_comment_ajax() {
    // Verify nonce
    if (!check_ajax_referer('task_comment_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => __('Security check failed.', 'decker')));
    }

    // Get and validate data
    $task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
    $content = isset($_POST['comment_content']) ? trim($_POST['comment_content']) : '';
    $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;

    if (!$task_id || !$content) {
        wp_send_json_error(array('message' => __('Invalid comment data.', 'decker')));
    }

    // Use wp_handle_comment_submission() which handles all validation and filtering
    $commentdata = array(
        'comment_post_ID' => $task_id,
        'comment_parent' => $parent_id,
        'comment_content' => $content,
        'comment_author' => wp_get_current_user()->display_name,
        'comment_author_email' => wp_get_current_user()->user_email,
        'comment_author_url' => wp_get_current_user()->user_url,
        'comment_type' => 'comment',
    );

    // Let WordPress handle the comment submission
    $comment = wp_handle_comment_submission($commentdata);

    if (is_wp_error($comment)) {
        wp_send_json_error(array('message' => $comment->get_error_message()));
    }

    // Prepare response data using WordPress functions
    $response = array(
        'success' => true,
        'comment_id' => $comment->comment_ID,
        'content' => apply_filters('comment_text', $comment->comment_content),
        'author' => get_comment_author($comment),
        'date' => get_comment_date(get_option('date_format'), $comment),
        'avatar_url' => get_avatar_url($comment->user_id, array('size' => 48))
    );

    wp_send_json_success($response);
}
add_action('wp_ajax_add_task_comment', 'handle_task_comment_ajax');
