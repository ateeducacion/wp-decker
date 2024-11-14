<?php
/**
 * Function to find and include wp-load.php dynamically.
 *
 * @param int $max_levels Maximum number of directory levels to traverse upward.
 * @return bool Returns true if wp-load.php is found and included, otherwise false.
 */
function include_wp_load($max_levels = 10) {
    $dir = __DIR__;
    for ($i = 0; $i < $max_levels; $i++) {
        if (file_exists($dir . '/wp-load.php')) {
            require_once $dir . '/wp-load.php';
            return true;
        }
        // Move up one level in the directory structure
        $parent_dir = dirname($dir);
        if ($parent_dir === $dir) {
            // Reached the root directory of the file system
            break;
        }
        $dir = $parent_dir;
    }
    return false;
}

// Attempt to include wp-load.php, required when we are loading the task-card in a Bootstrap modal.
if (!include_wp_load()) { 
    exit('Error: wp-load.php not found.');
}


$task_id = 0;
if (isset( $_GET['id'] ) ) {
	$task_id = intval( $_GET['id'] );
}
$task = new Task($task_id);

$board_slug = "";
if (isset( $_GET['slug'] ) ) {
	$board_slug = $_GET['slug'];
}

$disabled = false;
if ($task_id > 0 && $task->status == 'archived') {
	$disabled = true;
}

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
<form id="task-form" class="needs-validation" target="_self" novalidate>
	<input type="hidden" name="action" value="save_decker_task">
	<input type="hidden" name="task_id" value="<?php echo esc_attr( $task_id ); ?>">
	<div class="row">
		<!-- Título -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="task-title" value="<?php echo esc_attr( $task->title ); ?>" placeholder="Título de la tarea" required <?php disabled($disabled); ?>>
				<label for="task-title" class="form-label">Title<span id="high-label" class="badge bg-danger ms-2 d-none">MAXIMUM PRIORITY</span></label>
				<div class="invalid-feedback">Please provide a title.</div>
			</div>
		</div>

		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<input class="form-control" id="task-due-date" type="date" name="date" value="<?php echo esc_attr( $task->getDuedateAsString() ); ?>" placeholder="Seleccionar fecha" required <?php disabled($disabled); ?>>
				<label class="form-label" for="task-due-date">Due Date</label>
				<div class="invalid-feedback">Please select a due date.</div>
			</div>
		</div>
	</div>

	<!-- Tablero y Columna -->
	<div class="row">
		<div class="col-md-6 mb-3">
			<div class="form-floating">
				<?php //TODO: Allow changing the board. ?>
				<select class="form-select" id="task-board" required <?php disabled($disabled || $task_id > 0); ?>>
					<option value="" disabled selected>Select Board</option>
					<?php

						$boards = BoardManager::getAllBoards();

						foreach ( $boards as $board ) {
						    echo '<option value="' . esc_attr( $board->id ) . '" ' . selected( $task->board && $task->board->id == $board->id ) . ' ' . selected( $board_slug, $board->slug ) . '>' . esc_html( $board->name ) . '</option>';
						}
					?>

				</select>
				<label for="task-board" class="form-label">Board</label>
				<div class="invalid-feedback">Please select a board.</div>

			</div>
		</div>
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<select class="form-select" id="task-column" required <?php disabled($disabled); ?>>
					<option value="to-do" <?php selected( $task->stack, 'to-do' ); ?>>To Do</option>
					<option value="in-progress" <?php selected( $task->stack, 'in-progress' ); ?>>In Progress</option>
					<option value="done" <?php selected( $task->stack, 'done' ); ?>>Done</option>
				</select>
				<label for="task-column" class="form-label">Column</label>
			</div>
		</div>
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<!-- Author always disabled -->
				<select class="form-select" id="task-author" required <?php disabled(true); ?>>
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
			<select class="form-select" id="task-assignees" multiple <?php disabled($disabled); ?>>
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
			<select class="form-select" id="task-labels" multiple <?php disabled($disabled); ?>>
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
			<a href="#comments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link" <?php disabled( $task_id === 0 ); ?>>Comments
			   <span class="badge bg-light text-dark" id="comment-count">0</span>

			</a>
		</li>
		<li class="nav-item">
			<a href="#attachments-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link" <?php disabled( $task_id === 0 ); ?>>Attachments 

			<?php
			// Obtener los adjuntos asociados con la tarea
			
			$attachments = get_attached_media( '', $task_id );			
			// $attachments = is_array( $attachments ) ? $attachments : array();

			?>



			<span class="badge bg-light text-dark"><?php echo count( $attachments ); ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a href="#history-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link" <?php disabled( $task_id === 0 ); ?>>History

			<!-- <span class="badge bg-light text-dark">0</span> -->
			</a>
		</li>
		<li class="nav-item">
			<a href="#gantt-tab" data-bs-toggle="tab" aria-expanded="false" class="nav-link" <?php disabled( $task_id === 0 ); ?>>Gantt</a>
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
						<button type="button" class="btn btn-sm btn-success" id="submit-comment" disabled><i class="ri-send-plane-2 me-1"></i> Send</button>
					</div>
				</div>
			</div>
			<?php } ?>
		</div>

			<!-- Adjuntos -->
			<div class="tab-pane" id="attachments-tab">
			    <ul class="list-group mt-3" id="attachments-list">
			        <?php foreach ( $attachments as $attachment ) : 
			            $attachment_url = $attachment->guid;

			            // Obtener el nombre original si está disponible
			            $original_filename = get_post_meta( $attachment->ID, '_original_filename', true );
			            $display_name = ! empty( $original_filename ) ? $original_filename : get_the_title( $attachment->ID );


			            ?>
			            <li class="list-group-item d-flex justify-content-between align-items-center" data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
			                <a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank">
			                    <?php echo esc_html( $display_name ); ?> <i class="bi bi-box-arrow-up-right ms-2"></i>
			                </a>
			                <div>
			                    <button type="button" class="btn btn-sm btn-danger me-2 remove-attachment" <?php echo $disabled ? 'disabled' : ''; ?>>Delete</button>
			                </div>
			            </li>
			        <?php endforeach; ?>
			    </ul>
			    <br>
			    <div class="d-flex align-items-center">
			        <input type="file" id="file-input" class="form-control me-2" <?php echo $disabled ? 'disabled' : ''; ?> novalidate />
			        <button class="btn btn-sm btn-success" id="upload-file" <?php echo $disabled ? 'disabled' : ''; ?>>Upload</button>
			    </div>
			</div>

		
		<!-- Adjuntos
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
		</div> -->

		<!-- Historial -->
		<div class="tab-pane" id="history-tab">

			<table id="user-history-table" class="table table-bordered table-striped table-hover table-sm">
			    <thead>
			        <tr>
			            <th>Avatar</th>
			            <th>Nickname</th>
			            <th>Full Name</th>
			            <th>Date</th>
			        </tr>
			    </thead>
			    <tbody>
			        <?php
			        $history = $task->get_user_history_with_objects();
			        $timelineData = [];
			        foreach ($history as $record) {
			            $user = $record['user'];
			            $avatar = get_avatar($user->ID, 32); // Get WordPress avatar
			            $nickname = esc_html($user->nickname);
			            $full_name = esc_attr($user->first_name . ' ' . $user->last_name); // Assuming first and last name exist
			            $date = esc_html($record['date']);

			            echo '<tr>';
			            echo '<td>' . $avatar . '</td>';
			            echo '<td>' . $nickname . '</td>';
			            echo '<td title="' . $full_name . '">' . $full_name . '</td>';
			            echo '<td>' . $date . '</td>';
			            echo '</tr>';


		               // Prepare data for the Timeline Chart
		                $timelineData[] = [
		                    'nickname' => $nickname,
		                    'date' => $record['date']
		                ];

			        }

            // Convert PHP array to JSON for use in JavaScript
            $timelineDataJson = json_encode($timelineData);


			        ?>
			    </tbody>
			</table>



