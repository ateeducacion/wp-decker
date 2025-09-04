<?php
/**
 * Priority View Template
 *
 * This template displays the main priority view, including "Max Priority" tasks
 * and a per-user breakdown of tasks assigned for the current day.
 * It includes logic for importing tasks from previous days if none are set for today.
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Start output buffering to prevent "headers already sent" errors.
ob_start();

// Process form submission for importing tasks from a previous day.
if ( isset( $_POST['import_tasks_nonce'] ) ) {
	// Sanitize and verify the nonce for security.
	$import_tasks_nonce = sanitize_text_field( wp_unslash( $_POST['import_tasks_nonce'] ) );
	if ( wp_verify_nonce( $import_tasks_nonce, 'import_tasks' ) ) {

		// Sanitize and validate task IDs to ensure they are integers.
		$task_ids = isset( $_POST['task_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['task_ids'] ) ) : array();
		$current_user_id = get_current_user_id();

		// Mark selected tasks for today for the current user.
		foreach ( $task_ids as $task_id ) {
			$decker_tasks = new Decker_Tasks();
			$decker_tasks->add_user_date_relation( $task_id, $current_user_id );
		}

		// Redirect to the same page to avoid form resubmission on refresh.
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$redirect_url = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			wp_safe_redirect( esc_url( $redirect_url ) );
			exit;
		}
	}
}

// Include the main layout after processing any form submissions.
include 'layouts/main.php';

$task_manager    = new TaskManager();
$current_user_id = get_current_user_id();

// Check if the current user has any tasks marked for today.
$has_today_tasks = $task_manager->has_user_today_tasks();
$previous_tasks  = array();
$available_dates = array();

// If no tasks are scheduled for today, load tasks from previous days to offer for import.
if ( ! $has_today_tasks ) {
	// Find the latest date the user marked tasks (limited to 7 days back).
	$latest_date = $task_manager->get_latest_user_task_date( $current_user_id, 7 );

	if ( $latest_date ) {
		// If a recent date is found, load tasks from that specific date.
		$previous_tasks = $task_manager->get_user_tasks_marked_for_today_for_previous_days(
			$current_user_id,
			0, // 0 days back, since we're using a specific date.
			true,
			$latest_date
		);
	}

	// Get available dates for the import modal dropdown.
	$available_dates = $task_manager->get_user_task_dates( $current_user_id, 7 );
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
								<h4 class="page-title">
									<?php esc_html_e( 'Priority', 'decker' ); ?>
									<a href="<?php echo esc_url( add_query_arg( array( 'decker_page' => 'task' ), home_url( '/' ) ) ); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="btn btn-success btn-sm ms-3">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Task', 'decker' ); ?>
									</a>
								</h4>
							</div>
						</div>
					</div>

					<?php include 'layouts/top-alert.php'; ?>

					<?php if ( ! $has_today_tasks && ! empty( $previous_tasks ) ) : ?>
						<div id="alert-import-today-1" class="alert-import-today alert alert-warning alert-dismissible fade show" role="alert">
							<i class="ri-alert-fill"></i>
							<?php esc_html_e( 'You have no tasks defined for today. Do you want to import tasks from a previous day?', 'decker' ); ?>
							<button type="button" class="import-today btn btn-warning btn-sm ms-2" data-bs-toggle="modal" data-bs-target="#taskModal"><?php esc_html_e( 'Yes', 'decker' ); ?></button>
							<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'decker' ); ?>"></button>
						</div>
					<?php endif; ?>

					<div class="row">
						<div class="col-lg-12">
							<div class="card">
								<div class="d-flex card-header justify-content-between align-items-center">
									<h4 class="header-title"><?php esc_html_e( 'MAX PRIORITY', 'decker' ); ?> 🔥</h4>
								</div>


								<?php
								// Get all tasks with max_priority.
								$tasks = $task_manager->get_tasks(
									array(
										'meta_query' => array(
											'relation' => 'AND',
											array(
												'key'     => 'max_priority',
												'value'   => '1',
												'compare' => '=',
											),
											array(
												'key'     => 'hidden',
												'value'   => '1',
												'compare' => '!=',
											),
										),
									)
								);

								// Pre-sort tasks by board name for consistent grouping.
								usort(
									$tasks,
									function ( $a, $b ) {
										$a_board = $a->board ? $a->board->name : '';
										$b_board = $b->board ? $b->board->name : '';
										return strcmp( $a_board, $b_board );
									}
								);

								// First, group tasks by board ID for the mobile view.
								$tasks_by_board = array();
								foreach ( $tasks as $task ) {
									$board_id = $task->board ? $task->board->id : 0;
									if ( ! isset( $tasks_by_board[ $board_id ] ) ) {
										$tasks_by_board[ $board_id ] = array(
											'board' => $task->board, // Store the full board object.
											'tasks' => array(),
										);
									}
									$tasks_by_board[ $board_id ]['tasks'][] = $task;
								}
								?>

								<!-- START: Mobile View (hidden on md and up) -->
								<div class="priority-mobile-view d-md-none">
									<?php if ( empty( $tasks_by_board ) ) : ?>
										<p class="p-3 text-muted"><?php esc_html_e( 'No high priority tasks found.', 'decker' ); ?></p>
									<?php else : ?>
										<?php foreach ( $tasks_by_board as $group ) : ?>
											<div class="board-group mb-3">
												<h5 class="board-group-header">
													<?php if ( $group['board'] ) : ?>
														<span class="custom-badge" style="background-color: <?php echo esc_attr( $group['board']->color ); ?>;">
															<?php echo esc_html( $group['board']->name ); ?>
														</span>
													<?php else : ?>
														<span class="custom-badge bg-secondary">
															<?php esc_html_e( 'Uncategorized', 'decker' ); ?>
														</span>
													<?php endif; ?>
												</h5>
												<div class="list-group list-group-flush">
													<?php foreach ( $group['tasks'] as $task ) : ?>
														<div class="list-group-item task-item">
																						   <?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ); ?>
															<a href="
															<?php
															echo esc_url(
																add_query_arg(
																	array(
																		'decker_page' => 'task',
																		'id' => $task->ID,
																	),
																	home_url( '/' )
																)
															);
															?>
																		"
															   data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( $task->ID ); ?>"
															   class="task-title-link d-block">
																<?php echo esc_html( $task->title ); ?>
															</a>
															<div class="avatar-group mt-2">
																<?php if ( $task->responsable ) : ?>
																	<a href="#" class="avatar-group-item position-relative <?php echo $task->responsable->today ? ' today' : ''; ?>"
																	   data-bs-toggle="tooltip" data-bs-placement="top" 
																	   title="<?php echo esc_attr( $task->responsable->display_name ); ?>">
																	   <span class="badge badge_avatar"><i class="ri-star-s-fill"></i></span>
																		<img src="<?php echo esc_url( get_avatar_url( $task->responsable->ID ) ); ?>" alt="" class="rounded-circle avatar-xs">
																	</a>
																<?php endif; ?>
																<?php foreach ( $task->assigned_users as $user_info ) : ?>
																	<?php
																		continue;
																	?>
																	<a href="javascript: void(0);" class="avatar-group-item <?php echo $user_info->today ? ' today' : ''; ?>"
																	   data-bs-toggle="tooltip" data-bs-placement="top" 
																	   title="<?php echo esc_attr( $user_info->display_name ); ?>">
																		<img src="<?php echo esc_url( get_avatar_url( $user_info->ID ) ); ?>" alt="" class="rounded-circle avatar-xs">
																	</a>
																<?php endforeach; ?>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
											</div>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
								<!-- END: Mobile View -->
								
								<!-- START: Desktop View (hidden on sm and down) -->
								<div class="table-responsive d-none d-md-block">
									<table id="priority-table" class="table table-striped table-responsive">
										<thead>
											<tr>
												<th data-sort-default style="width: 10%;"><?php esc_html_e( 'Board', 'decker' ); ?></th>
												<th style="width: auto;"><?php esc_html_e( 'Title', 'decker' ); ?></th>
												<th class="d-none d-md-table-cell" style="width: 10%;"><?php esc_html_e( 'Responsable', 'decker' ); ?></th>
												<th style="width: 15%;" data-sort-method='none'><?php esc_html_e( 'Assigned Users', 'decker' ); ?></th>
											</tr>
										</thead>
										<tbody id="priority-id-table">
											<?php
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
													<td class="descripcion" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr( $task->title ); ?>">
																				   <?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ); ?>
														<a href="
														<?php
														echo esc_url(
															add_query_arg(
																array(
																	'decker_page' => 'task',
																	'id' => $task->ID,
																),
																home_url( '/' )
															)
														);
														?>
																	" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="<?php echo esc_attr( $task->ID ); ?>">
															<?php echo esc_html( $task->title ); ?>
														</a>
													</td>
													<td class="d-none d-md-table-cell">
														<div class="avatar-group mt-2">
															<?php if ( null != $task->responsable ) { ?>
															<a href="#" class="avatar-group-item position-relative <?php echo $task->responsable->today ? ' today' : ''; ?>"
															   data-bs-toggle="tooltip" data-bs-placement="top" 
															   title="<?php echo esc_attr( $task->responsable->display_name ); ?>">
															   <span class="badge badge_avatar"><i class="ri-star-s-fill"></i></span>
																<img src="<?php echo esc_url( get_avatar_url( $task->responsable->ID ) ); ?>" alt="" class="rounded-circle avatar-xs">
															</a>
															<?php } ?>
														</div>
													</td>
													<td>
														<div class="avatar-group mt-2">
															<?php
															foreach ( $task->assigned_users as $user_info ) :
																if ( $task->responsable && $task->responsable->ID == $user_info->ID ) {
																	continue;
																}
																$today_class = $user_info->today ? ' today' : '';
																?>
																<a href="javascript: void(0);" class="avatar-group-item <?php echo esc_attr( $today_class ); ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo esc_attr( $user_info->display_name ); ?>">
																	<img src="<?php echo esc_url( get_avatar_url( $user_info->ID ) ); ?>" alt="" class="rounded-circle avatar-xs">
																</a>
																<?php
															endforeach;
															?>
														</div>
													</td>
												</tr>
												<?php
											}
											?>
										</tbody>
									</table>
								</div>
								<!-- END: Desktop View -->

							</div> <!-- end card -->
						</div> <!-- end col-->
					</div>
					<!-- end row -->

					<div class="row" id="cards-container">
						<?php
						$options = get_option( 'decker_settings', array() );
						$selected_role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';
						$ignored_users = isset( $options['ignored_users'] ) ? array_map( 'intval', explode( ',', $options['ignored_users'] ) ) : array();

						$users = get_users(
							array(
								'role__in' => array( $selected_role, 'administrator' ),
								'orderby'  => 'display_name',
								'exclude'  => $ignored_users,
							)
						);

						foreach ( $users as $user ) {
							$card_class = ( $user->ID === $current_user_id ) ? 'card border-primary border' : 'card';
							$user_tasks = $task_manager->get_user_tasks_marked_for_today_for_previous_days( $user->ID, 0, false );
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
														<th class="py-1"><?php esc_html_e( 'Board', 'decker' ); ?></th>
														<th class="py-1"><?php esc_html_e( 'Title', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php if ( ! empty( $user_tasks ) ) : ?>
													<?php
													foreach ( $user_tasks as $task ) {
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
																															   <?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ); ?>
																															   <?php echo esc_html( $task->title ); ?>
																															   </a>
																														</td>
														</tr>
														<?php
													}
													?>
												<?php else : ?>
													<tr>
														<td colspan="2"><?php esc_html_e( 'No tasks for today.', 'decker' ); ?></td>
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
		<!-- End Page content -->
	</div>
	<!-- END wrapper -->

	<!-- Import Modal -->
	<div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered">
			<div class="modal-content">
				<form method="post" action="<?php echo esc_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ); ?>">
					<div class="modal-header">
						<h5 class="modal-title" id="taskModalLabel"><?php esc_html_e( 'Select tasks to import', 'decker' ); ?></h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php esc_attr_e( 'Close', 'decker' ); ?>"></button>
					</div>
					<div class="modal-body">
						<?php if ( ! empty( $available_dates ) ) : ?>
						<div class="mb-3">
							<label for="task-date-selector" class="form-label"><?php esc_html_e( 'Select date to import from:', 'decker' ); ?></label>
							<select id="task-date-selector" class="form-select">
								<?php
								foreach ( $available_dates as $date_str ) :
									$date_obj = DateTime::createFromFormat( 'Y-m-d', $date_str );
									$formatted_date = $date_obj ? date_i18n( get_option( 'date_format' ), $date_obj->getTimestamp() ) : $date_str;
									?>
									<option value="<?php echo esc_attr( $date_str ); ?>"><?php echo esc_html( $formatted_date ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php endif; ?>
						<table class="table table-striped table-hover">
							<thead class="table thead-sticky bg-light">
								<tr>
									<th scope="col" style="width: 50px;">
										<input type="checkbox" id="selectAllCheckbox">
									</th>                        
									<th scope="col"><?php esc_html_e( 'Board', 'decker' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Stack', 'decker' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Title', 'decker' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<!-- Tasks will be loaded here via AJAX -->
							</tbody>
						</table>
					</div>
					<div class="modal-footer">
						<?php wp_nonce_field( 'import_tasks', 'import_tasks_nonce' ); ?>
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
						<button type="submit" class="btn btn-primary import-selected-tasks" disabled><?php esc_html_e( 'Import', 'decker' ); ?></button>
					</div>
				</form>
			</div>
		</div>

		<div class="modal-body">
		  <?php if ( ! empty( $available_dates ) ) : ?>
		  <div class="mb-3">
			<label for="task-date-selector" class="form-label"><?php esc_html_e( 'Select date to import from:', 'decker' ); ?></label>
			<select id="task-date-selector" class="form-select">
				<?php
				foreach ( $available_dates as $date_str ) :
					$date_obj = DateTime::createFromFormat( 'Y-m-d', $date_str );
					// Use date_i18n to get the localized date format.
					$formatted_date = $date_obj ? date_i18n( get_option( 'date_format' ), $date_obj->getTimestamp() ) : $date_str;
					?>
					<option value="<?php echo esc_attr( $date_str ); ?>"><?php echo esc_html( $formatted_date ); ?></option>
				<?php endforeach; ?>
			</select>
		  </div>
		  <?php endif; ?>
		  <!-- Tasks will be loaded here -->
		  <table class="table table-striped table-hover">
			<thead class="table thead-sticky bg-light">
				<tr>
					<th scope="col" style="width: 50px;">
						<input type="checkbox" id="selectAllCheckbox" class="">
					</th>                        
										<th scope="col"><?php esc_html_e( 'Board', 'decker' ); ?></th>
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
												<td>
														<?php echo wp_kses_post( Decker_Tasks::get_stack_icon_html( $task->stack ) ); ?>
														<?php echo esc_html( $task->title ); ?>
												</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
								<tr>
										<td colspan="3"><?php esc_html_e( 'There are no tasks from previous days to import.', 'decker' ); ?></td>
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
	<!-- END Import Modal -->

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php include 'layouts/task-modal.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
		var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
		
		document.addEventListener('DOMContentLoaded', function() {
			const selectAllCheckbox = document.getElementById('selectAllCheckbox');
			const importButton = document.querySelector('.import-selected-tasks');
			const dateSelector = document.getElementById('task-date-selector');
			const tableBody = document.querySelector('#taskModal tbody');

			function updateImportButton() {
				const currentCheckboxes = document.querySelectorAll('.task-checkbox');
				const anyChecked = Array.from(currentCheckboxes).some(checkbox => checkbox.checked);
				if (importButton) {
					importButton.disabled = !anyChecked;
				}
			}

			function loadTasksFromDate(dateStr) {
				tableBody.innerHTML = '<tr><td colspan="4"><?php esc_html_e( 'Loading tasks...', 'decker' ); ?></td></tr>';
				
				jQuery.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'load_tasks_by_date',
						date: dateStr,
						user_id: <?php echo esc_js( get_current_user_id() ); ?>,
						nonce: '<?php echo esc_js( wp_create_nonce( 'load_tasks_by_date_nonce' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							tableBody.innerHTML = response.data;
							
							document.querySelectorAll('.task-checkbox').forEach(checkbox => {
								checkbox.addEventListener('change', updateImportButton);
							});
							
							document.querySelectorAll('.task-row').forEach(row => {
								row.addEventListener('click', function(event) {
									if (event.target.tagName !== 'INPUT') {
										const checkbox = this.querySelector('.task-checkbox');
										if (checkbox) {
											checkbox.checked = !checkbox.checked;
											updateImportButton();
										}
									}
								});
							});
							
							if (selectAllCheckbox) {
								selectAllCheckbox.checked = false;
							}
							
							updateImportButton();
						} else {
							tableBody.innerHTML = '<tr><td colspan="4"><?php esc_html_e( 'Error loading tasks.', 'decker' ); ?></td></tr>';
						}
					},
					error: function() {
						tableBody.innerHTML = '<tr><td colspan="4"><?php esc_html_e( 'Error loading tasks.', 'decker' ); ?></td></tr>';
					}
				});
			}

			if (dateSelector) {
				dateSelector.addEventListener('change', function() {
					loadTasksFromDate(this.value);
				});
				// Initial load for the default selected date
				if (dateSelector.options.length > 0) {
					loadTasksFromDate(dateSelector.value);
				}
			}

			const importTodayButton = document.querySelector('.import-today');
			if (importTodayButton) {
				importTodayButton.addEventListener('click', function() {
					if (dateSelector && dateSelector.options.length > 0) {
						dateSelector.selectedIndex = 0;
						const event = new Event('change');
						dateSelector.dispatchEvent(event);
					}
				});
			}

			if (selectAllCheckbox) {
				selectAllCheckbox.addEventListener('change', function() {
					const isChecked = this.checked;
					document.querySelectorAll('.task-checkbox').forEach(checkbox => {
						checkbox.checked = isChecked;
					});
					updateImportButton();
				});
			}
			
			// This initial call is important for the case where there are no dates to select from.
			updateImportButton(); 
		});
	</script>
	
	<script>
		// Initialize Tablesort for the desktop priority table.
		jQuery(document).ready(function() {
			if (document.getElementById('priority-table')) {
				new Tablesort(document.getElementById('priority-table'));
			}
		});
	</script>
</body>
</html>