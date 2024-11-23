<?php
include 'layouts/main.php';

$taskManager = new TaskManager();

?>

<head>
	<title><?php _e('Tasks', 'decker'); ?> | Decker</title>
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


</body>

</html>