<!-- 			<ul class="list-group">
				<li class="list-group-item">User 1 completed the task on 09/01/2023</li>
				<li class="list-group-item">User 2 reviewed the task on 09/02/2023</li>
			</ul> -->
		</div>

		<!-- Gantt -->



	<!-- Gantt -->
	<div class="tab-pane" id="gantt-tab">
		<div class="tab-pane" id="gantt-tab">
		    <?php if (!empty($timelineData)) : ?>
		        <div class="d-flex justify-content-center">
		            <div id="timeline-container" class="w-100" style="height: 400px; overflow: auto;">
		                <div id="timeline" style="width: 100%; height: 100%;"></div>		    
		            </div>
		        </div>
		    <?php else : ?>
		        <p class="text-muted">No hay datos disponibles para mostrar el diagrama de Gantt.</p>
		    <?php endif; ?>
		</div>
	</div>

</div>


<?php if (!empty($timelineData)) : ?>
    <!-- <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script> -->
    <script type="text/javascript">

    google.charts.load('current', {'packages':['timeline']});
    google.charts.setOnLoadCallback(function() {
        // No dibujar el gráfico aquí; esperar a que el modal se muestre
    });

function drawChart() {
    // Datos pasados desde PHP
    var rawData = <?php echo $timelineDataJson; ?>;

    function groupConsecutiveDatesForTimeline(data) {
        var groupedData = {};

        data.forEach(function(record) {
            var user = record.nickname;
            var dateParts = record.date.split('-');
            var date = new Date(
                parseInt(dateParts[0]),
                parseInt(dateParts[1]) - 1,
                parseInt(dateParts[2])
            );

            if (!groupedData[user]) {
                groupedData[user] = [];
            }

            groupedData[user].push(date);
        });

        var tasks = [];

        for (var user in groupedData) {
            var dates = groupedData[user];
            dates.sort(function(a, b) { return a - b; });

            var startDate = dates[0];
            var endDate = dates[0];

            for (var i = 1; i <= dates.length; i++) {
                var currentDate = dates[i];
                var previousDate = dates[i - 1];
                if (currentDate && (currentDate - previousDate === 86400000)) {
                    // Fechas consecutivas
                    endDate = currentDate;
                } else {
                    // No es consecutiva o no hay más fechas, guardar la tarea actual
                    tasks.push([
                        user,
                        '', // Puedes agregar más detalles aquí si lo deseas
                        new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate()),
                        new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate() + 1)
                    ]);
                    // Iniciar una nueva tarea si hay más fechas
                    if (currentDate) {
                        startDate = currentDate;
                        endDate = currentDate;
                    }
                }
            }
        }

        return tasks;
    }

    var container = document.getElementById('timeline');
    var chart = new google.visualization.Timeline(container);
    var dataTable = new google.visualization.DataTable();

    dataTable.addColumn({ type: 'string', id: 'User' });
    dataTable.addColumn({ type: 'string', id: 'Label' });
    dataTable.addColumn({ type: 'date', id: 'Start' });
    dataTable.addColumn({ type: 'date', id: 'End' });

    var tasks = groupConsecutiveDatesForTimeline(rawData);
    dataTable.addRows(tasks);

    var options = {
        timeline: { showRowLabels: true },
        height: container.parentElement.clientHeight, // Ajusta la altura basada en el contenedor
        width: '100%',
        chartArea: { width: '90%', height: '80%' }, // Ajusta el área del gráfico
    };

    chart.draw(dataTable, options);
}

