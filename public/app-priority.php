<?php
/**
 * File app-priority
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

$nonce = filter_input( INPUT_POST, 'import_tasks_nonce', FILTER_SANITIZE_STRING );
if ( $nonce && wp_verify_nonce( $nonce, 'import_tasks' ) ) {
	// Sanitize task_ids as integer array.
	$raw_task_ids = filter_input( INPUT_POST, 'task_ids', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY );
	$task_ids = array_map( 'intval', $raw_task_ids ? $raw_task_ids : array() );

	$today = gmdate( 'Y-m-d' );
	$current_user_id = get_current_user_id();

	foreach ( $task_ids as $task_id ) {
		$decker_tasks = new Decker_Tasks();
		$decker_tasks->add_user_date_relation( $task_id, $current_user_id, $today );
	}

	// Sanitizar y verificar REQUEST_URI antes de redirigir.
	$redirect_url = '';
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$redirect_url = esc_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
	}
	wp_redirect( $redirect_url );
	exit;
}

$previous_tasks  = array();
$task_manager    = new TaskManager();

// Verificar si hay tareas para hoy.
$has_today_tasks = $task_manager->has_user_today_tasks();

// Si no hay tareas para hoy, cargar las tareas de días previos.
if ( ! $has_today_tasks ) {

	$current_user_id = get_current_user_id();
	$days_to_load    = ( 1 == gmdate( 'N' ) ) ? 3 : 2; // Si es lunes, carga 3 días previos; de lo contrario, 2 días previos.

	$previous_tasks = $task_manager->getUserTasksMarkedForTodayForPreviousDays( $current_user_id, $days_to_load );
}

?>
<head>

	<title><?php esc_html_e( 'Priority', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>


<style type="text/css">
.custom-badge {
	display: inline-block;
	padding: 0.5em 0.75em;
	font-size: 0.75em;
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
							<div class="col-12">
								<div class="page-title-box">
									<div class="page-title-right">
										<ol class="breadcrumb m-0">
											<li class="breadcrumb-item"><a href="javascript: void(0);">Decker</a></li>
											<li class="breadcrumb-item active"><?php esc_html_e( 'Priority', 'decker' ); ?></li>
										</ol>
									</div>
									<h4 class="page-title"><?php esc_html_e( 'Priority', 'decker' ); ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ) ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3"><?php esc_html_e( 'Add New', 'decker' ); ?></a></h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

<?php if ( ! $has_today_tasks ) { ?>
	<div id="alert-import-today-1" class="alert-import-today alert alert-warning alert-dismissible fade show" role="alert">
		<i class="ri-alert-fill"></i>
		<?php esc_html_e( 'You have no tasks defined for today. Do you want to import those from the previous day?', 'decker' ); ?>
		<button type="button" class="import-today btn btn-warning btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#taskModal"><?php esc_html_e( 'Yes', 'decker' ); ?></button>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'decker' ); ?>"></button>
	</div>
<?php } ?>

					<div class="row">
						<div class="col-lg-12">
							<div class="card">
								<div class="d-flex card-header justify-content-between align-items-center">
									<h4 class="header-title"><?php esc_html_e( 'MAX PRIORITY', 'decker' ); ?> 🔥</h4>
								</div>

							<div class="table-responsive">
																
							<table id="priority-table" class="table table-striped table-responsive">
								<thead>
									<tr>
										<th data-sort-default style="width: 10%;"><?php esc_html_e( 'Board', 'decker' ); ?></th>
										<th class="d-none d-md-table-cell" style="width: 10%;"><?php esc_html_e( 'Stack', 'decker' ); ?></th>
										<th style="width: auto;"><?php esc_html_e( 'Title', 'decker' ); ?></th>
										<th style="width: 15%;" data-sort-method='none'><?php esc_html_e( 'Assigned Users', 'decker' ); ?></th>
									</tr>
								</thead>
								<tbody id="priority-id-table">
									<?php
									// Obtener tareas con max_priority.
									$args = array(
										'meta_query' => array(
											array(
												'key'     => 'max_priority',
												'value'   => '1',
												'compare' => '=',
											),
										),
									);
									$tasks = $task_manager->get_tasks( $args );
									foreach ( $tasks as $task ) {

										$board = __( 'No board assigned', 'decker' );
										if ( $task->board ) {
											$board = sprintf(
												'<span class="custom-badge overflow-visible" style="background-color: %s;">%s</span>',
												esc_attr( $task->board->color ),
												esc_html( $task->board->name )
											);
										}
										?>
										<tr>
											<td><?php echo wp_kses_post( $board ); ?></td>
											<td class="d-none d-md-table-cell"><?php echo esc_html( $task->stack ); ?></td>
											<td class="descripcion" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr( $task->title ); ?>">
												<a href="
												<?php
												echo esc_url(
													add_query_arg(
														array(
															'decker_page' => 'task',
															'id'          => esc_attr( $task->ID ),
														),
														home_url( '/' )
													)
												);
												?>
															" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
													<?php echo esc_html( $task->title ); ?>
												</a>
											</td>
											<td>
												<div class="avatar-group mt-2">
													<?php
													$today = gmdate( 'Y-m-d' );
													foreach ( $task->assigned_users as $user_info ) {
														$today_class = $user_info->today ? 'today' : '';
														?>
														<a href="javascript: void(0);" class="avatar-group-item <?php echo esc_attr( $today_class ); ?>" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="<?php echo esc_attr( $user_info->display_name ); ?>" data-bs-original-title="<?php echo esc_attr( $user_info->display_name ); ?>">
															<img src="<?php echo esc_url( get_avatar_url( $user_info->ID ) ); ?>" alt="" class="rounded-circle avatar-xs">
														</a>
														<?php
													}
													?>
												</div>
											</td>
										</tr>
										<?php
									}
									?>
								</tbody>
							</table>

						</div> <!-- end col-->

					</div>
					<!-- end row -->

					<div class="row" id="cards-container">

						<?php
						$today = gmdate( 'Y-m-d' );
						$options                       = get_option( 'decker_settings', array() );
						$selected_role                 = isset( $options['user_profile'] ) ? $options['user_profile'] : 'administrator';
						$users                         = get_users(
							array(
								'role__in' => array( $selected_role, 'administrator' ),
								'orderby'  => 'display_name',
							)
						);
						$today           = gmdate( 'Y-m-d' );
						$current_user_id = get_current_user_id();
						foreach ( $users as $user ) {
							$card_class = ( $user->ID === $current_user_id ) ? 'card border-primary border' : 'card';
							$args       = array(
								'meta_query' => array(
									array(
										'key'     => '_user_date_relations',
										'value'   => sprintf( ':"%s"', $today ),
										'compare' => 'LIKE',
									),
									array(
										'key'     => 'assigned_users',
										'value'   => $user->ID,
										'compare' => 'LIKE',
									),
								),
							);
							$user_tasks = $task_manager->getUserTasksMarkedForTodayForPreviousDays( $user->ID, 0 );
							?>
							<div class="col-xl-6">
								<div class="<?php echo esc_attr( $card_class ); ?>">
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
												foreach ( $user_tasks as $task ) {
													// Ensuring the board is displayed as intended without directly modifying the board property.
													$board_display = '';
													if ( ! empty( $task->board ) ) {
														$board_display = '<span class="custom-badge overflow-visible" style="background-color: ' . esc_attr( $task->board->color ) . ';">' . esc_html( $task->board->name ) . '</span>';
													}
													?>
														<tr>
															<td><?php echo wp_kses_post( $board_display ); ?></td>
															<td>
																<a href="
																<?php
																echo esc_url(
																	add_query_arg(
																		array(
																			'decker_page' => 'task',
																			'id'          => esc_attr( $task->ID ),
																		),
																		home_url( '/' )
																	)
																);
																?>
																" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
																	<?php echo esc_html( $task->title ); ?>
																</a>
															</td>
														</tr>
														<?php
												}
												if ( empty( $user_tasks ) ) {
													?>
														<tr>
															<td colspan="2"><?php esc_html_e( 'No tasks for today.', 'decker' ); ?></td>
														</tr>
												<?php } ?>

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
	  <form method="post" action="<?php echo esc_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); ?>">
		<div class="modal-header">
		  <h5 class="modal-title" id="taskModalLabel"><?php esc_html_e( 'Select tasks to import', 'decker' ); ?></h5>
		  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'decker' ); ?>"></button>
		</div>
		<div class="modal-body">
		  <!-- Aquí se insertarán dinámicamente las tareas con checkboxes -->
		  <table class="table table-striped table-hover">
			<thead class="table thead-sticky bg-light">
				<tr>
					<th scope="col" style="width: 50px;">
						<input type="checkbox" id="selectAllCheckbox" class="">
					</th>                        
					<th scope="col"><?php esc_html_e( 'Board', 'decker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stack', 'decker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Title', 'decker' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $has_today_tasks ) : ?>
				<?php foreach ( $previous_tasks as $task ) : ?>
					<tr class="task-row" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
						<?php
							$board_color = 'red';
						$board_name                      = 'Unassigned';
						if ( $task->board ) {
							$board_color = $task->board->color;
							$board_name  = $task->board->name;
						}
						?>

						<td><input type="checkbox" name="task_ids[]" class="task-checkbox" value="<?php echo esc_attr( $task->ID ); ?>"></td>
						<td>
							<span class="custom-badge overflow-visible" style="background-color: <?php echo esc_attr( $board_color ); ?>;">
								<?php echo esc_html( $board_name ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $task->stack ); ?></td>
						<td><?php echo esc_html( $task->title ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="4"><?php esc_html_e( 'There are no tasks from previous days to import.', 'decker' ); ?></td>
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

		const importTodayYesButton = document.querySelector('.import-today');
		if (importTodayYesButton) {

			document.querySelector('.import-today').addEventListener('click', loadPreviousTasks);
			selectAllCheckbox.addEventListener('change', function() {
				const isChecked = this.checked;
				taskCheckboxes.forEach(checkbox => {
					checkbox.checked = isChecked;
				});
				updateImportButton(); // Actualiza el estado del botón después de seleccionar/desmarcar todos
			});

		}

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
jQuery(document).ready(function() {
	new Tablesort(document.getElementById('priority-table'));
});
</script>

</body>

</html>
