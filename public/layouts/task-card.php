<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'; // Because it can be loade in a iframe
require_once 'head-css.php';

$task_id = 0;
if (isset( $_GET['id'] ) ) {
	$task_id = intval( $_GET['id'] );
}
$task = new Task($task_id);

?>
<script type="text/javascript">
function loadComments() {
	const taskId = <?php echo json_encode( $task_id ); ?>;
	fetch(`/wp-json/wp/v2/comments?post=${taskId}`)
		.then(response => response.json())
		.then(comments => {
			const commentsList = document.getElementById('comments-list');
			const commentCount = document.getElementById('comment-count');
			commentsList.innerHTML = '';
			commentCount.textContent = comments.length;

			const nestedComments = comments.reduce((acc, comment) => {
				const parentId = comment.parent || 0;
				if (!acc[parentId]) acc[parentId] = [];
				acc[parentId].push(comment);
				return acc;
			}, {});

			function createCommentElement(comment) {
				const commentElement = document.createElement('div');
				commentElement.classList.add('d-flex', 'align-items-start', 'mb-2');
				commentElement.style.marginLeft = comment.parent ? '20px' : '0'; // Añadir margen para respuestas
				commentElement.innerHTML = `
					<img class="me-2 rounded-circle" src="${comment.author_avatar_urls[48]}" alt="Avatar" height="32" />
					<div class="w-100">
						<h5 class="mt-0">${comment.author_name} <small class="text-muted float-end">${new Date(comment.date).toLocaleString()}</small></h5>
						${comment.content.rendered}
						<br />
						${comment.author === <?php echo get_current_user_id(); ?> ? `<a href="javascript:void(0);" class="text-muted d-inline-block mt-2 comment-delete" data-comment-id="${comment.id}"><i class="ri-delete-bin-line"></i> Delete</a>` : ''}
						<a href="javascript:void(0);" class="text-muted d-inline-block mt-2 comment-reply" data-comment-id="${comment.id}"><i class="ri-reply-line"></i> Reply</a>
					</div>
				`;
				return commentElement;
			}

			function appendComments(parentElement, parentId) {
				if (nestedComments[parentId]) {
					nestedComments[parentId].forEach(comment => {
						const commentElement = createCommentElement(comment);
						parentElement.appendChild(commentElement);
						appendComments(commentElement, comment.id);
					});
				}
			}

			appendComments(commentsList, 0);

			// Add delete event listeners
			document.querySelectorAll('.comment-delete').forEach(button => {
				button.addEventListener('click', function() {
					const commentId = this.getAttribute('data-comment-id');
					deleteComment(commentId);
				});
			});
		});

		// Add reply event listeners
		document.querySelectorAll('.comment-reply').forEach(button => {
			button.addEventListener('click', function() {
				replyToCommentId = this.getAttribute('data-comment-id');
				const replyingTo = this.closest('.d-flex').querySelector('h5').textContent.trim();
				document.getElementById('replying-to').textContent = replyingTo;
				document.getElementById('reply-indicator').classList.remove('d-none');
			});
		});

		// Cancel reply
		document.getElementById('cancel-reply').addEventListener('click', function() {
			replyToCommentId = null;
			document.getElementById('reply-indicator').classList.add('d-none');
		});
}

var replyToCommentId = null;

document.addEventListener('DOMContentLoaded', function () {

	if (document.getElementById('submit-comment')) {
		document.getElementById('submit-comment').addEventListener('click', function() {
			const commentText = document.getElementById('comment-text').value;
			const taskId = <?php echo json_encode( $task_id ); ?>;
			const parentId = replyToCommentId;

			if (commentText.trim() === '') {
				alert('Please enter a comment.');
				return;
			}

			fetch('/wp-json/wp/v2/comments', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
				},
				body: JSON.stringify({
					post: taskId,
					content: commentText,
					parent: parentId
				})
			})
			.then(response => response.json())
			.then(data => {
				if (data.id) {
					document.getElementById('comment-text').value = '';
					loadComments();
					replyToCommentId = null; // Reset replyToCommentId after successful submission
					alert('Failed to add comment.');
				}
			});
		});
	}
});

// Borrar comentario
function deleteComment(commentId) {
	fetch(`/wp-json/wp/v2/comments/${commentId}`, {
		method: 'DELETE',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
		}
	})
	.then(response => response.json())
	.then(data => {
		if (data.deleted) {
			loadComments();
		} else {
			alert('Failed to delete comment.');
		}
	});
}
</script>