// taskModal.addEventListener('shown.bs.modal', function () {
//     google.charts.setOnLoadCallback(drawChart);
// });

// Redibujar el gráfico al cambiar el tamaño de la ventana para mantener la responsividad
window.addEventListener('resize', function() {
    drawChart();
});


    </script>
<?php endif; ?>

	<!-- Switch de Prioridad Máxima y Botones de Archive y Guardar -->
	<div class="d-flex justify-content-end align-items-center mt-3">

		<div class="form-check form-switch me-3">
			<input class="form-check-input" type="checkbox" id="task-today" 
		       <?php checked( $task->is_current_user_today_assigned() ); ?> 
		       <?php disabled( $task_id !== 0 && ($disabled || !$task->is_current_user_assigned_to_task()) ); ?>>
			<label class="form-check-label" for="task-max-priority">Today <?php echo ($task->is_current_user_today_assigned()) ? '🔥' : '🪵'; ?></label>
		</div>

		<div class="form-check form-switch me-3">
			<input class="form-check-input" type="checkbox" id="task-max-priority" onchange="togglePriorityLabel(this)" <?php checked( $task->max_priority ); ?> <?php disabled($disabled ); ?>>
			<label class="form-check-label" for="task-max-priority">Maximum Priority</label>
		</div>
		<button type="button" class="btn btn-secondary me-2" id="archive-task" <?php disabled($disabled || $task_id === 0 ); ?>>
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
		<button type="submit" class="btn btn-primary" id="save-task" disabled>
			<i class="ri-save-line"></i> Save
		</button>
	</div>
