<?php
include 'layouts/main.php';

$taskManager = new TaskManager();

?>

<head>
	<title><?php _e('Tasks', 'decker'); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>

	<style type="text/css">
		/* Dropdown menu styles */
		.dropdown-toggle::after {
			display: none;
		}
		
		.dropdown-toggle {
			color: #98a6ad;
			text-decoration: none;
		}
		
		.dropdown-toggle:hover {
			color: #323a46;
		}
		
		.dropdown-menu {
			min-width: 120px;
		}
		
		.dropdown-item {
			padding: 0.4rem 1.2rem;
		}
		
		.dropdown-item i {
			font-size: 15px;
		}
		

#searchBuilderContainer .dt-button,
#boardFilter {
	height: 38px; /* Asegura que tengan la misma altura */
	padding: 6px 12px; /* Ajusta el padding para alinearlo con los formularios de Bootstrap */
	font-size: 14px; /* Mantiene una tipografía coherente */
	line-height: 1.5;
	border-radius: 4px; /* Bordes redondeados */
	border: 1px solid #ced4da; /* Color de borde coherente */
	background-color: #f8f9fa; /* Fondo claro para los botones */
	color: #495057; /* Texto coherente con el tema */
	white-space: nowrap; /* Evita que el texto se desborde */
	min-width: 120px; /* Ancho mínimo para asegurar que el texto no se corte */
}

#searchBuilderContainer .dt-button:hover,
#boardFilter:hover {
	background-color: #e2e6ea; /* Fondo al pasar el cursor */
	color: #212529; /* Texto al pasar el cursor */
	border-color: #dae0e5; /* Borde al pasar el cursor */
}

#searchBuilderContainer {
	display: flex;
	align-items: center;
}

#searchBuilderContainer .dt-button {
	margin-right: 8px; /* Espacio entre botones */
	width: auto; /* Ajusta el ancho según el contenido */
	text-align: center; /* Centra el texto dentro del botón */
}

.dataTables_wrapper .dataTables_length {
	margin-bottom: 16px; /* Espacio inferior */
}

.dataTables_wrapper .dataTables_length select {
	width: auto; /* Ajusta el ancho al contenido */
	display: inline-block; /* Alineación adecuada */
	margin-right: 10px; /* Espacio entre el select y otros elementos */
}

.dataTables_wrapper .dataTables_filter {
	margin-bottom: 16px; /* Espacio inferior */
}





/* Asegura que la tabla sea responsiva */
.table-responsive {
	overflow-x: auto;
}

table#tablaTareas {
	width: 100%;
	table-layout: auto; /* Hace que las columnas se ajusten al contenido */
}

table#tablaTareas th,
table#tablaTareas td {
	white-space: nowrap; /* Evita el desbordamiento del texto */
	word-break: break-word; /* Rompe las palabras largas para evitar el desbordamiento */
}

table#tablaTareas td:nth-child(4) {
	max-width: 200px; /* Ancho máximo para la columna de descripción */
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Si deseas que se oculte alguna columna en móviles, puedes hacer uso de display: none; */
@media (max-width: 768px) {
	.d-none.d-md-table-cell {
		display: none !important;
	}
}




	</style>
