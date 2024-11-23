<?php 
include 'layouts/main.php';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $term_type = sanitize_text_field($_POST['term_type']);
    $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : null;
    $term_name = sanitize_text_field($_POST['term_name']);
    $term_slug = !empty($_POST['term_slug']) ? sanitize_title($_POST['term_slug']) : '';
    
    $data = array(
        'name' => $term_name
    );

    // Only add color if it's not empty
    if (!empty($_POST['term_color'])) {
        $term_color = sanitize_hex_color_no_hash($_POST['term_color']);
        $data['color'] = '#' . $term_color;
    }

    if (!empty($term_slug)) {
        $data['slug'] = $term_slug;
    }

    $result = array('success' => false, 'message' => '');

    if ($term_type === 'board') {
        $result = BoardManager::saveBoard($data, $term_id);
    } else {
        $result = LabelManager::saveLabel($data, $term_id);
    }

    if ($result['success']) {
        wp_redirect(add_query_arg(array(
            'status' => 'success',
            'message' => urlencode($result['message'])
        )));
        exit;
    } else {
        wp_redirect(add_query_arg(array(
            'status' => 'error',
            'message' => urlencode($result['message'])
        )));
        exit;
    }
}

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
														<th data-sort-default><?php _e('Name', 'decker'); ?></th>
														<th><?php _e('Slug', 'decker'); ?></th>
														<th><?php _e('Color', 'decker'); ?></th>
														<th data-sort-method='none'><?php _e('Actions', 'decker'); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													foreach ($items as $item) {
														echo '<tr>';
														echo '<td>';
														if (!empty($item->color)) {
															echo '<span class="badge" style="background-color: ' . esc_attr($item->color) . ';">' . esc_html($item->name) . '</span>';
														} else {
															echo esc_html($item->name);
														}
														echo '</td>';
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
					<form id="term-form" method="POST">
						<input type="hidden" name="term_type" value="<?php echo esc_attr($type); ?>">
						<input type="hidden" name="term_id" id="term-id">
						<div class="mb-3">
							<label for="term-name" class="form-label"><?php _e('Name', 'decker'); ?> <span class="text-danger">*</span></label>
							<input type="text" class="form-control" id="term-name" name="term_name" required>
						</div>
						<div class="mb-3">
							<label for="term-slug" class="form-label"><?php _e('Slug', 'decker'); ?></label>
							<input type="text" class="form-control" id="term-slug" name="term_slug">
							<div class="form-text"><?php _e('Leave empty for automatic generation from name', 'decker'); ?></div>
						</div>
						<div class="mb-3">
							<label for="term-color" class="form-label"><?php _e('Color', 'decker'); ?></label>
							<input type="color" class="form-control" id="term-color" name="term_color">
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php _e('Close', 'decker'); ?></button>
							<button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i><?php _e('Save', 'decker'); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>


</body>

<script>
jQuery(document).ready(function($) {
    new Tablesort(document.getElementById('termsTable'));
    
    // Handle search functionality
    $('#searchInput').on('keyup', function() {
        const searchText = $(this).val().toLowerCase();
        $('#termsTable tbody tr').each(function() {
            const name = $(this).find('td:first-child').text().toLowerCase();
            const slug = $(this).find('td:nth-child(2)').text().toLowerCase();
            const color = $(this).find('td:nth-child(3)').text().toLowerCase();
            
            if (name.includes(searchText) || slug.includes(searchText) || color.includes(searchText)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

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
        const row = $(this).closest('tr');
        const nameCell = row.find('td:first-child');
        const name = nameCell.find('.badge').length ? nameCell.find('.badge').text() : nameCell.text();
        const slug = row.find('td:nth-child(2)').text();
        // Convert RGB color to Hex
        const rgbColor = row.find('td:nth-child(3) .color-box').css('background-color');
        const rgbToHex = function(rgb) {
            // If it's already hex, return it
            if (rgb.startsWith('#')) return rgb;
            
            // Convert rgb(r,g,b) to hex
            const rgbMatch = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
            if (!rgbMatch) return '#000000';
            
            const r = parseInt(rgbMatch[1]);
            const g = parseInt(rgbMatch[2]);
            const b = parseInt(rgbMatch[3]);
            
            return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        };
        
        const id = $(this).data('id');
        $('#termModalLabel').text('<?php _e("Edit Term", "decker"); ?>');
        $('#term-id').val(id);
        $('#term-name').val(name);
        $('#term-slug').val(slug);
        $('#term-color').val(rgbToHex(rgbColor));
        termModal.show();
    });

    // Handle delete term
    $('.delete-term').on('click', function(e) {
        e.preventDefault();
        const row = $(this).closest('tr');
        const termSlug = row.find('td:nth-child(2)').text();
        const userInput = prompt('<?php _e("To confirm deletion, please enter the term slug:", "decker"); ?> ' + termSlug);
        
        if (userInput === termSlug) {
            const termId = $(this).data('id');
            const termType = $(this).data('type');
            const form = $('<form method="POST">')
                .append($('<input type="hidden" name="term_type">').val(termType))
                .append($('<input type="hidden" name="term_id">').val(termId))
                .append($('<input type="hidden" name="action">').val('delete'));
            $('body').append(form);
            form.submit();
        } else if (userInput !== null) {
            alert('<?php _e("Incorrect slug. Deletion cancelled.", "decker"); ?>');
        }
    });
});
</script>

</html>
