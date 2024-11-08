<?php
include 'layouts/main.php';
?>

<head>
	<title>Tasks | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>

	<style type="text/css">
		

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

								$page_title = 'Tasks';
								if ( $current_type === 'active' ) {
								    $page_title = 'Active Tasks';
								} elseif ( $current_type === 'my' ) {
								    $page_title = 'My Tasks';
								} elseif ( $current_type === 'archived' ) {
								    $page_title = 'Archived Tasks';
								}
							?>
								<h4 class="page-title"><?php echo esc_html( $page_title ); ?> <a href="<?php echo add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ); ?>" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#task-modal">Add New</a></h4>


	



								<div class="d-flex align-items-center">
									<div id="searchBuilderContainer" class="me-2"></div>
									<select id="boardFilter" class="form-select">
										<option value="">All Boards</option>
										<?php
										$boards = get_terms(
											array(
												'taxonomy' => 'decker_board',
												'hide_empty' => false,
											)
										);
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
														<th>P.</th>
														<th>Board</th>
														<th>Stack</th>
														<th>Description</th>
														<th>Tags</th>
														<th>Assigned Users</th>
														<th>Remaining Time</th>
													</tr>
												</thead>
												<tbody>
													<?php
													$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'all';
													$args = array(
														'post_type' => 'decker_task',
														'posts_per_page' => -1,
													);

													if ( $type === 'archived' ) {
														$args['post_status'] = 'archived';
													} elseif ( $type === 'my' ) {
														$args['meta_query'] = array(
															array(
																'key' => 'assigned_users',
																'value' => get_current_user_id(),
																'compare' => 'LIKE',
															),
														);
													} else {
														$args['post_status'] = 'publish';
													}

													$tasks = get_posts( $args );

													foreach ( $tasks as $task ) {
														$board_terms = wp_get_post_terms( $task->ID, 'decker_board' );
														$board_name = ! empty( $board_terms ) ? $board_terms[0]->name : 'Unassigned';
														$stack = get_post_meta( $task->ID, 'stack', true );
														$priority = get_post_meta( $task->ID, 'max_priority', true ) ? 'High' : 'Normal';
														$assigned_users = get_post_meta( $task->ID, 'assigned_users', true );
														$assigned_users = is_array( $assigned_users ) ? $assigned_users : array();
														// $user_avatars = array_map(
														// function ( $user_id ) {
														// $user_info = get_userdata( $user_id );
														// return array(
														// 'name' => $user_info->display_name,
														// 'avatar' => get_avatar_url( $user_id, array( 'size' => 32 ) ),
														// );
														// },
														// $assigned_users
														// );
														?>
														<tr>
															<td>
																<?php if ( get_post_meta( $task->ID, 'max_priority', true ) ) : ?>
																	🔥
																<?php endif; ?>
															</td>
															<td>
																<?php
																	// Check if terms are available and there are no errors
																	if ( ! empty( $board_terms ) && ! is_wp_error( $board_terms ) ) {
																	    // Retrieve the board name and color
																	    $board_name = $board_terms[0]->name;
																	    $board_color = get_term_meta( $board_terms[0]->term_id, 'term-color', true );
																	    ?>
																	    <span class="badge rounded-pill" style="background-color: <?php echo esc_attr( $board_color ); ?>;"><?php echo esc_html( $board_name ); ?></span>
																	    <?php
																	} else {
																	    // Display a fallback badge if no board is assigned
																	    ?>
																	    <span class="badge bg-danger"><i class="ri-error-warning-line"></i> Undefined board</span>
																	    <?php
																	}
																?>
															</td>
															<td><?php echo esc_html( $stack ); ?></td>
															<td><a href="
															<?php
															echo add_query_arg(
																array(
																	'decker_page' => 'task',
																	'id' => esc_attr( $task->ID ),
																),
																home_url( '/' )
															);
															?>
																			" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( $task->ID ); ?>"><?php echo esc_html( get_the_title( $task ) ); ?></a></td>
															<td>
																<?php
																$labels = wp_get_post_terms( $task->ID, 'decker_label' );
																foreach ( $labels as $label ) {
																	$label_color = get_term_meta( $label->term_id, 'term-color', true );
																	echo '<span class="badge" style="background-color: ' . esc_attr( $label_color ) . ';">' . esc_html( $label->name ) . '</span> ';
																}
																?>
															</td>
															<td>
																<div class="avatar-group">

																<?php
																$today = date( 'Y-m-d' );
																if ( ! empty( $assigned_users ) ) {
																	foreach ( $assigned_users as $user_id ) {
																		$user_info = get_userdata( $user_id );
																		if ( $user_info ) {
																			$user_date_relations = get_post_meta( $task->ID, '_user_date_relations', true );
																			$is_today = false;
																			if ( $user_date_relations ) {
																				foreach ( $user_date_relations as $relation ) {
																					if ( $relation['user_id'] == $user_id && $relation['date'] == $today ) {
																						$is_today = true;
																						break;
																					}
																				}
																			}
																			$avatar_class = $is_today ? 'avatar-group-item today' : 'avatar-group-item';
																			?>
																			<a href="javascript: void(0);" class="<?php echo $avatar_class; ?>" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="<?php echo esc_attr( $user_info->display_name ); ?>" data-bs-original-title="<?php echo esc_attr( $user_info->display_name ); ?>">
																				<img src="<?php echo esc_url( get_avatar_url( $user_id ) ); ?>" alt="" class="rounded-circle avatar-xs">
																			</a>
																			<?php
																		}
																	}
																}
																?>






																</div>
															</td>
															<td>

																<?php


																// echo esc_html( get_post_meta( $task->ID, 'duedate', true ) );

																echo Decker_Utility_Functions::getRelativeTime( get_post_meta( $task->ID, 'duedate', true ) );

																?>
																	

																</td>
														</tr>
													<?php } ?>
													
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