<!-- Task card -->
<form id="task-form" class="needs-validation" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
	<input type="hidden" name="action" value="save_decker_task">
	<input type="hidden" name="task_id" value="<?php echo esc_attr( $task_id ); ?>">
	<div class="row">
		<!-- Título -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="task-title" value="<?php echo esc_attr( $task->title ); ?>" placeholder="Título de la tarea" required>
				<label for="task-title" class="form-label">Title<span id="high-label" class="badge bg-danger ms-2 d-none">MAXIMUM PRIORITY</span></label>
				<div class="invalid-feedback">Please provide a title.</div>
			</div>
		</div>

		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<input class="form-control" id="task-due-date" type="date" name="date" value="<?php echo esc_attr( $task->due_date ); ?>" placeholder="Seleccionar fecha" required>
				<label class="form-label" for="task-due-date">Due Date</label>
				<div class="invalid-feedback">Please select a due date.</div>
			</div>
		</div>
	</div>

	<!-- Tablero y Columna -->
	<div class="row">
		<div class="col-md-6 mb-3">
			<div class="form-floating">
				<select class="form-select" id="task-board" required>
					<option value="" disabled selected>Select Board</option>
					<?php
					$boards = BoardManager::getAllBoards();

					foreach ( $boards as $board ) {
						$selected = ( $task->board->id == $board->id ) ? 'selected' : '';
						$selected = ( isset( $task->board->id ) && $task->board->id == $board->id ) ? 'selected' : '';
						echo '<option value="' . esc_attr( $board->id ) . '" ' . $selected . '>' . esc_html( $board->name ) . '</option>';
					}
					?>

				</select>
				<label for="task-board" class="form-label">Board</label>
				<div class="invalid-feedback">Please select a board.</div>

			</div>
		</div>
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<select class="form-select" id="task-column" required>
					<option value="to-do" <?php selected( $task->stack, 'to-do' ); ?>>To Do</option>
					<option value="in-progress" <?php selected( $task->stack, 'in-progress' ); ?>>In Progress</option>
					<option value="done" <?php selected( $task->stack, 'done' ); ?>>Done</option>
				</select>
				<label for="task-column" class="form-label">Column</label>
			</div>
		</div>
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<select class="form-select" id="task-author" required>
					<option value="" disabled selected>Select Author</option>
					<?php
					$users = get_users();
					foreach ( $users as $user ) {
						$selected = ( $user->ID == $task->author ) ? 'selected' : '';
						echo '<option value="' . esc_attr( $user->ID ) . '" ' . $selected . '>' . esc_html( $user->display_name ) . '</option>';
					}
					?>
				</select>
				<label for="task-author" class="form-label">Author</label>
				<div class="invalid-feedback">Please select an author.</div>				
			</div>
		</div>
	</div>


	<!-- Asignados y Etiquetas con ejemplos preseleccionados -->
	<div class="row">




		<div class="mb-3">
			<label for="task-assignees" class="form-label">Assign to</label>
			<select class="form-select" id="task-assignees" multiple>
				<?php
				foreach ( $users as $user ) {
		            $selected = in_array($user->ID, array_column($task->assigned_users, 'ID')) ? 'selected' : '';
					echo '<option value="' . esc_attr( $user->ID ) . '" ' . $selected . '>' . esc_html( $user->display_name ) . '</option>';
				}
				?>
			</select>
		</div>
		<div class="mb-3">
			<label for="task-labels" class="form-label">Labels</label>
			<select class="form-select" id="task-labels" multiple>
				<?php
					$labels = LabelManager::getAllLabels();
					foreach ( $labels as $label ) {
			            $selected = in_array($label->id, array_column($task->labels, 'id')) ? 'selected' : '';
						echo '<option value="' . esc_attr( $label->id ) . '" data-choice-custom-properties=\'{"color": "' . esc_attr( $label->color ) . '"}\' ' . $selected . '>' . esc_html( $label->name ) . '</option>';
					}
				?>
			</select>
		</div>
	</div>


	<!-- Pestañas: Descripción, Comentarios y Adjuntos -->
	<ul class="nav nav-tabs nav-bordered mb-3">
		<li class="nav-item">
			<a href="#description-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link active">Description
			</a>
		</li>
		<li class="nav-item">
			<a href="#comments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link <?php echo $task_id === 0 ? 'disabled' : ''; ?>">
				Comments
			   <span class="badge bg-light text-dark" id="comment-count">0</span>

			</a>
		</li>
		<li class="nav-item">
			<a href="#attachments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link <?php echo $task_id === 0 ? 'disabled' : ''; ?>">Attachments 
			<span class="badge bg-light text-dark">0</span>
			</a>
		</li>
		<li class="nav-item">
			<a href="#history-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link <?php echo $task_id === 0 ? 'disabled' : ''; ?>">History

			<span class="badge bg-light text-dark">0</span>
			</a>
		</li>
		<li class="nav-item">
			<a href="#gantt-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link <?php echo $task_id === 0 ? 'disabled' : ''; ?>">Gantt</a>
		</li>
	</ul>

	<div class="tab-content">
		<!-- Descripción (Editor Quill) -->
		<div class="tab-pane show active" id="description-tab">
			<div id="editor" style="height: 200px;"><?php echo $task->description; ?></div>
		</div>

		<!-- Comentarios -->
		<div class="tab-pane" id="comments-tab">
			<?php if ( $task_id > 0 ) { ?>
			<div id="comments-list">
				<!-- Los comentarios se cargarán aquí -->
			</div>
			<div class="border rounded mt-4">
				<div class="comment-area-box">
					<div id="reply-indicator" class="p-2 bg-light text-secondary d-none">
						Replying to <span id="replying-to"></span>
						<button type="button" class="btn-close float-end" id="cancel-reply"></button>
					</div>
					<textarea rows="3" class="form-control border-0 resize-none" placeholder="Write your comment..." id="comment-text" name="comment-text"></textarea>
					<div class="invalid-feedback">Please enter a comment.</div>
					<div class="p-2 bg-light d-flex justify-content-between align-items-center" id="comment-actions">
						<button type="button" class="btn btn-sm btn-success" id="submit-comment"><i class="ri-send-plane-2 me-1"></i> Send</button>
					</div>
				</div>
			</div>
			<?php } ?>
		</div>

		<!-- Adjuntos -->
		<div class="tab-pane" id="attachments-tab">
			<ul class="list-group mt-3">
				<li class="list-group-item d-flex justify-content-between align-items-center"><a href="#">
					file-2.pdf <i class="bi bi-box-arrow-up-right ms-2"></i></a>
					<div>
						<button class="btn btn-sm btn-danger me-2">Delete</button>
					</div>
				</li>
			</ul>
			<br>
			<div class="d-flex align-items-center">
				<input type="file" id="file-input" class="form-control me-2" />
				<button class="btn btn-sm btn-success" id="upload-file">Upload</button>
			</div>
		</div>

		<!-- Historial -->
		<div class="tab-pane" id="history-tab">
			<ul class="list-group">
				<li class="list-group-item">User 1 completed the task on 09/01/2023</li>
				<li class="list-group-item">User 2 reviewed the task on 09/02/2023</li>
			</ul>
		</div>

		<!-- Gantt -->
		<div class="tab-pane" id="gantt-tab">
			<p class="text-muted">Under construction...</p>
		</div>
	</div>

	<!-- Switch de Prioridad Máxima y Botones de Archive y Guardar -->
	<div class="d-flex justify-content-end align-items-center mt-3">
		<div class="form-check form-switch me-3">
			<input class="form-check-input" type="checkbox" id="task-max-priority" onchange="togglePriorityLabel(this)" <?php echo ( $task->max_priority ? 'checked' : '' ); ?> <?php echo current_user_can( 'administrator' ) ? '' : 'disabled'; ?>>
			<label class="form-check-label" for="task-max-priority">Maximum Priority</label>
		</div>
		<button type="button" class="btn btn-secondary me-2" id="archive-task" <?php echo $task_id === 0 ? 'disabled' : ''; ?>>
			<i class="ri-archive-line"></i> Archive
		</button>
		<div class="btn-group me-2">
			<button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
				<i class="ri-more-2-fill"></i>
			</button>
			<ul class="dropdown-menu">
				<li>
					<a class="dropdown-item <?php echo $task_id > 0 ? '' : 'disabled'; ?>" href="<?php echo $task_id > 0 ? get_edit_post_link( $task_id ) : '#'; ?>">
						<i class="ri-wordpress-line me-1"></i> Edit in WordPress
					</a>
				</li>
			</ul>
		</div>
		<button type="submit" class="btn btn-primary" id="save-task">
			<i class="ri-save-line"></i> Save
		</button>
	</div>
