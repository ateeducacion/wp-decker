<?php
include 'layouts/main.php';

// Verificar si el usuario actual tiene tareas para hoy
$current_user_id = get_current_user_id();
$today = date( 'Y-m-d' );
$args = array(
	'post_type' => 'decker_task',
	'meta_query' => array(
		array(
			'key' => '_user_date_relations',
			'value' => sprintf( ':"%s"', $today ),
			'compare' => 'LIKE',
		),
		array(
			'key' => 'assigned_users',
			'value' => sprintf( ':"%s";', $current_user_id ), // Search in serialized data
			'compare' => 'LIKE',
		),
	),
);
if ( isset( $_POST['import_tasks_nonce'] ) && wp_verify_nonce( $_POST['import_tasks_nonce'], 'import_tasks' ) ) {
	$task_ids = isset( $_POST['task_ids'] ) ? array_map( 'intval', $_POST['task_ids'] ) : array();
	$current_user_id = get_current_user_id();
	$today = date( 'Y-m-d' );

	foreach ( $task_ids as $task_id ) {
		$decker_tasks = new Decker_Tasks();
		$decker_tasks->add_user_date_relation( $task_id, $current_user_id, $today );
	}

	// Redirigir para evitar reenvío de formulario
	wp_redirect( esc_url( $_SERVER['REQUEST_URI'] ) );
	exit;
}

$today_tasks = new WP_Query( $args );

// Mostrar mensaje si no hay tareas para hoy
$show_import_message = ! $today_tasks->have_posts();
?>
<head>

	<title>Priority | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>


<style type="text/css">
.custom-badge {
	display: inline-block;
	padding: 0.5em 0.75em;
	font-size: 0.75em;
/*    font-weight: 700;*/
	line-height: 1;
	color: #fff;
	text-align: center;
	white-space: normal; /* Permite que el texto se desborde en varias líneas */
	vertical-align: baseline;
	border-radius: 10rem;
	word-break: break-word; /* Asegura que las palabras largas se corten correctamente */
}
	
.table-responsive {
	overflow-x: auto;
}

.table th, .table td, .descripcion {
	white-space: normal; /* Permite que el texto se desborde en varias líneas */
	word-break: break-word; /* Asegura que las palabras largas se corten correctamente */
	word-wrap: break-word; /* Asegura que las palabras largas se corten correctamente */
	overflow-wrap: break-word; /* Asegura que las palabras largas se corten correctamente */
}
}

@media (max-width: 768px) {
	.table th:nth-child(2), .table td:nth-child(2) {
		display: none; /* Oculta la columna "Status" en dispositivos móviles */
	}
}

.avatar-group {
	flex-wrap: wrap; /* Asegura que los avatares se ajusten al espacio disponible */
}

.priority-id-table td {
	max-width: 100%; /* Asegura que las celdas ocupen todo el ancho disponible */
}





</style>


</head>

<body>
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
							<div class="col-12">
								<div class="page-title-box">
									<div class="page-title-right">
										<ol class="breadcrumb m-0">
											<li class="breadcrumb-item"><a href="javascript: void(0);">Decker</a></li>
											<li class="breadcrumb-item active">Priority</li>
										</ol>
									</div>
									<h4 class="page-title">Priority
										<a href="<?php echo add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3">Add New</a></h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

<?php if ( $show_import_message ) : ?>
	<div id="alert-import-today-1" class="alert-import-today alert alert-warning alert-dismissible fade show" role="alert">
		<i class="ri-alert-fill"></i>
		Usted no tiene definidas tareas para hoy. ¿Desea importar las del día anterior?
		<button type="button" class="import-today btn btn-warning btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#taskModal">Sí</button>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
	</div>
<?php endif; ?>

					<div class="row">
						<div class="col-lg-12">
							<div class="card">
								<div class="d-flex card-header justify-content-between align-items-center">
									<h4 class="header-title">MAX PRIORITY 🔥🧨</h4>
								</div>



