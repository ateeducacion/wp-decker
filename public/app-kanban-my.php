<?php
include 'layouts/main.php';

$user_id = get_current_user_id();
$taskManager = new TaskManager();
$tasks = $taskManager->getTasksByUser( $user_id );


// Dividir las tareas en columnas
$columns = array(
	'to-do' => array(),
	'in-progress' => array(),
	'done' => array(),
);

foreach ( $tasks as $task ) {
	$columns[ $task->stack ][] = $task;
}

?>
<head>
	<title><?php esc_html_e( 'My Board', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>
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

										<div class="input-group mb-3">
											<!-- Icono de búsqueda integrado en el campo -->
											<span class="input-group-text bg-white border-end-0">
												<i class="ri-search-line"></i>
											</span>
											
											<!-- Campo de búsqueda con botón de borrar (X) dentro -->
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attr_e( 'Search...', 'decker' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'decker' ); ?>">

										</div>

									</div>

									<h4 class="page-title"><?php echo esc_html_e( 'My Board', 'decker' ); ?>
										<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ) ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3"><?php esc_html_e( 'Add New', 'decker' ); ?></a>
									</h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

						<div class="row">
							<div class="col-12">
								<div class="board">
									<div class="tasks" data-plugin="dragula" data-containers='["task-list-to-do", "task-list-in-progress", "task-list-done"]'>
										<h5 class="mt-0 task-header"><?php esc_html_e( 'TO-DO', 'decker' ); ?> (<?php echo esc_html( count( $columns['to-do'] ) ); ?>)</h5>
										
										<div id="task-list-to-do" class="task-list-items">

											<?php foreach ( $columns['to-do'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard( true ); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e( 'In Progress', 'decker' ); ?> (<?php echo esc_html( count( $columns['in-progress'] ) ); ?>)</h5>
										
										<div id="task-list-in-progress" class="task-list-items">

											<?php foreach ( $columns['in-progress'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard( true ); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e( 'Done', 'decker' ); ?> (<?php echo esc_html( count( $columns['done'] ) ); ?>)</h5>
										<div id="task-list-done" class="task-list-items">

											<?php foreach ( $columns['done'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard( true ); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-4-->
									</div>

								</div> <!-- end .board-->
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

		<!-- dragula js-->
		<script src='https://cdnjs.cloudflare.com/ajax/libs/dragula/3.7.3/dragula.min.js'></script>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const searchInput = document.getElementById('searchInput');

				function filterTasks() {
					const searchText = searchInput.value.toLowerCase();
					document.querySelectorAll('.card').forEach((card) => {
						const titleElement = card.querySelector('.text-body');
						const title = titleElement ? titleElement.textContent.toLowerCase() : '';
						const matchesSearch = title.includes(searchText);

						if (matchesSearch) {
							card.style.display = '';
						} else {
							card.style.display = 'none';
						}
					});
				}

				searchInput.addEventListener('input', filterTasks);
			});
		</script>

	</body>
</html>
