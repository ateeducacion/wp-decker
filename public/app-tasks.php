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
	   height: 38px; /* Ensures they have the same height */
	   padding: 6px 12px; /* Adjusts padding to align with Bootstrap forms */
	   font-size: 14px; /* Maintains consistent typography */
	   line-height: 1.5;
	   border-radius: 4px; /* Rounded borders */
	   border: 1px solid #ced4da; /* Consistent border color */
	   background-color: #f8f9fa; /* Light background for buttons */
	   color: #495057; /* Text consistent with the theme */
	   white-space: nowrap; /* Prevents text from overflowing */
	   min-width: 120px; /* Minimum width to ensure the text isn't cut off */
}

#searchBuilderContainer .dt-button:hover,
#boardFilter:hover {
	   background-color: #e2e6ea; /* Background on hover */
	   color: #212529; /* Text on hover */
	   border-color: #dae0e5; /* Border on hover */
}

#searchBuilderContainer {
	display: flex;
	align-items: center;
}

#searchBuilderContainer .dt-button {
	   margin-right: 8px; /* Space between buttons */
	   width: auto; /* Adjusts the width according to the content */
	   text-align: center; /* Centers the text inside the button */
}

.dataTables_wrapper .dataTables_length {
	   margin-bottom: 16px; /* Bottom spacing */
}

.dataTables_wrapper .dataTables_length select {
	   width: auto; /* Adjusts width to content */
	   display: inline-block; /* Proper alignment */
	   margin-right: 10px; /* Space between the select and other elements */
}

.dataTables_wrapper .dataTables_filter {
	   margin-bottom: 16px; /* Bottom spacing */
}





/* Ensures the table is responsive */
.table-responsive {
	overflow-x: auto;
}

table#tablaTareas {
	width: 100%;
	   table-layout: auto; /* Makes the columns adjust to the content */
}

table#tablaTareas th,
table#tablaTareas td {
	   white-space: nowrap; /* Prevents text overflow */
	   word-break: break-word; /* Breaks long words to prevent overflow */
}

/* Description column - allow more space and wrapping */
table#tablaTareas td:nth-child(4) {
	white-space: normal;
	word-wrap: break-word;
}

/* Labels/Tags column - constrain width with ellipsis */
table#tablaTareas td:nth-child(5) {
	max-width: 150px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Stack column - compact display */
table#tablaTareas td:nth-child(3) {
	white-space: nowrap;
}

