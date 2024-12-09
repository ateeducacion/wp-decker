<?php
/**
 * File task-card
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

/**
 * Function to find and include wp-load.php dynamically.
 *
 * @param int $max_levels Maximum number of directory levels to traverse upward.
 * @return bool Returns true if wp-load.php is found and included, otherwise false.
 */
function include_wp_load( $max_levels = 10 ) {
	$dir = __DIR__;
	for ( $i = 0; $i < $max_levels; $i++ ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			return true;
		}
		// Move up one level in the directory structure.
		$parent_dir = dirname( $dir );
		if ( $parent_dir === $dir ) {
			// Reached the root directory of the file system.
			break;
		}
		$dir = $parent_dir;
	}
	return false;
}

// Attempt to include wp-load.php, required when we are loading the task-card in a Bootstrap modal.
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! include_wp_load() ) { // Usa tu función include_wp_load().
		exit( 'Error: Unauthorized access.' );
	}
}

if ( ! defined( 'DECKER_TASK' ) ) {
	// Sanitize and verify the nonce.
	$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'decker_task_card' ) ) {
		exit( 'Unauthorized request.' );
	}
}


$task_id = 0;
if ( isset( $_GET['id'] ) ) {
	$task_id = intval( $_GET['id'] );
}
$task = new Task( $task_id );

$board_slug = '';
if ( isset( $_GET['slug'] ) ) {
	$board_slug = sanitize_text_field( wp_unslash( $_GET['slug'] ) );
}

$disabled = false;
if ( $task_id && 'archived' == $task->status ) {
	$disabled = true;
}

$task_comments = array();

if ( $task_id ) {

	// Obtener comentarios asociados al task_id.
	$task_comments = get_comments(
		array(
			'post_id' => $task_id,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	);

}

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
			// Obtener respuestas recursivamente.
			echo '<div class="d-flex align-items-start mb-2" style="margin-left:' . ( $comment->comment_parent ? '20px' : '0' ) . ';">';
			echo '<img class="me-2 rounded-circle" src="' . esc_url( get_avatar_url( $comment->user_id, array( 'size' => 48 ) ) ) . '" alt="Avatar" height="32" />';
			echo '<div class="w-100">';
			echo '<h5 class="mt-0">' . esc_html( $comment->comment_author ) . ' <small class="text-muted float-end">' . esc_html( get_comment_date( '', $comment ) ) . '</small></h5>';
			echo wp_kses_post( apply_filters( 'the_content', $comment->comment_content ) );

			// Mostrar enlace de eliminar si el comentario pertenece al usuario actual.
			if ( get_current_user_id() == $comment->user_id ) {
				echo '<a href="javascript:void(0);" onclick="deleteComment(' . esc_attr( $comment->comment_ID ) . ');" class="text-muted d-inline-block mt-2 comment-delete" data-comment-id="' . esc_attr( $comment->comment_ID ) . '"><i class="ri-delete-bin-line"></i> ' . esc_html__( 'Delete', 'decker' ) . '</a> ';

			}

			/* echo '<a href="javascript:void(0);" class="text-muted d-inline-block mt-2 comment-reply" data-comment-id="' . esc_attr( $comment->comment_ID ) . '"><i class="ri-reply-line"></i> Reply</a>'; */

			echo '</div>';
			echo '</div>';

			// Llamada recursiva para renderizar respuestas.
			render_comments( $task_comments, $comment->comment_ID, $current_user_id );
		}
	}
}
?>

<script type="text/javascript">
// Start of comment part
var replyToCommentId = null;

document.addEventListener('DOMContentLoaded', function() {


	 initializeSendComments();
	
});

