<?php
include 'layouts/main.php';

// Get the type from URL parameter, default to 'label'
$type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'label';

// Initialize the appropriate manager based on type
$manager = null;
$items = [];
$title = '';
$addNewText = '';

if ($type === 'board') {
    $manager = new BoardManager();
    $items = $manager->getAllBoards();
    $title = __('Boards', 'decker');
    $addNewText = __('Add New Board', 'decker');
} else {
    $manager = new LabelManager();
    $items = $manager->getAllLabels();
    $title = __('Labels', 'decker');
    $addNewText = __('Add New Label', 'decker');
}

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
		
										<h4 class="page-title"><?php echo esc_html($title); ?> <a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#term-modal"><?php echo esc_html($addNewText); ?></a></h4>


								<div class="page-title-right">

									<div class="input-group mb-3">
										<!-- Icono de búsqueda integrado en el campo -->
										<span class="input-group-text bg-white border-end-0">
											<i class="ri-search-line"></i>
										</span>
										
										<!-- Campo de búsqueda con botón de borrar (X) dentro -->
										<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attr_e('Search...', 'decker'); ?>" aria-label="<?php esc_attr_e('Search', 'decker'); ?>">

									</div>

								</div>					
		



							</div>
							<!-- end page title -->

<?php include 'layouts/top-alert.php'; ?>

							<div class="row">
								<div class="col-12">
									<div class="card">
										<div class="card-body table-responsive">

											<table id="termsTable" class="table table-striped table-bordered dataTable no-footer dt-responsive nowrap w-100" aria-describedby="termsTable_info">
												<thead>
													<tr>
														<th><?php _e('Name', 'decker'); ?></th>
														<th><?php _e('Slug', 'decker'); ?></th>
														<?php if ($type === 'label'): ?>
															<th><?php _e('Color', 'decker'); ?></th>
														<?php endif; ?>
														<th><?php _e('Actions', 'decker'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													foreach ($items as $item) {
														echo '<tr>';
														if ($type === 'label') {
															echo '<td><span class="badge" style="background-color: ' . esc_attr($item->color) . ';">' . esc_html($item->name) . '</span></td>';
														} else {
															echo '<td>' . esc_html($item->name) . '</td>';
														}
														echo '<td>' . esc_html($item->slug) . '</td>';
														if ($type === 'label') {
															echo '<td><span class="color-box" style="display: inline-block; width: 20px; height: 20px; background-color: ' . esc_attr($item->color) . ';"></span> ' . esc_html($item->color) . '</td>';
														}
														echo '<td>';
														echo '<a href="#" class="btn btn-sm btn-info me-2 edit-term" data-type="' . esc_attr($type) . '" data-id="' . esc_attr($item->id) . '"><i class="ri-pencil-line"></i></a>';
														echo '<a href="#" class="btn btn-sm btn-danger delete-term" data-type="' . esc_attr($type) . '" data-id="' . esc_attr($item->id) . '"><i class="ri-delete-bin-line"></i></a>';
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

<script>
jQuery(document).ready(function() {
	new Tablesort(document.getElementById('termsTable'));
	
	// Store the current type for use in AJAX calls
	window.currentTermType = '<?php echo esc_js($type); ?>';
});
</script>

</html>
