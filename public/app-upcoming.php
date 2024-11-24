<?php
include 'layouts/main.php';

$current_date      = new DateTime();
$tomorrow_date     = ( new DateTime() )->modify( '+1 day' );
$next_7_days_date  = ( new DateTime() )->modify( '+7 days' );
$one_year_ago_date = ( new DateTime() )->modify( '-1 year' );

$taskManager = new TaskManager();

$tasks = $taskManager->getUpcomingTasksByDate( $one_year_ago_date, $next_7_days_date ); // TODO: Change this to a function getTaskByDate(from, until)

// Set the timezone to ensure consistency
date_default_timezone_set( 'UTC' ); // Change to your preferred timezone

// Initialize DateTime objects for current date and specific ranges

// Current date at 00:00:00
$current_date = new DateTime( 'today' );

// Yesterday at 23:59:59
$yesterday_end = ( clone $current_date )->modify( '-1 day' )->setTime( 23, 59, 59 );

// Today
$today_start = clone $current_date; // Today at 00:00:00
$today_end   = ( clone $current_date )->setTime( 23, 59, 59 );

// Tomorrow
$tomorrow_start = ( clone $current_date )->modify( '+1 day' ); // Tomorrow at 00:00:00
$tomorrow_end   = ( clone $tomorrow_start )->setTime( 23, 59, 59 );

// Next 7 Days (Day after tomorrow to seven days ahead)
$next_7_days_start = ( clone $current_date )->modify( '+2 days' ); // Day after tomorrow at 00:00:00
$next_7_days_end   = ( clone $current_date )->modify( '+7 days' )->setTime( 23, 59, 59 );

// Initialize columns with empty arrays
$columns = array(
	'delayed'     => array(),
	'today'       => array(),
	'tomorrow'    => array(),
	'next-7-days' => array(),
);

// Iterate through each task and categorize it
foreach ( $tasks as $task ) {
	// Ensure the task has a due date and it's a DateTime object
	if ( isset( $task->duedate ) && $task->duedate instanceof DateTime ) {
		// Clone the due date to avoid modifying the original
		$due_date = clone $task->duedate;

		// Categorize based on due date
		if ( $due_date <= $yesterday_end ) {
			// Delayed: Due up to yesterday at 23:59:59
			$columns['delayed'][] = $task;
		} elseif ( $due_date >= $today_start && $due_date <= $today_end ) {
			// Today: Due today from 00:00:00 to 23:59:59
			$columns['today'][] = $task;
		} elseif ( $due_date >= $tomorrow_start && $due_date <= $tomorrow_end ) {
			// Tomorrow: Due tomorrow from 00:00:00 to 23:59:59
			$columns['tomorrow'][] = $task;
		} elseif ( $due_date >= $next_7_days_start && $due_date <= $next_7_days_end ) {
			// Next 7 Days: Due from day after tomorrow up to seven days ahead
			$columns['next-7-days'][] = $task;
		}
		// Optional: Handle tasks beyond the next 7 days if needed
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<title><?php esc_htmlesc_html_e( 'Upcoming Tasks', 'decker' ); ?> | Decker</title>
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
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attresc_html_e( 'Search...', 'decker' ); ?>" aria-label="<?php esc_attresc_html_e( 'Search', 'decker' ); ?>">

											<!-- Select de usuarios -->
											<select id="boardUserFilter" class="form-select ms-2">
												<option value=""><?php esc_html_e( 'All Users', 'decker' ); ?></option>
												<?php
												$users = get_users();
												foreach ( $users as $user ) {
													echo '<option value="' . esc_attr( $user->display_name ) . '">' . esc_html( $user->display_name ) . '</option>';
												}
												?>
											</select>
										</div>

									</div>
									<h4 class="page-title"><?php esc_htmlesc_html_e( 'Upcoming Tasks', 'decker' ); ?>
										<a href="#" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3"><?php esc_html_e( 'Add New', 'decker' ); ?></a></h4>
								</div>
							</div>
						</div>     


<?php include 'layouts/top-alert.php'; ?>

						<div class="row">
							<div class="col-12">
								<div class="board">
									<div class="tasks">
										<h5 class="mt-0 task-header"><?php esc_html_e( 'DELAYED', 'decker' ); ?> (<?php echo count( $columns['delayed'] ); ?>)</h5>
										
										<div id="task-list-delayed" class="task-list-items">

											<?php foreach ( $columns['delayed'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-1-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e( 'Today', 'decker' ); ?> (<?php echo count( $columns['today'] ); ?>)</h5>
										
										<div id="task-list-today" class="task-list-items">

											<?php foreach ( $columns['today'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>


										</div> <!-- end company-list-3-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e( 'Tomorrow', 'decker' ); ?> (<?php echo count( $columns['tomorrow'] ); ?>)</h5>
										<div id="task-list-tomorrow" class="task-list-items">

											<?php foreach ( $columns['tomorrow'] as $task ) : ?>
											<!-- Task Item -->
												<?php $task->renderTaskCard(); ?>
											<!-- Task Item End -->
											<?php endforeach; ?>
											
										</div> <!-- end company-list-4-->
									</div>

									<div class="tasks">
										<h5 class="mt-0 task-header text-uppercase"><?php esc_html_e( 'Next 7 Days', 'decker' ); ?> (<?php echo count( $columns['next-7-days'] ); ?>)</h5>
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