/* If you want to hide a column on mobile, you can use display: none; */
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
								<h4 class="page-title"><?php echo esc_html( $page_title ); ?> <a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ) ); ?>" class="btn btn-success btn-sm ms-3 <?php echo esc_attr( $class_disabled ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal"><i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Task', 'decker' ); ?></a></h4>

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
														<th class="c-responsable"><?php esc_html_e( 'Responsable', 'decker' ); ?></th>
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

													// Task max priority.
													echo '<td>' . ( $task->max_priority ? '🔥' : '' ) . '</td>';

													// Task board.
													echo '<td>';

													if ( null === $task->board ) {
														echo '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . esc_attr( 'Undefined board', 'decker' ) . '</span>';
													} else {
														echo '<span class="badge rounded-pill" style="background-color: ' . esc_attr( $task->board->color ) . ';">' . esc_html( $task->board->name ) . '</span>';
													}
													echo '</td>';

																									   // Task stack.
													$stack_label = Decker_Tasks::get_stack_label( $task->stack );
													echo '<td data-order="' . esc_attr( $stack_label ) . '" data-search="' . esc_attr( $stack_label ) . '">' . wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ) . '</td>';


													// Task title.
													echo '<td><a href="' . esc_url(
														add_query_arg(
															array(
																'decker_page' => 'task',
																'id'          => $task->ID,
															),
															home_url( '/' )
														)
													) . '" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="' . esc_attr( $task->ID ) . '">' . esc_html( $task->title ) . '</a></td>';


													// Labels.
													echo '<td>';
													foreach ( $task->labels as $label ) {
														echo '<span class="badge" style="background-color: ' . esc_attr( $label->color ) . ';">' . esc_html( $label->name ) . '</span> ';
													}
													echo '</td>';


													// Responsable.
													echo '<td>';
													echo '<div class="avatar-group">';

													if ( $task->responsable ) {
														echo '<a href="javascript: void(0);" class="avatar-group-item avatar-group-item-responsable" data-bs-toggle="tooltip" data-bs-placement="top" aria-label="' . esc_attr( $task->responsable->display_name ) . '" data-bs-original-title="' . esc_attr( $task->responsable->display_name ) . '">';
														echo '<span class="d-none">' . esc_attr( $task->responsable->display_name ) . '</span>';
														echo '<img src="' . esc_url( get_avatar_url( $task->responsable->ID ) ) . '" alt="' . esc_attr( $user->display_name ) . '" class="rounded-circle avatar-xs">';
														echo '</a>';
													}

													echo '</div></td>';


													// Assigned users.
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

													// Remaining time.
													echo '<td data-order="' . esc_attr( $task->duedate?->format( 'Y-m-d' ) ) . '" class="due-date">';
													if ( $task->duedate instanceof DateTime ) {
														$due_midnight = clone $task->duedate;
														$due_midnight->setTime( 0, 0, 0 );
														$today_midnight = new DateTime( 'today' );

														$date_class = '';
														if ( $due_midnight == $today_midnight ) {
															$date_class = 'due-today';
														} elseif ( $due_midnight < $today_midnight ) {
															$date_class = 'due-past';
														}

														echo '<span class="' . esc_attr( $date_class ) . '" title="' . esc_attr( $task->duedate->format( 'Y-m-d' ) ) . '">';
													} else {
														echo '<span class="due-none">';
													}
													echo esc_html( $task->get_relative_time() );
													echo '</span></td>';


													// Context menu.
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
	// dayjs.extend(dayjs_plugin_relativeTime);

	// Set locale to Spanish
	// dayjs.locale('es');

	function setupAllTasksTable(initialBoard) {
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
								columns: [1, 2, 3, 4, 5, 6],
							},
							columns: [1, 2, 3, 4, 5, 6],
						},
					},
					{
						extend: 'print',
						className: 'd-none d-md-block', // Hide on mobile
					},
					{
						extend: 'csv',
						className: 'd-none d-md-block', // Hide on mobile
					},
					// {
					// 	extend: 'excel',
					// 	className: 'd-none d-md-block', // Hide on mobile
					// },
					// {
					// 	extend: 'pdf',
					// 	className: 'd-none d-md-block', // Hide on mobile
					// },
				],
				dom: '<"ms-2"l><"d-flex justify-content-between align-items-center"<"me-2"B>f>rtip', // Adjust layout
				columnDefs: [
					{
						searchPanes: {
							show: false,
						},
						targets: [1, 7], // Columns for which SearchPanes is disabled
					},
					{
						targets: 2, // Columna 3
						searchBuilder: {
							disable: true
						}
					},
					{
						targets: [4, 5, 8],
						orderable: false
					},
					{
						targets: 7, // due-date column
						type: 'date',
					},
					// Column width adjustments for better distribution
					// Explicit widths for key columns; remaining columns auto-size
					{
						targets: 3, // Description column (0-indexed: P., Board, Stack, Description)
						width: '35%'
					},
					{
						targets: 4, // Tags/Labels column
						width: '12%'
					},
					{
						targets: 2, // Stack column
						width: '8%'
					}
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
					
					// Apply initial filter if it exists
					if (initialBoard) {
						tablaElement.column(1).search(initialBoard).draw();
					}
				}
			});

			// Filter by board using combobox
			jQuery('#boardFilter').on('change', function () {
				const boardValue = this.value;
				tablaElement.column(1).search(boardValue).draw();
				// Update URL with the new filter
				updateUrlWithFilters(boardValue);
			});
		}
	}

	// Function to update the URL with filter parameters
	function updateUrlWithFilters(boardFilter) {
		const url = new URL(window.location);
		if (boardFilter) {
			url.searchParams.set('board', boardFilter);
		} else {
			url.searchParams.delete('board');
		}
		window.history.replaceState(null, '', url);
	}

	// Function to read parameters from the URL
	function getUrlParam(name) {
		const urlParams = new URLSearchParams(window.location.search);
		return urlParams.get(name);
	}

	// Call setup function when document is ready
	jQuery(document).ready(function () {
		// Read parameters from the URL
		const initialBoard = getUrlParam('board');
		
		setupAllTasksTable(initialBoard);

		// If there is a board filter in the URL, apply it
		if (initialBoard) {
			jQuery('#boardFilter').val(initialBoard);
		}

			   // Handle the click event on the "Today" checkbox
		document.querySelectorAll('.today-checkbox').forEach(checkbox => {
			checkbox.addEventListener('change', function() {
				const taskId = this.dataset.taskId;
				const isChecked = this.checked;
				// Here you can implement the AJAX logic to update the task status
				console.log(`Task ID: ${taskId}, Today: ${isChecked}`);
			});
		});
	});


document.querySelectorAll('#tablaTareas tbody a[data-bs-toggle="modal"]').forEach(function (link) {
	link.addEventListener('click', function (event) {
		const taskTitle = event.target.textContent; // Get the task title
		const modalTitle = document.querySelector('#task-modal .modal-title');
		modalTitle.textContent = taskTitle; // Change the modal title
		
		// Here you can add logic to change the modal content according to the task.
	});
});


	</script>

</body>

</html>