</form>

<script type="text/javascript">

var quill;
var assigneesSelect;
var labelsSelect;

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
		quill = new Quill('#editor', {
			theme: 'snow',
			readOnly: <?php echo $disabled ? 'true' : 'false'; ?>,
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
		assigneesSelect = new Choices('#task-assignees', { removeItemButton: true});
	
        // Agregar el evento de cambio para los asignados
        assigneesSelect.passedElement.element.addEventListener('change', handleAssigneesChange);

	}


	if (document.getElementById('task-labels')) {
		labelsSelect = new Choices('#task-labels', { removeItemButton: true, allowHTML: true });
	}



    var uploadFileButton = document.getElementById('upload-file');
    if (uploadFileButton) {
        uploadFileButton.addEventListener('click', function () {
            var fileInput = document.getElementById('file-input');
            if (fileInput.files.length > 0) {
                uploadAttachment(fileInput.files[0]);
            } else {
                alert('Please select a file to upload.');
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


    const taskTodayCheckbox = document.getElementById('task-today');

	// Verifica si el checkbox está presente en la página

    if (taskTodayCheckbox) {
        taskTodayCheckbox.addEventListener('change', handleTaskTodayChange);

        // Estado inicial: si la casilla está marcada, asegurarse de que el usuario esté seleccionado
        if (taskTodayCheckbox.checked) {
            const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
            if (!selectedValues.includes(userId)) {
                assigneesSelect.setChoiceByValue(userId);
            }
        }
    }


    // if (taskTodayCheckbox) {
    //     // Listener para el cambio en el checkbox de 'task-today'
    //     taskTodayCheckbox.addEventListener('change', function() {
    //         if (this.checked) {
    //             // Agrega el usuario actual a los valores seleccionados si está marcado
    //             const userId = window.userId; // Usamos la constante global
    //             const currentAssignees = assigneesSelect.getValue().map(item => parseInt(item.value, 10));
                
    //             // Verifica si el userId ya está presente, si no, lo agrega
    //             if (!currentAssignees.includes(userId)) {
    //                 assigneesSelect.setValue([...currentAssignees, { value: userId, label: 'You' }]);
    //             }
    //         }
    //     });

    //     // Listener para cambios en la selección de asignados
    //     assigneesSelect.passedElement.element.addEventListener('change', function() {
    //         const selectedAssignees = assigneesSelect.getValue().map(item => parseInt(item.value, 10));
    //         // Si el userId no está más en la lista de seleccionados, desmarcar el checkbox
    //         if (!selectedAssignees.includes(window.userId)) {
    //             taskTodayCheckbox.checked = false;
    //         }
    //     });
    // }







	const saveButton = document.getElementById('save-task');

	// Function to enable save button when any field changes
    const enableSaveButton = function() {
        saveButton.disabled = false;

        // TO-DO: Finish this to prevent closing without saving
        // hasUnsavedChanges = true;
    };

	const form = document.getElementById('task-form');

    // Add event listeners to all form inputs
    const inputs = form.querySelectorAll('input, textarea, select');
    inputs.forEach(function(input) {
        input.addEventListener('change', enableSaveButton);
        input.addEventListener('input', enableSaveButton);
    });

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

}


// Función para manejar cambios en la casilla "task-today"
function handleTaskTodayChange(event) {
    if (event.target.checked) {
        // Verificar si el usuario ya está seleccionado
        const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
        if (!selectedValues.includes(userId)) {
            assigneesSelect.setChoiceByValue(userId);
        }
    }
    // Si se desmarca, no hacer nada
}

// Función para manejar cambios en los asignados
function handleAssigneesChange(event) {
    const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
    if (!selectedValues.includes(userId)) {
        const taskTodayCheckbox = document.getElementById('task-today');
        if (taskTodayCheckbox && taskTodayCheckbox.checked) {
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
    formData.append('task_id', <?php echo json_encode( $task_id ); ?>);
    formData.append('attachment', file);
    formData.append('nonce', '<?php echo wp_create_nonce( 'upload_attachment_nonce' ); ?>');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?php echo admin_url( 'admin-ajax.php' ); ?>', true);

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 400) {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                // Añadir el nuevo adjunto a la lista en la interfaz
                addAttachmentToList(response.data.attachment_id, response.data.attachment_url, response.data.attachment_title);
                // Limpiar el input de archivo
                document.getElementById('file-input').value = '';
            } else {
                alert(response.data.message || 'Error uploading attachment.');
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

function addAttachmentToList(attachmentId, attachmentUrl, attachmentTitle) {
    var attachmentsList = document.getElementById('attachments-list');
    var li = document.createElement('li');
    li.className = 'list-group-item d-flex justify-content-between align-items-center';
    li.setAttribute('data-attachment-id', attachmentId);

    li.innerHTML = `
        <a href="${attachmentUrl}" target="_blank">
            ${attachmentTitle} <i class="bi bi-box-arrow-up-right ms-2"></i>
        </a>
        <div>
            <button type="button" class="btn btn-sm btn-danger me-2 remove-attachment"<?php echo $disabled ? ' disabled' : ''; ?>>Delete</button>
        </div>
    `;

    attachmentsList.appendChild(li);
}

document.addEventListener('click', function(event) {
    if (event.target && event.target.classList.contains('remove-attachment')) {
        var listItem = event.target.closest('li');
        var attachmentId = listItem.getAttribute('data-attachment-id');
        deleteAttachment(attachmentId, listItem);
    }
});

function deleteAttachment(attachmentId, listItem) {
    if (!confirm('Are you sure you want to delete this attachment?')) {
        return;
    }

    var formData = new FormData();
    formData.append('action', 'delete_task_attachment');
    formData.append('task_id', <?php echo json_encode( $task_id ); ?>);
    formData.append('attachment_id', attachmentId);
    formData.append('nonce', '<?php echo wp_create_nonce( 'delete_attachment_nonce' ); ?>');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?php echo admin_url( 'admin-ajax.php' ); ?>', true);

    xhr.onload = function() {
        if (xhr.status >= 200 && xhr.status < 400) {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                // Eliminar el adjunto de la lista en la interfaz
                listItem.remove();
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
    taskModal.addEventListener('shown.bs.modal', function () {
        const formModal = taskModal.querySelector('#task-form');

        if (formModal && !formModal.dataset.listener) {
            formModal.dataset.listener = 'true';

            formModal.addEventListener('submit', function(event) {
                sendFormByAjax(event);
            });
        }

    google.charts.setOnLoadCallback(drawChart);



// taskModal.addEventListener('shown.bs.modal', function () {
//     google.charts.setOnLoadCallback(drawChart);
// });

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
            nonce: '<?php echo  wp_create_nonce( 'save_decker_task_nonce' ); ?>',
            task_id: document.querySelector('input[name="task_id"]').value,
            title: document.getElementById('task-title').value,
            due_date: document.getElementById('task-due-date').value,
            board: document.getElementById('task-board').value,
            stack: document.getElementById('task-column').value,
            author: document.getElementById('task-author').value,
            assignees: selectedAssigneesValues,
            labels: selectedLabelsValues,
            description: quill.root.innerHTML,
            max_priority: document.getElementById('task-max-priority').checked ? 1 : 0,
        };

        // Envía la solicitud AJAX
        const xhr = new XMLHttpRequest();
        xhr.open('POST', '<?php echo admin_url( 'admin-ajax.php' ); ?>', true);
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


                    } else {

		                // Redirecciona o actualiza según la respuesta
		                window.location.href = '<?php echo esc_url( add_query_arg( 'decker_page', 'task', home_url( '/' ) ) ); ?>' + '&id=' + response.data.task_id;

					}

                } else {
                    alert(response.data.message || 'Error al guardar la tarea.');
                }
            } else {
                console.error('Error en la respuesta del servidor.');
                alert('Ocurrió un error al guardar la tarea.');
            }
        };

        xhr.onerror = function() {
            console.error('Error en la solicitud.');
            alert('Ocurrió un error al guardar la tarea.');
        };

        const encodedData = Object.keys(formData)
            .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(formData[key]))
            .join('&');

        xhr.send(encodedData);
    // });
}

    // // Opcional: Habilitar el botón de enviar cuando se completa el textarea de comentarios
    // const commentText = document.getElementById('comment-text');
    // const submitCommentButton = document.getElementById('submit-comment');

    // if (commentText) {
    //     commentText.addEventListener('input', function() {
    //         if (commentText.value.trim() !== '') {
    //             submitCommentButton.disabled = false;
    //         } else {
    //             submitCommentButton.disabled = true;
    //         }
    //     });
    // }



</script>