</head>
<body <?php body_class(); ?>>
	<!-- Begin page -->
	<div class="wrapper">

		<?php include 'layouts/menu.php'; ?>

		<!-- ============================================================== -->
		<!-- Start Page Content here -->
		<!-- ============================================================== -->

		<div class="content-page">
			<div class="content">

				<!-- Start Content-->
				<div class="container-fluid">

					<div class="row">
						<div class="col-xxl-12">
							<!-- start page title -->





							<div class="page-title-box d-flex align-items-center justify-content-between">
							
							<?php
								$current_type = isset( $_GET['decker_page'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'tasks';

								$page_title = __('Tasks', 'decker');
								$class_disabled = '';
								if ( $current_type === 'active' ) {
								    $page_title = __('Active Tasks', 'decker');
								} elseif ( $current_type === 'my' ) {
								    $page_title = __('My Tasks', 'decker');
								} elseif ( $current_type === 'archived' ) {
								    $page_title = __('Archived Tasks', 'decker');
								    $class_disabled = ' disabled';
								}
							?>
								<h4 class="page-title"><?php echo esc_html( $page_title ); ?> <a href="<?php echo add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ); ?>" class="btn btn-success btn-sm ms-3 <?php echo esc_attr($class_disabled); ?>" data-bs-toggle="modal" data-bs-target="#task-modal"><?php _e('Add New', 'decker'); ?></a></h4>


	



								<div class="d-flex align-items-center">
									<div id="searchBuilderContainer" class="me-2"></div>
									<select id="boardFilter" class="form-select">
										<option value=""><?php _e('All Boards', 'decker'); ?></option>
										<?php
											$boards = BoardManager::getAllBoards();
											foreach ($boards as $board) {
											    echo '<option value="' . esc_attr($board->name) . '">' . esc_html($board->name) . '</option>';
											}
										?>
									</select>
								</div>
							</div>
							<!-- end page title -->

<?php include 'layouts/top-alert.php'; ?>

							<div class="row">
								<div class="col-12">
									<div class="card">
										<div class="card-body table-responsive">

											<table id="tablaTareas" class="table table-striped table-bordered dataTable no-footer dt-responsive nowrap w-100" aria-describedby="tablaTareas_info">
												<thead>
													<tr>
														<th><?php _e('P.', 'decker'); ?></th>
														<th><?php _e('Board', 'decker'); ?></th>
														<th><?php _e('Stack', 'decker'); ?></th>
														<th><?php _e('Description', 'decker'); ?></th>
														<th><?php _e('Tags', 'decker'); ?></th>
														<th><?php _e('Assigned Users', 'decker'); ?></th>
														<th><?php _e('Remaining Time', 'decker'); ?></th>
														<th class="text-end"><?php _e('Actions', 'decker'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';

                                                    $tasks = [];

                                                    if ($type === 'archived') {
                                                        $tasks = $taskManager->getTasksByStatus('archived');
                                                    } elseif ($type === 'my') {
                                                        $tasks = $taskManager->getTasksByUser(get_current_user_id());
                                                    } else {
                                                        $tasks = $taskManager->getTasksByStatus('publish');
                                                    }

                                                    foreach ($tasks as $task) {
                                                        echo '<tr>';
                                                        echo '<td>' . ($task->max_priority ? '🔥' : '') . '</td>';
                                                        echo '<td>';

														if (null === $task->board) {
														    echo '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . __('Undefined board', 'decker') . '</span>';
														} else {														    
														    echo '<span class="badge rounded-pill" style="background-color: ' . esc_attr($task->board->color) . ';">' . esc_html($task->board->name) . '</span>';
														}
                                                        echo '</td>';
                                                        echo '<td>' . esc_html($task->stack ) . '</td>';
                                                        echo '<td><a href="' . esc_url(add_query_arg(array('decker_page' => 'task', 'id' => $task->ID), home_url('/'))) . '" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="' . esc_attr($task->ID) . '">' . esc_html($task->title) . '</a></td>';
                                                        echo '<td>';
                                                        foreach ($task->labels as $label) {
                                                            echo '<span class="badge" style="background-color: ' . esc_attr($label->color) . ';">' . esc_html($label->name) . '</span> ';
                                                        }
                                                        echo '</td>';
                                                        echo '<td><div class="avatar-group">';

                                                        foreach ($task->assigned_users as $user) {
                                                        	$today_class = $user->today ? ' today' : '';
                                                            echo '<a href="javascript: void(0);" class="avatar-group-item' . esc_attr($today_class) . '" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="' . esc_attr($user->display_name) . '" data-bs-original-title="' . esc_attr($user->display_name) . '">';
                                                            echo '<img src="' . esc_url(get_avatar_url($user->ID)) . '" alt="" class="rounded-circle avatar-xs">';
                                                            echo '</a>';
                                                        }
                                                        echo '</div></td>';
                                                        echo '<td>' . esc_html($task->getRelativeTime()) . '</td>';
                                                        echo '<td class="text-end">
                                                            <div class="dropdown">
                                                                <a href="#" class="dropdown-toggle card-drop" data-bs-toggle="dropdown" aria-expanded="false">
                                                                    <i class="mdi mdi-dots-horizontal font-18"></i>
                                                                </a>
                                                                <div class="dropdown-menu dropdown-menu-end">
                                                                    <a class="dropdown-item" href="#"><i class="mdi mdi-pencil me-1"></i>' . __('Edit', 'decker') . '</a>
                                                                    <a class="dropdown-item" href="#"><i class="mdi mdi-archive me-1"></i>' . __('Archive', 'decker') . '</a>
                                                                    <a class="dropdown-item text-danger" href="#"><i class="mdi mdi-trash-can me-1"></i>' . __('Delete', 'decker') . '</a>
                                                                </div>
                                                            </div>
                                                        </td>';
                                                        echo '</tr>';
                                                    }

													?>
													
													<!-- Add more task rows as needed -->
												</tbody>
											</table>

										</div> <!-- end card body-->
									</div> <!-- end card -->
								</div><!-- end col-->
							</div> <!-- end row-->

						</div> <!-- end col -->
					</div>
					<!-- end row-->

				</div> <!-- container -->

			</div> <!-- content -->

			<?php include 'layouts/footer.php'; ?>

		</div>

		<!-- ============================================================== -->
		<!-- End Page content -->
		<!-- ============================================================== -->

	</div>
	<!-- END wrapper -->
	<?php include 'layouts/right-sidebar.php'; ?>

	<?php include 'layouts/task-modal.php'; ?>

	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
	function setupAllTasksTable() {
		if (!$.fn.DataTable.isDataTable('#tablaTareas')) {
			// Initialize DataTables only if it hasn't been initialized yet
			tablaElement = jQuery('#tablaTareas').DataTable({
				language: {
					url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/en-GB.json',
				},
				buttons: [
					{
						extend: 'searchBuilder',
						config: {
							depthLimit: 2,
							searchBuilder: {
								columns: [0, 1, 2, 3, 4, 5],
							},
							columns: [0, 1, 2, 3, 4, 5],
						},
					},
					{
						extend: 'print',
						className: 'd-none d-md-block', // Ocultar en móviles
					},
				],
				dom: '<"ms-2"l><"d-flex justify-content-between align-items-center"<"me-2"B>f>rtip', // Ajustar layout
				// select: true,
				searchBuilder: {
					columns: [0, 1, 2, 3, 4, 5, 6],
				},
				columnDefs: [
					{
						searchPanes: {
							show: false,
						},
						targets: [1, 6],
					},
				],
				lengthMenu: [
					[25, 50, 100, 200, -1],
					[25, 50, 100, 200, 'All'],
				],
				pageLength: 50,
				responsive: true,
				order: [[0, 'desc'], [1, 'asc'], [2, 'asc']], // Ordenar por la primera columna, luego la segunda

				initComplete: function () {
					// Move SearchBuilder to the desired location
					var searchBuilderButton = $('.dt-buttons .dt-button');
					$('#searchBuilderContainer').append(searchBuilderButton);
				}
			});

			// Filter by board using combobox
			jQuery('#boardFilter').on('change', function () {
				tablaElement.column(1).search(this.value).draw();
			});
		}
	}

	// Call setup function when document is ready
	jQuery(document).ready(function () {
		setupAllTasksTable();
		// Manejar el evento de clic en el checkbox "Today"
		document.querySelectorAll('.today-checkbox').forEach(checkbox => {
			checkbox.addEventListener('change', function() {
				const taskId = this.dataset.taskId;
				const isChecked = this.checked;
				// Aquí puedes implementar la lógica AJAX para actualizar el estado de la tarea
				console.log(`Task ID: ${taskId}, Today: ${isChecked}`);
			});
		});
	});


document.querySelectorAll('#tablaTareas tbody a[data-bs-toggle="modal"]').forEach(function (link) {
	link.addEventListener('click', function (event) {
		const taskTitle = event.target.textContent; // Obtén el título de la tarea
		const modalTitle = document.querySelector('#task-modal .modal-title');
		modalTitle.textContent = taskTitle; // Cambia el título del modal
		
		// Aquí puedes agregar lógica para cambiar el contenido del modal según la tarea.
	});
});


	</script>

</body>

</html>