function initializeSendComments() {

	const commentTextArea = document.getElementById('comment-text');
	const submitButton = document.getElementById('submit-comment');
	
	if (commentTextArea && submitButton) {
		// Enable/disable submit button based on textarea content
		commentTextArea.addEventListener('input', function() {
			submitButton.disabled = this.value.trim() === '';
		});

		// Handle comment submission
		submitButton.addEventListener('click', function() {
			const commentText = commentTextArea.value;
			const taskId = <?php echo wp_json_encode( $task_id ); ?>;
			const parentId = replyToCommentId;

			if (commentText.trim() === '') {
				return;
			}

			// Show loading state
			submitButton.disabled = true;
			submitButton.innerHTML = '<i class="ri-loader-2-line ri-spin me-1"></i> Sending...';

			const formData = new FormData();
			formData.append('action', 'add_task_comment');
			formData.append('task_id', taskId);
			formData.append('comment_content', commentText);
			formData.append('parent_id', parentId || 0);
			formData.append('nonce', '<?php echo esc_attr( wp_create_nonce( 'task_comment_nonce' ) ); ?>');

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					// Clear form
					commentTextArea.value = '';
					if (replyToCommentId) {
						document.getElementById('reply-indicator').classList.add('d-none');
						replyToCommentId = null;
					}

					// Add new comment to the list
					const commentsList = document.getElementById('comments-list');
					const newComment = document.createElement('div');
					newComment.className = 'd-flex align-items-start mb-2';
					if (parentId) {
						newComment.style.marginLeft = '20px';
					}
					
					newComment.innerHTML = `
						<img class="me-2 rounded-circle" src="${data.data.avatar_url}" alt="Avatar" height="32" />
						<div class="w-100">
							<h5 class="mt-0">${data.data.author} <small class="text-muted float-end">${data.data.date}</small></h5>
							${data.data.content}
							<br />
							<a href="javascript:void(0);" onclick="deleteComment(${data.data.comment_id});" 
							   class="text-muted d-inline-block mt-2 comment-delete" 
							   data-comment-id="${data.data.comment_id}">
								<i class="ri-delete-bin-line"></i> Delete
							</a>
							<!--<a href="javascript:void(0);" class="text-muted d-inline-block mt-2 comment-reply" 
							   data-comment-id="${data.data.comment_id}">
								<i class="ri-reply-line"></i> Reply
							</a>-->
						</div>
					`;
					
					if (parentId) {
						// Find parent comment and append after it.
						const parentComment = document.querySelector(`[data-comment-id="${parentId}"]`).closest('.d-flex');
						parentComment.after(newComment);
					} else {
						// Append to main comments list.
						commentsList.appendChild(newComment);
					}

					// Update comment count.
					const commentCount = document.getElementById('comment-count');
					if (commentCount) {
						commentCount.textContent = parseInt(commentCount.textContent) + 1;
					}
				} else {
					alert(data.message || 'Error adding comment.');
				}
			})
			.catch(error => {
				console.error('Error:', error);
				alert('Error adding comment. Please try again.');
			})
			.finally(() => {
				// Reset button state
				submitButton.disabled = commentTextArea.value.trim() === '';
				submitButton.innerHTML = '<i class="ri-chat-1-line me-1"></i> Comment';
			});
		});
	}

}

// Borrar comentario.
function deleteComment(commentId) {
	if (!confirm('<?php esc_html_e( 'Are you sure you want to delete this comment?', 'decker' ); ?>')) {
		return;
	}

	fetch(`/wp-json/wp/v2/comments/${commentId}`, {
		method: 'DELETE',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
		}
	})
	.then(response => response.json())
	.then(data => {
		if (data.status == 'trash') {
			// Find and remove the comment element
			const commentElement = document.querySelector(`[data-comment-id="${commentId}"]`).closest('.d-flex');
			if (commentElement) {
				commentElement.remove();
				
				// Update comment count
				const commentCount = document.getElementById('comment-count');
				if (commentCount) {
					const currentCount = parseInt(commentCount.textContent);
					if (!isNaN(currentCount)) {
						commentCount.textContent = currentCount - 1;
					}
				}
			}
		} else {
			alert('<?php esc_html_e( 'Failed to delete comment.', 'decker' ); ?>');
		}
	})
	.catch(error => {
		console.error('Error:', error);
		alert('<?php esc_html_e( 'Error deleting comment.', 'decker' ); ?>');
	});
}

	// Opcional: Habilitar el botón de enviar cuando se completa el textarea de comentarios
	const commentText = document.getElementById('comment-text');
	const submitCommentButton = document.getElementById('submit-comment');

	if (commentText) {
		commentText.addEventListener('input', function() {
			if (commentText.value.trim() !== '') {
				submitCommentButton.disabled = false;
			} else {
				submitCommentButton.disabled = true;
			}
		});
	}

// End of comment part
</script>