</form>

<script type="text/javascript">

document.addEventListener('DOMContentLoaded', function() {
	initializeTaskPage();
});


function initializeTaskPage() {
	// Verificar si el task_id está presente en data-task-id
	const taskElement = document.querySelector(`[data-task-id="${<?php echo json_encode( $task_id ); ?>}"]`);
	if (taskElement) {
		console.log('Task ID found in data-task-id:', taskElement.getAttribute('data-task-id'));


		// Cargar comentarios al iniciar
		loadComments();


	} else {
		console.log('Task ID not found in data-task-id');
	}


	if (document.getElementById('editor')) {
		var quill = new Quill('#editor', {
			theme: 'snow',
			modules: {
				toolbar: [
					[{ 'header': [1, 2, false] }],
					['bold', 'italic', 'underline', 'strike'],
					[{ 'color': [] }, { 'background': [] }],
					['link', 'blockquote', 'code-block', 'image'],
					[{ 'list': 'ordered' }, { 'list': 'bullet' }, { 'list': 'check' }],
					[{ 'indent': '-1' }, { 'indent': '+1' }], // Disminuir y aumentar sangría
					['clean'],
				]
			}
		});
	}

	// Inicializar Choices.js para los selectores de asignados y etiquetas
	if (document.getElementById('task-assignees')) {
		const assigneesSelect = new Choices('#task-assignees', { removeItemButton: true});
	}


	if (document.getElementById('task-labels')) {
		const labelsSelect = new Choices('#task-labels', { removeItemButton: true, allowHTML: true });
	}



	var uploadFileButton = document.getElementById('upload-file');
	if (uploadFileButton) {
		uploadFileButton.addEventListener('click', function () {
			var fileInput = document.getElementById('file-input').value;
			if (fileInput) {
				alert('File uploaded: ' + fileInput);
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

</script>
