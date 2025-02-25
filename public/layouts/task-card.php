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
		$parent_dir = dirname( $dir );
		if ( $parent_dir === $dir ) {
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


// Initialize variables from URL parameters for new tasks.
$task_id = 0;
$board_slug = '';
$initial_title = '';
$initial_description = '';
$initial_stack = 'to-do';
$initial_max_priority = false;

if ( isset( $_GET['id'] ) ) {
	$task_id = intval( $_GET['id'] );
}

// Handle URL parameters for new task creation.
if ( isset( $_GET['type'] ) && 'new' === $_GET['type'] ) {
	if ( isset( $_GET['title'] ) ) {
		$initial_title = sanitize_text_field( wp_unslash( $_GET['title'] ) );
	}
	if ( isset( $_GET['description'] ) ) {
		$initial_description = wp_kses( wp_unslash( $_GET['description'] ), Decker::get_allowed_tags() );
	}
	if ( isset( $_GET['board'] ) ) {
		$board_slug = sanitize_text_field( wp_unslash( $_GET['board'] ) );
	}
	if ( isset( $_GET['stack'] ) ) {
		$initial_stack = sanitize_text_field( wp_unslash( $_GET['stack'] ) );
	}
	if ( isset( $_GET['maximum_priority'] ) && '1' === $_GET['maximum_priority'] ) {
		$initial_max_priority = true;
	}
}

$task = new Task( $task_id );

// If no board_slug from new task parameters, try to get it from GET.
if ( empty( $board_slug ) && isset( $_GET['slug'] ) ) {
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

<!-- Task card -->
<form id="task-form" class="needs-validation" target="_self" novalidate>
	<input type="hidden" name="action" value="save_decker_task">
	<input type="hidden" name="task_id" value="<?php echo esc_attr( $task_id ); ?>">
	<div class="row">

		<!-- Title -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="task-title" value="<?php echo esc_attr( $task_id ? $task->title : $initial_title ); ?>" placeholder="<?php esc_attr_e( 'Task title', 'decker' ); ?>" required <?php disabled( $disabled ); ?>>
				<label for="task-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?><span id="high-label" class="badge bg-danger ms-2 d-none"><?php esc_html_e( 'MAXIMUM PRIORITY', 'decker' ); ?></span></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
			</div>
		</div>

		<!-- Maximum priority and For today -->
		<div class="col-md-3 mb-2 d-flex flex-column align-items-start">
			<div class="form-check form-switch mb-2">
				<input class="form-check-input" type="checkbox" id="task-max-priority" onchange="togglePriorityLabel(this)" <?php checked( $task_id ? $task->max_priority : $initial_max_priority ); ?> <?php disabled( $disabled ); ?>>
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

		
		<!-- Responsable -->
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<select class="form-select" id="task-responsable" required <?php disabled( $disabled ); ?>>
					<option value="" disabled selected><?php esc_html_e( 'Select Responsable', 'decker' ); ?></option>
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

					$responsable_id = $task_id ? $task->responsable->ID : get_current_user_id();

					foreach ( $users as $user ) {
						echo '<option value="' . esc_attr( $user->ID ) . '" '
							. selected( $user->ID, $responsable_id, false ) . '>'
							. esc_html( $user->display_name ) . '</option>';
					}
					?>
				</select>
				<label for="task-responsable" class="form-label"><?php esc_html_e( 'Responsable', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please select a responsable.', 'decker' ); ?></div>				
			</div>
		</div>

		<!-- Stack -->
		<div class="col-md-2 mb-2">
			<div class="form-floating">
				<select class="form-select" id="task-stack" required <?php disabled( $disabled ); ?>>
					<option value="to-do" <?php selected( $task_id ? $task->stack : $initial_stack, 'to-do' ); ?>><?php esc_html_e( 'To Do', 'decker' ); ?></option>
					<option value="in-progress" <?php selected( $task_id ? $task->stack : $initial_stack, 'in-progress' ); ?>><?php esc_html_e( 'In Progress', 'decker' ); ?></option>
					<option value="done" <?php selected( $task_id ? $task->stack : $initial_stack, 'done' ); ?>><?php esc_html_e( 'Done', 'decker' ); ?></option>
				</select>
				<label for="task-stack" class="form-label"><?php esc_html_e( 'Stack', 'decker' ); ?></label>
			</div>
		</div>

		<!-- Due date -->
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<input class="form-control" id="task-due-date" type="date" name="date" value="<?php echo esc_attr( $task->get_formatted_date() ); ?>" placeholder="<?php esc_attr_e( 'Select date', 'decker' ); ?>" required <?php disabled( $disabled ); ?>>
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
		<li class="nav-item">
			<a href="#info-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link<?php echo ( 0 === $task_id ) ? ' disabled' : ''; ?>" <?php disabled( 0 === $task_id ); ?>><?php esc_html_e( 'Information', 'decker' ); ?></a>
		</li>
	</ul>

	<div class="tab-content">
		<!-- Description (Quill Editor) -->
		<div class="tab-pane show active" id="description-tab">
			<div id="editor-container">
				<div id="editor" style="height: 200px;"><?php echo wp_kses( $task_id ? $task->description : $initial_description, Decker::get_allowed_tags() ); ?></div>
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

		<!-- Information -->
		<div class="tab-pane" id="info-tab">
			<div class="row mt-3">
				<!-- Hidden Status -->
				<div class="col-12 mb-3">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" id="task-hidden" <?php checked( $task->hidden ); ?> <?php disabled( $disabled ); ?>>
						<label class="form-check-label" for="task-hidden">
							<?php esc_html_e( 'Hidden task', 'decker' ); ?>
							<small class="text-muted"><?php esc_html_e( '(Hidden tasks are not shown in task listings)', 'decker' ); ?></small>
						</label>
					</div>
				</div>
				<!-- Creation Date -->
				<div class="col-md-6 mb-3">
					<div class="form-floating">
						<input type="text" class="form-control" id="task-created" 
							value="<?php echo esc_attr( get_the_date( 'Y-m-d H:i:s', $task_id ) ); ?>" 
							readonly>
						<label for="task-created" class="form-label">
							<?php esc_html_e( 'Created', 'decker' ); ?>
						</label>
					</div>
				</div>

				<!-- Author (Read-only unless admin) -->
				<div class="col-md-6 mb-3">
					<div class="form-floating">
						<select class="form-select" id="task-author-info" <?php disabled( ! current_user_can( 'manage_options' ) ); ?>>
							<?php
							$author_id = get_post_field( 'post_author', $task_id );
							$users = get_users( array( 'orderby' => 'display_name' ) );
							foreach ( $users as $user ) {
								echo '<option value="' . esc_attr( $user->ID ) . '" ' .
									selected( $user->ID, $author_id, false ) . '>' .
									esc_html( $user->display_name ) . '</option>';
							}
							?>
						</select>
						<label for="task-author-info" class="form-label">
							<?php esc_html_e( 'Author', 'decker' ); ?>
						</label>
					</div>
				</div>
			</div>
		</div>

	</div>


	<!-- Switch de Prioridad Máxima y Botones de Archive y Guardar -->
	<div class="d-flex justify-content-end align-items-center mt-3">


		<div class="btn-group mb-2 dropup">
			<button type="submit" class="btn btn-primary" id="save-task" onclick="sendFormByAjax(event);" disabled>
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