<!-- Task card -->
<form id="task-form" class="needs-validation" target="_self" novalidate>
	<input type="hidden" name="action" value="save_decker_task">
	<input type="hidden" name="task_id" value="<?php echo esc_attr( $task_id ); ?>">
	<div class="row">

		<!-- Title -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="task-title" value="<?php echo esc_attr( $task->title ); ?>" placeholder="<?php esc_attr_e( 'Task title', 'decker' ); ?>" required <?php disabled( $disabled ); ?>>
				<label for="task-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?><span id="high-label" class="badge bg-danger ms-2 d-none"><?php esc_html_e( 'MAXIMUM PRIORITY', 'decker' ); ?></span></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
			</div>
		</div>

		<!-- Maximum priority and For today -->
		<div class="col-md-3 mb-2 d-flex flex-column align-items-start">
			<div class="form-check form-switch mb-2">
				<input class="form-check-input" type="checkbox" id="task-max-priority" onchange="togglePriorityLabel(this)" <?php checked( $task->max_priority ); ?> <?php disabled( $disabled ); ?>>
				<label class="form-check-label" for="task-max-priority"><?php esc_html_e( 'Maximum Priority', 'decker' ); ?></label>
			</div>
			<div class="form-check form-switch">
				<input class="form-check-input" type="checkbox" id="task-today" 
				   <?php checked( $task->is_current_user_today_assigned() ); ?> <?php disabled( $disabled ); ?>>
				<label class="form-check-label" for="task-today"><?php esc_html_e( 'For today', 'decker' ); ?></label>
			</div>
		</div>

	</div>

	<div class="row">

		<!-- Boards -->
		<div class="col-md-4 mb-3">
			<div class="form-floating">
				<?php // TODO: Allow changing the board. ?>
				<select class="form-select" id="task-board" required <?php disabled( $disabled || $task_id ); ?>>
					<option value="" disabled selected><?php esc_html_e( 'Select Board', 'decker' ); ?></option>
					<?php

						$boards = BoardManager::get_all_boards();

					foreach ( $boards as $board ) {
						echo '<option value="' . esc_attr( $board->id ) . '" ' . selected( $task->board && $task->board->id == $board->id ) . ' ' . selected( $board_slug, $board->slug ) . '>' . esc_html( $board->name ) . '</option>';
					}
					?>

				</select>
				<label for="task-board" class="form-label"><?php esc_html_e( 'Board', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please select a board.', 'decker' ); ?></div>

			</div>
		</div>

		
		<!-- Author -->
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<!-- Author always disabled -->
				<select class="form-select" id="task-author" required 
					<?php disabled( $disabled || ! current_user_can( 'edit_posts' ) ); // Disable the select if the current user cannot edit posts. ?>>
					<option value="" disabled selected><?php esc_html_e( 'Select Author', 'decker' ); ?></option>
					<?php
					// Get ignored users from settings.
					$options       = get_option( 'decker_settings', array() );
					$ignored_users = isset( $options['ignored_users'] ) ? array_map( 'intval', explode( ',', $options['ignored_users'] ) ) : array();

					$users = get_users(
						array(
							'orderby'  => 'display_name',
							'exclude'  => $ignored_users,
						)
					);

					foreach ( $users as $user ) {
						echo '<option value="' . esc_attr( $user->ID ) . '" '
							. selected( $user->ID, $task->author, false ) . ' '
							. disabled( ! user_can( $user->ID, 'edit_posts' ), true, false ) . // Disable the option if the user cannot edit posts.
							'>' . esc_html( $user->display_name ) . '</option>';
					}
					?>
				</select>
				<label for="task-author" class="form-label"><?php esc_html_e( 'Author', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please select an author.', 'decker' ); ?><</div>				
			</div>
		</div>

		<!-- Stack -->
		<div class="col-md-2 mb-2">
			<div class="form-floating">
				<select class="form-select" id="task-stack" required <?php disabled( $disabled ); ?>>
					<option value="to-do" <?php selected( $task->stack, 'to-do' ); ?>><?php esc_html_e( 'To Do', 'decker' ); ?></option>
					<option value="in-progress" <?php selected( $task->stack, 'in-progress' ); ?>><?php esc_html_e( 'In Progress', 'decker' ); ?></option>
					<option value="done" <?php selected( $task->stack, 'done' ); ?>><?php esc_html_e( 'Done', 'decker' ); ?></option>
				</select>
				<label for="task-stack" class="form-label"><?php esc_html_e( 'Stack', 'decker' ); ?></label>
			</div>
		</div>

		<!-- Due date -->
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<input class="form-control" id="task-due-date" type="date" name="date" value="<?php echo esc_attr( $task->get_duedate_as_string() ); ?>" placeholder="<?php esc_attr_e( 'Select date', 'decker' ); ?>" required <?php disabled( $disabled ); ?>>
				<label class="form-label" for="task-due-date"><?php esc_html_e( 'Due Date', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please select a due date.', 'decker' ); ?></div>
			</div>
		</div>

	</div>


	<!-- Asignados y Etiquetas con ejemplos preseleccionados -->
	<div class="row">
		<div class="mb-3">
			<label for="task-assignees" class="form-label"><?php esc_html_e( 'Assign to', 'decker' ); ?></label>
				<select class="form-select" id="task-assignees" multiple <?php disabled( $disabled ); ?>>
					<?php

					foreach ( $users as $user ) {
						$is_disabled = ! user_can( $user->ID, 'edit_posts' );
						$class = $is_disabled ? 'class="no-edit-capability"' : '';

						// Verify if the suser is on the assignees list.
						$is_selected = in_array( $user->ID, array_column( $task->assigned_users, 'ID' ) );


						echo '<option value="' . esc_attr( $user->ID ) . '" '
							. wp_kses_post( $class ) . ' '
							. disabled( $is_disabled, true, false ) . ' ' // Add "disabled" if needed.
							. selected( $is_selected, true, false ) . '>' // Add "selected" if needed.
							. esc_html( $user->display_name )
							. '</option>';

					}
					?>
				</select>
		</div>
	</div>
	<div class="row">

		<div class="mb-3">
			<label for="task-labels" class="form-label"><?php esc_html_e( 'Labels', 'decker' ); ?></label>
			<select class="form-select" id="task-labels" multiple <?php disabled( $disabled ); ?>>
				<?php
				$labels = LabelManager::get_all_labels();
				foreach ( $labels as $label ) {
					echo '<option value="' . esc_attr( $label->id ) . '" data-choice-custom-properties=\'{"color": "' . esc_attr( $label->color ) . '"}\' ' . selected( in_array( $label->id, array_column( $task->labels, 'id' ) ) ) . '>' . esc_html( $label->name ) . '</option>';
				}
				?>
			</select>
		</div>
	</div>


	<!-- Tabs: Description, Commetns and Attachments -->
	<ul class="nav nav-tabs nav-bordered mb-3">
		<li class="nav-item">
			<a href="#description-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link active"><?php esc_html_e( 'Description', 'decker' ); ?>
			</a>
		</li>
		<li class="nav-item">
			<a href="#comments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link<?php echo ( 0 === $task_id ) ? ' disabled' : ''; ?>" <?php disabled( 0 === $task_id ); ?>><?php esc_html_e( 'Comments', 'decker' ); ?>
			   <span class="badge bg-light text-dark" id="comment-count"><?php echo count( $task_comments ); ?></span>

			</a>
		</li>
		<li class="nav-item">
			<a href="#attachments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link<?php echo ( 0 === $task_id ) ? ' disabled' : ''; ?>" <?php disabled( 0 === $task_id ); ?>><?php esc_html_e( 'Attachments', 'decker' ); ?> 

			<?php
			// Obtener los adjuntos asociados con la tarea.

			$attachments = get_attached_media( '', $task_id );

			?>
			<span class="badge bg-light text-dark" id="attachment-count"><?php echo count( $attachments ); ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a href="#history-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link<?php echo ( 0 === $task_id ) ? ' disabled' : ''; ?>" <?php disabled( 0 === $task_id ); ?>><?php esc_html_e( 'History', 'decker' ); ?>

			<!-- <span class="badge bg-light text-dark">0</span> -->
			</a>
		</li>
		<li class="nav-item">
			<a href="#gantt-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link<?php echo ( 0 === $task_id ) ? ' disabled' : ''; ?>" <?php disabled( 0 === $task_id ); ?>><?php esc_html_e( 'Gantt', 'decker' ); ?></a>
		</li>
	</ul>

	<div class="tab-content">
		<!-- Description (Quill Editor) -->
		<div class="tab-pane show active" id="description-tab">
			<div id="editor-container">
				<div id="editor" style="height: 200px;"><?php echo wp_kses( $task->description, Decker::get_allowed_tags() ); ?></div>
			</div>
		</div>

		<!-- Comments -->
		<div class="tab-pane" id="comments-tab">
			<div id="comments-list">
				<?php
				if ( $task_id ) {
					if ( $task_comments ) {
						render_comments( $task_comments, 0, get_current_user_id() );
					} else {
						echo '<p>' . esc_html__( 'No comments yet.', 'decker' ) . '</p>';
					}
				}
				?>
			</div>
			<div class="border rounded mt-4">
				<div class="comment-area-box">
					<div id="reply-indicator" class="p-2 bg-light text-secondary d-none">
						<?php esc_html_e( 'Replying to', 'decker' ); ?> <span id="replying-to"></span>
						<button type="button" class="btn-close float-end" id="cancel-reply"></button>
					</div>
					<textarea rows="3" class="form-control border-0 resize-none" placeholder="<?php esc_attr_e( 'Write your comment...', 'decker' ); ?>" id="comment-text" name="comment-text"></textarea>
					<div class="invalid-feedback"><?php esc_html_e( 'Please enter a comment.', 'decker' ); ?></div>
					<div class="p-2 bg-light d-flex justify-content-between align-items-center" id="comment-actions">
						<button type="button" class="btn btn-sm btn-success" id="submit-comment" disabled><i class="ri-chat-1-line me-1"></i> <?php esc_attr_e( 'Comment', 'decker' ); ?></button>
					</div>
				</div>
			</div>

		</div>

			<!-- Attachments -->
			<div class="tab-pane" id="attachments-tab">
				<ul class="list-group mt-3" id="attachments-list">
					<?php
					foreach ( $attachments as $attachment ) :

						$attachment_url   = $attachment->guid;
						$file_extension   = pathinfo( $attachment_url, PATHINFO_EXTENSION );
						$attachment_title = $attachment->post_title . '.' . $file_extension;

						?>
						<li class="list-group-item d-flex justify-content-between align-items-center" data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
							<a href="<?php echo esc_url( $attachment_url ); ?>" download="<?php echo esc_attr( $attachment_title ); ?>">
								<?php echo esc_html( $attachment_title ); ?> <i class="bi bi-box-arrow-up-right ms-2"></i>
							</a>
							<div>
								<button type="button" class="btn btn-sm btn-danger me-2 remove-attachment" <?php echo $disabled ? 'disabled' : ''; ?>><?php esc_html_e( 'Delete', 'decker' ); ?></button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<br>
				<div class="d-flex align-items-center">
					<input type="file" id="file-input" class="form-control me-2" <?php echo $disabled ? 'disabled' : ''; ?> />
					<button type="button" class="btn btn-sm btn-success" id="upload-file" <?php echo $disabled ? 'disabled' : ''; ?>><?php esc_html_e( 'Upload', 'decker' ); ?></button>
				</div>
			</div>

		<!-- History -->
		<div class="tab-pane" id="history-tab">

			<table id="user-history-table" class="table table-bordered table-striped table-hover table-sm">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nickname', 'decker' ); ?></th>
						<th><?php esc_html_e( 'Date', 'decker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$history = $task->get_user_history_with_objects();
					$timeline_data                = array();
					foreach ( $history as $record ) {
						$user      = $record['user'];
						$avatar    = get_avatar( $user->ID, 32 ); // Get WordPress avatar.
						$nickname  = esc_html( $user->nickname );
						$full_name = esc_attr( $user->first_name . ' ' . $user->last_name ); // Assuming first and last name exist.
						$date      = esc_html( $record['date'] );

						echo '<tr>';
						echo '<td title="' . esc_attr( $full_name ) . '">' . wp_kses_post( $avatar ) . ' ' . esc_html( $nickname ) . '</td>';
						echo '<td>' . esc_html( $date ) . '</td>';
						echo '</tr>';


						// Prepare data for the Timeline Chart.
						$timeline_data[] = array(
							'nickname' => esc_html( $nickname ),
							'date'     => $record['date'],
						);

					}

					// Convert PHP array to JSON for use in JavaScript.
					$timeline_data_json = wp_json_encode( $timeline_data );
					// TO-DO: use this to draw the gantt.

					?>
				</tbody>
			</table>

		</div>

		<!-- Gantt -->
		<div class="tab-pane" id="gantt-tab">
			<div class="tab-pane" id="gantt-tab">
				<p class="text-muted"><?php esc_html_e( 'Under construction...', 'decker' ); ?></p>
			</div>
		</div>

	</div>


	<!-- Switch de Prioridad Máxima y Botones de Archive y Guardar -->
	<div class="d-flex justify-content-end align-items-center mt-3">


		<div class="btn-group mb-2 dropup">
			<button type="submit" class="btn btn-primary" id="save-task" disabled>
				<i class="ri-save-line"></i> <?php esc_html_e( 'Save', 'decker' ); ?>
			</button>
			<button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split dropup" id="save-task-dropdown" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" <?php disabled( $disabled || 0 == $task_id ); ?>>
				<span class="visually-hidden"><?php esc_html_e( 'Toggle Dropdown', 'decker' ); ?></span>
			</button>
			<?php
			if ( $task_id ) {
				$task->render_task_menu( true );
			}
			?>

		</div>


	</div>



</form>

<script type="text/javascript">

var quill = null;
var assigneesSelect;
var labelsSelect;

document.addEventListener('DOMContentLoaded', function() {
	initializeTaskPage();
});


function initializeTaskPage() {




	new Tablesort(document.getElementById('user-history-table'));



	// Verificar si el task_id está presente en data-task-id
	const taskElement = document.querySelector(`[data-task-id="${<?php echo wp_json_encode( $task_id ); ?>}"]`);
	if (taskElement) {
		console.log('Task ID found in data-task-id:', taskElement.getAttribute('data-task-id'));
	} else {
		console.log('Task ID not found in data-task-id');
	}


	if (document.getElementById('editor')) {
		if (quill === null) {

			// Register the HTML Edit Button module
			Quill.register('modules/htmlEditButton', htmlEditButton);

			quill = new Quill('#editor', {
				theme: 'snow',
				readOnly: <?php echo $disabled ? 'true' : 'false'; ?>,
				modules: {
					toolbar: { 
						container: [
							// [{ 'header': [1, 2, false] }],
							['bold', 'italic', 'underline', 'strike'],
							// [{ 'color': [] }, { 'background': [] }],
							['link', 'blockquote', 'code-block'],
							[{ 'list': 'ordered' }, { 'list': 'bullet' }, { 'list': 'check' }],
							[{ 'indent': '-1' }, { 'indent': '+1' }], // Disminuir y aumentar sangría.
							['clean'],
							// Add the new buttons.
							['fullscreen'], // Full-screen button.
							// The HTML edit button will be added automatically.
						],
						handlers: {
							'fullscreen': function() {
								var editorContainer = document.getElementById('editor-container');
								if (!document.fullscreenElement) {
									editor.style.height = '500px'
									editorContainer.requestFullscreen().catch(err => {
										alert('Error attempting to enable full-screen mode: ' + err.message);
									});
								} else {
									document.exitFullscreen();
									editor.style.height = '100px'; // restore the original height.
								}
							}
						}
					},	
					htmlEditButton: {
						// Optional configuration for the HTML Edit Button
						syntax: false, // Enable syntax highlighting if you have highlight.js included
						buttonTitle: "<?php esc_attr_e( 'Show HTML source', 'decker' ); ?>",
						msg: "<?php esc_attr_e( 'Edit the content in HTML format', 'decker' ); ?>", //Custom message to display in the editor, default: Edit HTML here, when you click "OK" the quill editor's contents will be replaced
						okText: "<?php esc_attr_e( 'OK', 'decker' ); ?>", // Text to display in the OK button, default: Ok,
						cancelText: "<?php esc_attr_e( 'Cancel', 'decker' ); ?>", // Text to display in the cancel button, default: Cancel
						closeOnClickOverlay: false,
					},   				
				}
			});
		}

	}

	// Inicializar Choices.js para los selectores de asignados y etiquetas
	if (document.getElementById('task-assignees')) {
		assigneesSelect = new Choices('#task-assignees', { 
			removeItemButton: true,
			allowHTML: true,
			searchEnabled: true,
			shouldSort: true,
		});
	
		// TODO: Agregar el evento de cambio para los asignados
		assigneesSelect.passedElement.element.addEventListener('change', handleAssigneesChange);

	}


	if (document.getElementById('task-labels')) {
		labelsSelect = new Choices('#task-labels', { 
			removeItemButton: true, 
			allowHTML: true,
			searchEnabled: true,
			shouldSort: true,
		});
	}



	var uploadFileButton = document.getElementById('upload-file');
	if (uploadFileButton) {
		uploadFileButton.addEventListener('click', function () {
			var fileInput = document.getElementById('file-input');
			if (fileInput.files.length > 0) {
				uploadAttachment(fileInput.files[0]);
			} else {
				alert(<?php echo wp_json_encode( __( 'Please select a file to upload.', 'decker' ) ); ?>);
			}
		});
	}
	
	// Show/hide "High" label for maximum priority
	var taskMaxPriority = document.getElementById('task-max-priority');
	if (taskMaxPriority) {
		taskMaxPriority.addEventListener('change', function () {
			togglePriorityLabel(this);
		});
	}


	// TODO Cambios esteticos al selencionar/deselecionar el check tareas
	const taskTodayCheckbox = document.getElementById('task-today');
	// Verifica si el checkbox está presente en la página
	if (taskTodayCheckbox) {
		taskTodayCheckbox.addEventListener('change', handleTaskTodayChange);
	}


	const saveButton = document.getElementById('save-task');

	// Function to enable save button when any field changes
	const enableSaveButton = function() {
		saveButton.disabled = false;

		// TO-DO: Finish this to prevent closing without saving
		// hasUnsavedChanges = true;
	};

	const form = document.getElementById('task-form');

	// // Add event listeners to all form inputs
	// const inputs = form.querySelectorAll('input, textarea, select');
	// inputs.forEach(function(input) {
	//     input.addEventListener('change', enableSaveButton);
	//     input.addEventListener('input', enableSaveButton);
	// });


	// Add event listeners to all form inputs
	const inputIds = ['task-title', 'task-due-date', 'task-board', 'task-stack', 'task-author', 'task-today', 'task-max-priority'];

	// Iterate and assing listeners
	inputIds.forEach(function(id) {
		const element = document.getElementById(id);
		if (element) {
			element.addEventListener('change', enableSaveButton);
			element.addEventListener('input', enableSaveButton);
		}
	});
 

	 // Check the initial state of the max-priority checkbox and toggle the label accordingly
	var taskMaxPriority = document.getElementById('task-max-priority');
	if (taskMaxPriority) {
		togglePriorityLabel(taskMaxPriority);
	}
   
	// For Quill Editor
	if (quill) {
		quill.on('text-change', function() {
			saveButton.disabled = false;
		});
	}

	// For Choices.js Selects
	if (assigneesSelect) {
		assigneesSelect.passedElement.element.addEventListener('change', enableSaveButton);
	}
	if (labelsSelect) {
		labelsSelect.passedElement.element.addEventListener('change', enableSaveButton);
	}

	// TO-DO: esto está duplicado de footer-scripts, unificar...
	document.querySelectorAll('.archive-task').forEach((element) => {
	  element.addEventListener('click', function () {
		var taskId = element.getAttribute('data-task-id');
		if (confirm(<?php echo wp_json_encode( __( 'Are you sure you want to archive this task?', 'decker' ) ); ?>)) {
		  fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/archive', {
			method: 'POST',
			headers: {
			  'Content-Type': 'application/json',
			  'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
			},
			body: JSON.stringify({ status: 'archived' })
		  })
		  .then(response => {
			if (!response.ok) {
			  throw new Error('Network response was not ok');
			}
			return response.json();
		  })
		  .then(data => {
			if (data.success) {

			  // TO-DO: Maybe will be better just remove the card, but we reload just for better debuggin
			  // element.closest('.card').remove();

			  // Reload the page if the request was successful
			  location.reload();   

			} else {
			  alert(<?php echo wp_json_encode( __( 'Failed to archive task.', 'decker' ) ); ?>);
			}
		  })
		  .catch(error => console.error('Error:', error));
		}
	  });
	});    

}


// TO-DO: Función para manejar cambios en la casilla "task-today"
function handleTaskTodayChange(event) {

	// Si el usuario marca una tarea para hoy
	if (event.target.checked) {
		// Verificar si el usuario ya está seleccionado
		const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
		// Y si no está seleccioando
		if (!selectedValues.includes(userId)) {
			// Lo selecciona
			// assigneesSelect.setChoiceByValue(userId);
			assigneesSelect.setChoiceByValue(userId.toString()); // Asegúrate de que userId sea un string


		}
	}
	// Si se desmarca, no hacer nada
}

// TO-DO: Función para manejar cambios en los asignados
function handleAssigneesChange(event) {
	// Si el usuario se quita de los asignados a la tarea
	const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
	if (!selectedValues.includes(userId.toString())) {
		const taskTodayCheckbox = document.getElementById('task-today');
		// Y tiene la tarea marcada para hoy
		if (taskTodayCheckbox && taskTodayCheckbox.checked) {
			// La desmarca
			taskTodayCheckbox.checked = false;
		}
	}
}


// TO-DO: Finish this to prevent closing without saving
// var hasUnsavedChanges = false;
// window.addEventListener('beforeunload', function(event) {
//     if (hasUnsavedChanges) {
//         // Mostrará una advertencia al usuario
//         event.preventDefault();
//         event.returnValue = ''; // Para algunos navegadores, esto es necesario
//     }
// });

function uploadAttachment(file) {
	var formData = new FormData();
	formData.append('action', 'upload_task_attachment');
	formData.append('task_id', <?php echo wp_json_encode( $task_id ); ?>);
	formData.append('attachment', file);
	formData.append('nonce', '<?php echo esc_attr( wp_create_nonce( 'upload_attachment_nonce' ) ); ?>');

	var xhr = new XMLHttpRequest();
	xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', true);

	xhr.onload = function() {
		if (xhr.status >= 200 && xhr.status < 400) {
			var response = JSON.parse(xhr.responseText);
			if (response.success) {
				// Añadir el nuevo adjunto a la lista en la interfaz
				addAttachmentToList(response.data.attachment_id, response.data.attachment_url, response.data.attachment_title, response.data.attachment_extension);
				// Limpiar el input de archivo
				document.getElementById('file-input').value = '';
			} else {
				alert(response.data.message || <?php echo wp_json_encode( __( 'Error uploading attachment.', 'decker' ) ); ?>);
			}
		} else {
			console.error('Server error.');
			alert('An error occurred while uploading the attachment.');
		}
	};

	xhr.onerror = function() {
		console.error('Request error.');
		alert('An error occurred while uploading the attachment.');
	};

	xhr.send(formData);
}

function addAttachmentToList(attachmentId, attachmentUrl, attachmentTitle, attachmentExtension) {
	var attachmentsList = document.getElementById('attachments-list');
	var li = document.createElement('li');
	var attachmentFilename = `${attachmentTitle}.${attachmentExtension}`; 

	li.className = 'list-group-item d-flex justify-content-between align-items-center';
	li.setAttribute('data-attachment-id', attachmentId);

	li.innerHTML = `
		<a href="${attachmentUrl}" target="_blank" download="${attachmentFilename}">
			${attachmentFilename} <i class="bi bi-box-arrow-up-right ms-2"></i>
		</a>
		<div>
			<button type="button" class="btn btn-sm btn-danger me-2 remove-attachment"<?php echo $disabled ? ' disabled' : ''; ?>><?php esc_html_e( 'Delete', 'decker' ); ?></button>
		</div>
	`;

	attachmentsList.appendChild(li);

	// Actualiza el contador de archivos
	updateAttachmentCount(1); // Incrementar en 1    
}

document.addEventListener('click', function(event) {
	if (event.target && event.target.classList.contains('remove-attachment')) {
		var listItem = event.target.closest('li');
		var attachmentId = listItem.getAttribute('data-attachment-id');
		deleteAttachment(attachmentId, listItem);
	}
});

function deleteAttachment(attachmentId, listItem) {
	if (!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to delete this attachment?', 'decker' ) ); ?>)) {
		return;
	}

	var formData = new FormData();
	formData.append('action', 'delete_task_attachment');
	formData.append('task_id', <?php echo wp_json_encode( $task_id ); ?>);
	formData.append('attachment_id', attachmentId);
	formData.append('nonce', '<?php echo esc_attr( wp_create_nonce( 'delete_attachment_nonce' ) ); ?>');

	var xhr = new XMLHttpRequest();
	xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', true);

	xhr.onload = function() {
		if (xhr.status >= 200 && xhr.status < 400) {
			var response = JSON.parse(xhr.responseText);
			if (response.success) {
				// Eliminar el adjunto de la lista en la interfaz
				listItem.remove();

				// Actualiza el contador de archivos
				updateAttachmentCount(-1); // Decrementar en 1                
			} else {
				alert(response.data.message || 'Error deleting attachment.');
			}
		} else {
			console.error('Server error.');
			alert('An error occurred while deleting the attachment.');
		}
	};

	xhr.onerror = function() {
		console.error('Request error.');
		alert('An error occurred while deleting the attachment.');
	};

	xhr.send(formData);
}

function updateAttachmentCount(change) {
	var attachmentCountElement = document.getElementById('attachment-count');
	if (attachmentCountElement) {
		var currentCount = parseInt(attachmentCountElement.textContent, 10) || 0;
		var newCount = currentCount + change;
		attachmentCountElement.textContent = newCount;
	}
}

function togglePriorityLabel(element) {
	var highLabel = document.getElementById('high-label');
	if (highLabel) {
		if (element.checked) {
			highLabel.classList.remove('d-none');
		} else {
			highLabel.classList.add('d-none');
		}
	}
}

// custom-task.js


taskModal = document.getElementById('task-modal');

if (taskModal) {

	taskModal.addEventListener('contentLoaded', function () {
	const formModal = taskModal.querySelector('#task-form');

	if (formModal && !formModal.dataset.listener) {
		// El formulario ya está en el DOM, agrega el listener directamente
		formModal.dataset.listener = 'true';

		formModal.addEventListener('submit', function(event) {
			event.preventDefault();
			sendFormByAjax(event);
		});
	}

	// Inicializar otras funcionalidades que dependan del contenido cargado
	initializeTaskPage();
	initializeSendComments();
});


	// TO-DO: Finish this to prevent closing without saving
	// taskModal.addEventListener('hide.bs.modal', function(event) {
	//     if (hasUnsavedChanges) {
	//         event.preventDefault(); // Prevent the modal from closing
	//         if (confirm('You have unsaved changes. Are you sure you want to close the modal?')) {
	//             // resetUnsavedChanges(); // Allow closing if confirmed
	//             const modalInstance = bootstrap.Modal.getInstance(taskModal);
	//             if (modalInstance) {
	//                 modalInstance.hide();
	//             }
	//         }
	//     }
	// });

}

document.addEventListener('DOMContentLoaded', function () {
	const form = document.getElementById('task-form');

	if (form && !form.dataset.listener) {
		form.dataset.listener = 'true';

		form.addEventListener('submit', function(event) {
			sendFormByAjax(event);
		});
	}
});


function sendFormByAjax(event) {
	event.preventDefault();

	const form = document.getElementById('task-form');

	// form.addEventListener('submit', function(event) {
	//     event.preventDefault(); // Previene el envío por defecto


		// Remueve la clase 'was-validated' previamente
		form.classList.remove('was-validated');

		// Verifica la validez del formulario
		if (!form.checkValidity()) {
			event.stopPropagation();
			form.classList.add('was-validated');
			return;
		}

		// Si el formulario es válido, procede con el envío vía AJAX
		const selectedAssigneesValues = assigneesSelect.getValue().map(item => parseInt(item.value, 10));
		const selectedLabelsValues = labelsSelect.getValue().map(item => parseInt(item.value, 10));

		// Recopila los datos del formulario
		const formData = {
			action: 'save_decker_task',
			nonce: '<?php echo esc_attr( wp_create_nonce( 'save_decker_task_nonce' ) ); ?>',
			task_id: document.querySelector('input[name="task_id"]').value,
			title: document.getElementById('task-title').value,
			due_date: document.getElementById('task-due-date').value,
			board: document.getElementById('task-board').value,
			stack: document.getElementById('task-stack').value,
			author: document.getElementById('task-author').value,
			assignees: selectedAssigneesValues,
			labels: selectedLabelsValues,
			description: quill.root.innerHTML,
			max_priority: document.getElementById('task-max-priority').checked ? 1 : 0,
		};

		// Envía la solicitud AJAX
		const xhr = new XMLHttpRequest();
		xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

		xhr.onload = function() {
			if (xhr.status >= 200 && xhr.status < 400) {
				const response = JSON.parse(xhr.responseText);
				if (response.success) {
					// alert(response.data.message);

					const taskTodayElement = document.getElementById('task-today');
					if (taskTodayElement && !taskTodayElement.disabled) {
						// Obtiene el estado actual de 'today' y lo convierte a booleano
						let markForToday = taskTodayElement.checked;
						let taskId = response.data.task_id;

						// Llama a la función para marcar o desmarcar (invierte el estado actual)
						toggleMarkForToday(taskId, markForToday);

						// Actualiza el valor del elemento
						// taskTodayElement.value = (!today).toString();
					}

					if (taskModal) {

						var modalInstance = bootstrap.Modal.getInstance(taskModal);
						if (modalInstance) {
							modalInstance.hide();
						}


					  // TO-DO: Maybe will be better just close de the modal and update the the card.
					  // but we reload just for better debugging.
					  // element.closest('.card').remove();

					  // Reload the page if the request was successful
					  location.reload();   

					} else {

						// Redirecciona o actualiza según la respuesta
						window.location.href = '<?php echo esc_url( add_query_arg( 'decker_page', 'task', home_url( '/' ) ) ); ?>' + '&id=' + response.data.task_id;

					}

				} else {
					alert(response.data.message || 'Error al guardar la tarea.');
				}
			} else {
				console.error(<?php echo wp_json_encode( __( 'Server response error.', 'decker' ) ); ?>);
				alert(<?php echo wp_json_encode( __( 'An error occurred while saving the task.', 'decker' ) ); ?>);

			}
		};

		xhr.onerror = function() {
			console.error(<?php echo wp_json_encode( __( 'Request error.', 'decker' ) ); ?>);
			alert(<?php echo wp_json_encode( __( 'Error saving task.', 'decker' ) ); ?>);
		};

		const encodedData = Object.keys(formData)
			.map(key => encodeURIComponent(key) + '=' + encodeURIComponent(formData[key]))
			.join('&');

		xhr.send(encodedData);
	// });
}

</script>
