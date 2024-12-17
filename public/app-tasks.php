<?php
/**
 * File app-tasks
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

$task_manager = new TaskManager();

?>

<head>
	<title><?php esc_html_e( 'Tasks', 'decker' ); ?> | Decker</title>
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

								$selected_type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'all';

								$page_title     = __( 'Tasks', 'decker' );
								$class_disabled = '';
							if ( 'active' === $selected_type ) {
								$page_title = __( 'Active Tasks', 'decker' );
							} elseif ( 'my' === $selected_type ) {
								$page_title = __( 'My Tasks', 'decker' );
							} elseif ( 'archived' === $selected_type ) {
								$page_title     = __( 'Archived Tasks', 'decker' );
								$class_disabled = ' disabled';
							}
							?>
								<h4 class="page-title"><?php echo esc_html( $page_title ); ?> <a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ) ); ?>" class="btn btn-success btn-sm ms-3 <?php echo esc_attr( $class_disabled ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal"><?php esc_html_e( 'Add New', 'decker' ); ?></a></h4>

								<div class="d-flex align-items-center">
									<div id="searchBuilderContainer" class="me-2"></div>
									<select id="boardFilter" class="form-select">
										<option value=""><?php esc_html_e( 'All Boards', 'decker' ); ?></option>
										<?php
										$boards = BoardManager::get_all_boards();
										foreach ( $boards as $board ) {
											echo '<option value="' . esc_attr( $board->name ) . '">' . esc_html( $board->name ) . '</option>';
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
														<th class="c-priority"><?php esc_html_e( 'P.', 'decker' ); ?></th>
														<th class="c-board"><?php esc_html_e( 'Board', 'decker' ); ?></th>
														<th class="c-stack"><?php esc_html_e( 'Stack', 'decker' ); ?></th>
														<th class="c-description"><?php esc_html_e( 'Description', 'decker' ); ?></th>
														<th class="c-tags"><?php esc_html_e( 'Tags', 'decker' ); ?></th>
														<th class="c-users"><?php esc_html_e( 'Assigned Users', 'decker' ); ?></th>
														<th class="c-time"><?php esc_html_e( 'Remaining Time', 'decker' ); ?></th>
														<th class="c-actions text-end"></th>
													</tr>
												</thead>
												<tbody>
												<?php

													$tasks = array();

												if ( 'archived' === $selected_type ) {
													$tasks = $task_manager->get_tasks_by_status( 'archived' );
												} elseif ( 'my' === $selected_type ) {
													$tasks = $task_manager->get_tasks_by_user( get_current_user_id() );
												} else {
													$tasks = $task_manager->get_tasks_by_status( 'publish' );
												}

												foreach ( $tasks as $task ) {
													echo '<tr class="task">';
													echo '<td>' . ( $task->max_priority ? '🔥' : '' ) . '</td>';
													echo '<td>';

													if ( null === $task->board ) {
														echo '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . esc_attr( 'Undefined board', 'decker' ) . '</span>';
													} else {
														echo '<span class="badge rounded-pill" style="background-color: ' . esc_attr( $task->board->color ) . ';">' . esc_html( $task->board->name ) . '</span>';
													}
													echo '</td>';
													echo '<td>' . esc_html( $task->stack ) . '</td>';
													echo '<td><a href="' . esc_url(
														add_query_arg(
															array(
																'decker_page' => 'task',
																'id'          => $task->ID,
															),
															home_url( '/' )
														)
													) . '" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="' . esc_attr( $task->ID ) . '">' . esc_html( $task->title ) . '</a></td>';
													echo '<td>';
													foreach ( $task->labels as $label ) {
														echo '<span class="badge" style="background-color: ' . esc_attr( $label->color ) . ';">' . esc_html( $label->name ) . '</span> ';
													}
													echo '</td>';


													echo '<td data-users=\'' . esc_attr( wp_json_encode( array_map( 'esc_html', wp_list_pluck( $task->assigned_users, 'display_name' ) ) ) ) . '\'>';
													echo '<div class="avatar-group">';

													foreach ( $task->assigned_users as $user ) {
														$today_class = $user->today ? ' today' : '';
														echo '<a href="javascript: void(0);" class="avatar-group-item' . esc_attr( $today_class ) . '" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="' . esc_attr( $user->display_name ) . '" data-bs-original-title="' . esc_attr( $user->display_name ) . '">';
														echo '<span class="d-none">' . esc_attr( $user->display_name ) . '</span>';
														echo '<img src="' . esc_url( get_avatar_url( $user->ID ) ) . '" alt="' . esc_attr( $user->display_name ) . '" class="rounded-circle avatar-xs">';
														echo '</a>';
													}
													echo '</div></td>';
													echo '<td>' . esc_html( $task->duedate?->format( 'Y-m-d H:i:s' ) ) . '</td>';
													echo '<td class="text-end">';
													$task->render_task_menu();
													echo '</td>';
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

	// Extend Day.js with relativeTime plugin
	dayjs.extend(dayjs_plugin_relativeTime);

	// Set locale to Spanish
	dayjs.locale('es');

	function setupAllTasksTable() {
		if (!jQuery.fn.DataTable.isDataTable('#tablaTareas')) {
			// Initialize DataTables only if it hasn't been initialized yet
			tablaElement = jQuery('#tablaTareas').DataTable({
				language: {
					// url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/en-GB.json',
					url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json', // Changed to spanish TO-DO: resolve better					
				},
				buttons: [
					{
						extend: 'searchBuilder',
						config: {
							depthLimit: 2,
							searchBuilder: {
								columns: [1, 2, 3, 4, 5],
							},
							columns: [1, 2, 3, 4, 5],
						},
					},
					{
						extend: 'print',
						className: 'd-none d-md-block', // Ocultar en móviles
					},
				],
				dom: '<"ms-2"l><"d-flex justify-content-between align-items-center"<"me-2"B>f>rtip', // Ajustar layout
				columnDefs: [
					{
						searchPanes: {
							show: false,
						},
						targets: [1, 6], // Columnas para las cuales SearchPanes está deshabilitado
					},
					{
						targets: 2, // Columna 3
						searchBuilder: {
							disable: true
						}
					},
					{
						targets: [4, 5, 7], // Columna 7
						orderable: false
					},
					{
						targets: 6, // Columna 6 (Remaining Time)
						render: function(data, type, row, meta) {
							if(type === 'display') {
								// Verificar que la fecha sea válida
								if (!data) {
									return '';
								}
								// Formatear la fecha completa para el tooltip
								var fullDate = dayjs(data).format('DD/MM/YYYY'); // Ajusta el formato según tus necesidades
								// Generar el texto amigable usando Day.js
								var friendlyText = dayjs(data).fromNow();
								return '<span title="' + fullDate + '">' + friendlyText + '</span>';
							}
							return data; // Para 'sort', 'type' y 'filter'
						},
						type: 'date'
					},

				],

				lengthMenu: [
					[25, 50, 100, 200, -1],
					[25, 50, 100, 200, 'All'],
				],
				pageLength: 50,
				responsive: true,
				order: [[0, 'desc']], 

				initComplete: function () {
					// Move SearchBuilder to the desired location
					var searchBuilderButton = jQuery('.dt-buttons .dt-button');
					jQuery('#searchBuilderContainer').append(searchBuilderButton);
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
