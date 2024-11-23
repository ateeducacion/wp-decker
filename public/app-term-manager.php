<?php
include 'layouts/main.php';

$labelManager = new LabelManager();
$labels = $labelManager->getAllLabels();

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
							?>
								<h4 class="page-title"><?php _e('Labels', 'decker'); ?> <a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#label-modal"><?php _e('Add New Label', 'decker'); ?></a></h4>


	



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

											<table id="labelsTable" class="table table-striped table-bordered dataTable no-footer dt-responsive nowrap w-100" aria-describedby="labelsTable_info">
												<thead>
													<tr>
														<th><?php _e('Name', 'decker'); ?></th>
														<th><?php _e('Slug', 'decker'); ?></th>
														<th><?php _e('Color', 'decker'); ?></th>
														<th><?php _e('Actions', 'decker'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													foreach ($labels as $label) {
														echo '<tr>';
														echo '<td><span class="badge" style="background-color: ' . esc_attr($label->color) . ';">' . esc_html($label->name) . '</span></td>';
														echo '<td>' . esc_html($label->slug) . '</td>';
														echo '<td><span class="color-box" style="display: inline-block; width: 20px; height: 20px; background-color: ' . esc_attr($label->color) . ';"></span> ' . esc_html($label->color) . '</td>';
														echo '<td>';
														echo '<a href="#" class="btn btn-sm btn-info me-2 edit-label" data-label-id="' . esc_attr($label->id) . '"><i class="ri-pencil-line"></i></a>';
														echo '<a href="#" class="btn btn-sm btn-danger delete-label" data-label-id="' . esc_attr($label->id) . '"><i class="ri-delete-bin-line"></i></a>';
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


</body>

</html>
