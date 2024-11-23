<?php
include 'layouts/main.php';

// Get the type from URL parameter, default to 'label'
$type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'label';

// Initialize the appropriate manager based on type
$items = [];
$title = '';
$addNewText = '';

if ($type === 'board') {
    $items = BoardManager::getAllBoards();
    $title = __('Boards', 'decker');
    $addNewText = __('Add New Board', 'decker');
} else {
    $items = LabelManager::getAllLabels();
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
														<th><?php _e('Color', 'decker'); ?></th>
														<th><?php _e('Actions', 'decker'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													foreach ($items as $item) {
														echo '<tr>';
														echo '<td><span class="badge" style="background-color: ' . esc_attr($item->color) . ';">' . esc_html($item->name) . '</span></td>';
														echo '<td>' . esc_html($item->slug) . '</td>';
														echo '<td><span class="color-box" style="display: inline-block; width: 20px; height: 20px; background-color: ' . esc_attr($item->color) . ';"></span> ' . esc_html($item->color) . '</td>';
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

	<!-- Term Modal -->
	<div class="modal fade" id="term-modal" tabindex="-1" aria-labelledby="termModalLabel" aria-hidden="true">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="termModalLabel"><?php _e('Add New Term', 'decker'); ?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<form id="term-form">
						<input type="hidden" id="term-id" name="term_id">
						<div class="mb-3">
							<label for="term-name" class="form-label"><?php _e('Name', 'decker'); ?></label>
							<input type="text" class="form-control" id="term-name" name="term_name" required>
						</div>
						<div class="mb-3">
							<label for="term-color" class="form-label"><?php _e('Color', 'decker'); ?></label>
							<input type="color" class="form-control" id="term-color" name="term_color">
						</div>
					</form>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('Close', 'decker'); ?></button>
					<button type="button" class="btn btn-primary" id="save-term"><?php _e('Save', 'decker'); ?></button>
				</div>
			</div>
		</div>
	</div>


</body>

<script>
jQuery(document).ready(function($) {
    new Tablesort(document.getElementById('termsTable'));
    
    // Store the current type for use in AJAX calls
    window.currentTermType = '<?php echo esc_js($type); ?>';

    // Initialize the modal
    const termModal = new bootstrap.Modal(document.getElementById('term-modal'));
    const termForm = document.getElementById('term-form');

    // Handle "Add New" button click
    $('.btn-success').on('click', function() {
        $('#termModalLabel').text('<?php _e("Add New Term", "decker"); ?>');
        termForm.reset();
    });

    // Handle edit button click
    $('.edit-term').on('click', function(e) {
        e.preventDefault();
        const termId = $(this).data('id');
        $('#termModalLabel').text('<?php _e("Edit Term", "decker"); ?>');
        
        // Make AJAX call to get term data
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_term_data',
                term_id: termId,
                type: window.currentTermType,
                nonce: '<?php echo wp_create_nonce("term_manager_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('#term-id').val(response.data.id);
                    $('#term-name').val(response.data.name);
                    $('#term-color').val(response.data.color);
                    termModal.show();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Handle save button click
    $('#save-term').on('click', function() {
        const formData = {
            action: 'save_term',
            type: window.currentTermType,
            term_id: $('#term-id').val(),
            term_name: $('#term-name').val(),
            term_color: $('#term-color').val(),
            nonce: '<?php echo wp_create_nonce("term_manager_nonce"); ?>'
        };

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            success: function(response) {
                if (response.success) {
                    location.reload(); // Refresh to show changes
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Handle delete button click
    $('.delete-term').on('click', function(e) {
        e.preventDefault();
        if (!confirm('<?php _e("Are you sure you want to delete this term?", "decker"); ?>')) {
            return;
        }

        const termId = $(this).data('id');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_term',
                term_id: termId,
                type: window.currentTermType,
                nonce: '<?php echo wp_create_nonce("term_manager_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload(); // Refresh to show changes
                } else {
                    alert(response.data.message);
                }
            }
        });
    });
});
</script>

</html>
