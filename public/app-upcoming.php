<?php
include 'layouts/main.php';

$current_date = new DateTime();
$tomorrow_date = ( new DateTime() )->modify( '+1 day' );
$next_7_days_date = ( new DateTime() )->modify( '+7 days' );


$taskManager = new TaskManager();

$args = array(
	'post_type' => 'decker_task',
	'post_status' => 'publish',
	'meta_key' => 'stack',
	'orderby'  => 'meta_value_num',
	'order'    => 'ASC',
	'numberposts' => -1,
);
$tasks = $taskManager->getTasks(); // TODO: Change this to a function getTaskByDate(from, until)

$columns = array(
	'delayed' => array(),
	'today' => array(),
	'tomorrow' => array(),
	'next-7-days' => array(),
);


foreach ( $tasks as $task ) {

	if ($task->duedate) {
		if ( $task->duedate < $current_date ) {
			$columns['delayed'][] = $task;
		} elseif ( $task->duedate->format( 'Y-m-d' ) === $current_date->format( 'Y-m-d' ) ) {
			$columns['today'][] = $task;
		} elseif ( $task->duedate->format( 'Y-m-d' ) === $tomorrow_date->format( 'Y-m-d' ) ) {
			$columns['tomorrow'][] = $task;
		} elseif ( $task->duedate > $tomorrow_date && $task->duedate <= $next_7_days_date ) {
			$columns['next-7-days'][] = $task;
		}
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title>Upcoming Tasks | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>

	<?php include 'layouts/head-css.php'; ?>
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

										<div class="input-group mb-3">
											<!-- Icono de búsqueda integrado en el campo -->
											<span class="input-group-text bg-white border-end-0">
												<i class="ri-search-line"></i>
											</span>
											
											<!-- Campo de búsqueda con botón de borrar (X) dentro -->
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="Search..." aria-label="Search">

											<!-- Select de usuarios -->
											<select id="boardUserFilter" class="form-select ms-2">
												<option value="">All Users</option>
												<?php
												$users = get_users();
												foreach ( $users as $user ) {
													echo '<option value="' . esc_attr( $user->display_name ) . '">' . esc_html( $user->display_name ) . '</option>';
												}
												?>
											</select>
										</div>

									</div>
									<h4 class="page-title">Upcoming Tasks
										<a href="#" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3">Add New</a></h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

						<div class="row">
							<div class="col-12">
								<div class="board">
									<div class="tasks">
										<h5 class="mt-0 task-header">DELAYED (<?php echo count( $columns['delayed'] ); ?>)</h5>
										
										<div id="task-list-delayed" class="task-list-items">

											<?php foreach ( $columns['delayed'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">Today (<?php echo count( $columns['today'] ); ?>)</h5>
										
										<div id="task-list-today" class="task-list-items">

											<?php foreach ( $columns['today'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">Tomorrow (<?php echo count( $columns['tomorrow'] ); ?>)</h5>
										<div id="task-list-tomorrow" class="task-list-items">

											<?php foreach ( $columns['tomorrow'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-4-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase">Next 7 Days (<?php echo count( $columns['next-7-days'] ); ?>)</h5>
										<div id="task-list-next-7-days" class="task-list-items">

											<?php foreach ( $columns['next-7-days'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
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

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const searchInput = document.getElementById('searchInput');
				const boardUserFilter = document.getElementById('boardUserFilter');

				function filterTasks() {
					const searchText = searchInput.value.toLowerCase();
					const selectedUser = boardUserFilter.value;

					document.querySelectorAll('.card').forEach((card) => {
						const titleElement = card.querySelector('.text-body');
						const title = titleElement ? titleElement.textContent.toLowerCase() : '';
						const assignedUsers = Array.from(card.querySelectorAll('.avatar-group-item')).map(item => item.getAttribute('data-bs-original-title').toLowerCase());
						const matchesSearch = title.includes(searchText) || assignedUsers.some(user => user.includes(searchText));
						const matchesUserFilter = !selectedUser || assignedUsers.includes(selectedUser.toLowerCase());

						if (matchesSearch && matchesUserFilter) {
							card.style.display = '';
						} else {
							card.style.display = 'none';
						}
					});
				}

				searchInput.addEventListener('input', filterTasks);
				boardUserFilter.addEventListener('change', filterTasks);
			});
		</script>

	</body>
</html>