<div class="table-responsive">

									
<table id="priority-table" class="table table-striped table-responsive">
										<thead>
											<tr>
												<th style="width: 10%;">Board</th>
												<th class="d-none d-md-table-cell" style="width: 10%;">Stack</th>
												<th style="width: auto;">Title</th>
												<th style="width: 15%;">Assigned Users</th>
											</tr>
										</thead>
										<tbody id="priority-id-table">
											<?php
											// Obtener tareas con max_priority
											$args = array(
												'post_type' => 'decker_task',
												'meta_query' => array(
													array(
														'key' => 'max_priority',
														'value' => '1',
														'compare' => '=',
													),
												),
											);
											$tasks = new WP_Query( $args );
											if ( $tasks->have_posts() ) :
												while ( $tasks->have_posts() ) :
													$tasks->the_post();
													$board_terms = get_the_terms( get_the_ID(), 'decker_board' );
													if ( $board_terms && ! is_wp_error( $board_terms ) ) {
														$board = '';
														foreach ( $board_terms as $term ) {
															$color = get_term_meta( $term->term_id, 'term-color', true );
															$board .= '<span class="custom-badge overflow-visible" style="background-color: ' . esc_attr( $color ) . ';">' . esc_html( $term->name ) . '</span> ';
														}
													} else {
														$board = 'No board assigned';
													}
													$stack = get_post_meta( get_the_ID(), 'stack', true );
													$assigned_users = get_post_meta( get_the_ID(), 'assigned_users', true );
													?>
													<tr>
														<td><?php echo $board; ?></td>
														<td class="d-none d-md-table-cell"><?php echo esc_html( $stack ); ?></td>
														<td class="descripcion" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php the_title(); ?>">
															<a href="
															<?php
															echo add_query_arg(
																array(
																	'decker_page' => 'task',
																	'id' => esc_attr( get_the_ID() ),
																),
																home_url( '/' )
															);
															?>
																		" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( get_the_ID() ); ?>"><?php the_title(); ?></a>
														</td>
														<td>
															<div class="avatar-group mt-2">
																<?php
																$today = date( 'Y-m-d' );
																if ( ! empty( $assigned_users ) ) {
																	foreach ( $assigned_users as $user_id ) {
																		$user_info = get_userdata( $user_id );
																		if ( $user_info ) {
																			$user_date_relations = get_post_meta( get_the_ID(), '_user_date_relations', true );
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
													</tr>
													<?php
												endwhile;
												wp_reset_postdata();
											endif;
											?>
										</tbody>
									</table>




							

						</div> <!-- end col-->

					</div>
					<!-- end row -->

					<div class="row" id="cards-container">

						<?php
						$today = date( 'Y-m-d' );
						$options = get_option( 'decker_settings', array() );
						$selected_role = isset( $options['user_profile'] ) ? $options['user_profile'] : 'administrator';
						$users = get_users(
							array(
								'role__in' => array( $selected_role, 'administrator' ),
								'orderby' => 'display_name',
							)
						);
						$today = date( 'Y-m-d' );
						$current_user_id = get_current_user_id();
						foreach ( $users as $user ) {
							$card_class = ( $user->ID === $current_user_id ) ? 'card border-primary border' : 'card';
							$user_tasks = new WP_Query(
								array(
									'post_type' => 'decker_task',
									'meta_query' => array(
										array(
											'key' => '_user_date_relations',
											'value' => sprintf( ':"%s"', $today ),
											'compare' => 'LIKE',
										),
										array(
											'key' => 'assigned_users',
											'value' => $user->ID,
											'compare' => 'LIKE',
										),
									),
								)
							);
							?>
							<div class="col-xl-6">
								<div class="<?php echo $card_class; ?>">
									<div class="d-flex card-header justify-content-between align-items-center">
										<h4 class="header-title"><?php echo esc_html( $user->display_name ); ?></h4>
										<img src="<?php echo esc_url( get_avatar_url( $user->ID ) ); ?>" class="rounded-circle avatar-xs hoverZoomLink" alt="<?php echo esc_attr( $user->display_name ); ?>">
									</div>
									<div class="card-body p-0">
										<div class="table-responsive">
											<table class="table table-borderless table-hover table-nowrap table-centered m-0">
												<thead class="border-top border-bottom bg-light-subtle border-light">
													<tr>
														<th class="py-1">Tablero</th>
														<th class="py-1">Título</th>
													</tr>
												</thead>
												<tbody>
													<?php
													if ( $user_tasks->have_posts() ) :
														while ( $user_tasks->have_posts() ) :
															$user_tasks->the_post();
															$board_terms = get_the_terms( get_the_ID(), 'decker_board' );
															$board = 'No board assigned';
															if ( $board_terms && ! is_wp_error( $board_terms ) ) {
																$board = '';
																foreach ( $board_terms as $term ) {
																	$color = get_term_meta( $term->term_id, 'term-color', true );
																	$board .= '<span class="custom-badge overflow-visible" style="background-color: ' . esc_attr( $color ) . ';">' . esc_html( $term->name ) . '</span> ';
																}
															}
															?>
															<tr>
																<td><?php echo $board; ?></td>
																<td><a href="
																<?php
																echo add_query_arg(
																	array(
																		'decker_page' => 'task',
																		'id' => esc_attr( get_the_ID() ),
																	),
																	home_url( '/' )
																);
																?>
																				" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( get_the_ID() ); ?>"><?php the_title(); ?></a></td>
															</tr>
															<?php
														endwhile;
														wp_reset_postdata();
													else :
														?>
														<tr>
															<td colspan="2">No tasks for today.</td>
														</tr>
													<?php endif; ?>
												</tbody>
											</table>
										</div>
									</div>
								</div>
							</div> <!-- end col -->
						<?php } ?>

					</div>
					<!-- end row -->

				</div>
				<!-- container -->

			</div>
			<!-- content -->

			<?php include 'layouts/footer.php'; ?>

		</div>

		<!-- ============================================================== -->
		<!-- End Page content -->
		<!-- ============================================================== -->

	</div>
	<!-- END wrapper -->


<!-- import modal -->

<!-- Modal -->
<div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
	<div class="modal-content">
	  <form method="post" action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>">
		<div class="modal-header">
		  <h5 class="modal-title" id="taskModalLabel">Selecciona las tareas para importar</h5>
		  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
		</div>
		<div class="modal-body">
		  <!-- Aquí se insertarán dinámicamente las tareas con checkboxes -->
		  <table class="table table-striped table-hover">
			<thead class="table thead-sticky bg-light">
				<tr>
					<th scope="col" style="width: 50px;">
						<input type="checkbox" id="selectAllCheckbox" class="">
					</th>                        
					<th scope="col">Tablero</th>
					<th scope="col">Columna</th>
					<th scope="col">Título</th>
				</tr>
			</thead>
			<tbody>
			  <?php
				// Obtener tareas de días anteriores
				$days_to_load = ( date( 'N' ) == 1 ) ? 3 : 1; // Si es lunes, cargar los tres días anteriores
				$previous_dates = array();
				for ( $i = 1; $i <= $days_to_load; $i++ ) {
					$previous_dates[] = date( 'Y-m-d', strtotime( "-$i days" ) );
				}

				$args = array(
					'post_type' => 'decker_task',
					'meta_query' => array(
						array(
							'key' => '_user_date_relations',
							'value' => implode( ',', $previous_dates ),
							'compare' => 'REGEXP',
						),
						array(
							'key' => 'assigned_users',
							'value' => sprintf( ':"%s";', $current_user_id ), // Search in serialized data
							'compare' => 'LIKE',
						),
					),
				);
				$previous_tasks = new WP_Query( $args );

				if ( $previous_tasks->have_posts() ) :
					while ( $previous_tasks->have_posts() ) :
						$previous_tasks->the_post();
						$board_terms = get_the_terms( get_the_ID(), 'decker_board' );
						$board = $board_terms && ! is_wp_error( $board_terms ) ? $board_terms[0]->name : 'No board assigned';
						$stack = get_post_meta( get_the_ID(), 'stack', true );
						?>
					  <tr class="task-row" data-task-id="<?php echo esc_attr( get_the_ID() ); ?>">
						  <td><input type="checkbox" name="task_ids[]" class="task-checkbox" value="<?php echo esc_attr( get_the_ID() ); ?>"></td>
						  <td>
							  <?php
								if ( $board_terms && ! is_wp_error( $board_terms ) ) {
									foreach ( $board_terms as $term ) {
										$color = get_term_meta( $term->term_id, 'term-color', true );
										echo '<span class="custom-badge overflow-visible" style="background-color: ' . esc_attr( $color ) . ';">' . esc_html( $term->name ) . '</span> ';
									}
								} else {
									echo 'No board assigned';
								}
								?>
						  </td>
						  <td><?php echo esc_html( $stack ); ?></td>
						  <td><?php the_title(); ?></td>
					  </tr>
						<?php
					endwhile;
					wp_reset_postdata();
			  else :
					?>
				  <tr>
					  <td colspan="4">No hay tareas de días anteriores para importar.</td>
				  </tr>
			  <?php endif; ?>
			</tbody>
		  </table>
		</div>
		<div class="modal-footer">
		  <?php wp_nonce_field( 'import_tasks', 'import_tasks_nonce' ); ?>
		  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
		  <button type="submit" class="btn btn-primary import-selected-tasks" disabled>Importar</button>
		</div>
	  </form>
	</div>
  </div>
</div>

<!-- JavaScript para el comportamiento de los checkboxes y el botón Importar -->
<script>
	document.addEventListener('DOMContentLoaded', function() {
		const selectAllCheckbox = document.getElementById('selectAllCheckbox');
		const taskCheckboxes = document.querySelectorAll('.task-checkbox');
		const importButton = document.querySelector('.import-selected-tasks');

		// Función para actualizar el estado del botón "Importar"
		function updateImportButton() {
			const anyChecked = Array.from(taskCheckboxes).some(checkbox => checkbox.checked);
			importButton.disabled = !anyChecked;
		}

		// Función para cargar tareas de días anteriores
		function loadPreviousTasks() {
			const today = new Date();
			let daysToLoad = 1; // Cargar un día anterior por defecto

			// Si es lunes, cargar los tres días anteriores
			if (today.getDay() === 1) {
				daysToLoad = 3;
			}

			const previousDates = [];
			for (let i = 1; i <= daysToLoad; i++) {
				const previousDate = new Date(today);
				previousDate.setDate(today.getDate() - i);
				previousDates.push(previousDate.toISOString().split('T')[0]);
			}

			// Aquí puedes implementar la lógica para cargar las tareas de las fechas anteriores
			console.log('Cargando tareas de las fechas:', previousDates);
		}

		// Evento para el botón "Sí" en el mensaje de importación
		document.querySelector('.import-today').addEventListener('click', loadPreviousTasks);
		selectAllCheckbox.addEventListener('change', function() {
			const isChecked = this.checked;
			taskCheckboxes.forEach(checkbox => {
				checkbox.checked = isChecked;
			});
			updateImportButton(); // Actualiza el estado del botón después de seleccionar/desmarcar todos
		});

		// Evento para actualizar el estado del botón "Importar" cuando se cambia un checkbox individual
		taskCheckboxes.forEach(checkbox => {
			checkbox.addEventListener('change', updateImportButton);
		});

		// Actualiza el botón "Importar" cuando se carga la página/modal (por si acaso)
		updateImportButton();
		// Hacer clic en cualquier parte de la fila para marcar el checkbox
		document.querySelectorAll('.task-row').forEach(row => {
			row.addEventListener('click', function(event) {
				if (event.target.tagName !== 'INPUT') { // Evitar el clic en el propio checkbox
					const checkbox = this.querySelector('.task-checkbox');
					checkbox.checked = !checkbox.checked;
					updateImportButton();
				}
			});
		});
	});
</script>



<!-- END import modal -->


	<?php include 'layouts/right-sidebar.php'; ?>
	<?php include 'layouts/task-modal.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>


<script>
$(document).ready(function() {
	$('#priority-table').DataTable({
		// Configuraciones adicionales, como desactivar paginación o buscar dentro de ciertas columnas
		info: false,
		paging: false,
		ordering: true,
		searching: false,
		// order: [[ 2, "asc" ]], // Ordena por la columna "Title"
		columnDefs: [
			{ "orderable": false, "targets": 3 } // Desactiva la ordenación en la columna "Assigned Users"
		]
	});
});
</script>

</body>

</html>
