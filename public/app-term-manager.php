<?php
/**
 * File app-term-manager
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;



// Process form submission.

if ( isset( $_POST['decker_term_nonce'] ) ) {
	$decker_term_nonce = sanitize_text_field( wp_unslash( $_POST['decker_term_nonce'] ) );
	if ( wp_verify_nonce( $decker_term_nonce, 'import_tasks' ) ) {
		wp_die( 'Security check failed' );
	}

	$term_type = isset( $_POST['term_type'] ) ? sanitize_text_field( wp_unslash( $_POST['term_type'] ) ) : 'board';
	$term_id   = isset( $_POST['term_id'] ) ? intval( $_POST['term_id'] ) : 0;

	// Check if this is a delete action.
	if ( isset( $_POST['action'] ) && 'delete' === $_POST['action'] ) {
		$result = array(
			'success' => false,
			'message' => '',
		);

		if ( 'board' === $term_type ) {
			$result = BoardManager::delete_board( $term_id );
		} else {
			$result = LabelManager::delete_label( $term_id );
		}

		wp_redirect(
			add_query_arg(
				array(
					'status'  => $result['success'] ? 'success' : 'error',
					'message' => urlencode( $result['message'] ),
				)
			)
		);

		exit;

	}

	// If not delete, process normal form submission.
	$term_name = isset( $_POST['term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['term_name'] ) ) : '';
	$term_slug = isset( $_POST['term_slug'] ) ? sanitize_title( wp_unslash( $_POST['term_slug'] ) ) : '';
	$description = isset( $_POST['term_description'] ) ? wp_kses_post( wp_unslash( $_POST['term_description'] ) ) : '';

	$data = array(
		'name' => $term_name,
	);

	$data['color'] = isset( $_POST['term_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['term_color'] ) ) : '';

	if ( ! empty( $term_slug ) ) {
		$data['slug'] = $term_slug;
	}

	$data['description'] = $description;

	$result = array(
		'success' => false,
		'message' => '',
	);

	if ( 'board' === $term_type ) {
		$result = BoardManager::save_board( $data, $term_id );
	} else {
		$result = LabelManager::save_label( $data, $term_id );
	}

	if ( $result['success'] ) {
		wp_redirect(
			add_query_arg(
				array(
					'status'  => 'success',
					'message' => urlencode( $result['message'] ),
				)
			)
		);
		exit;
	} else {
		wp_redirect(
			add_query_arg(
				array(
					'status'  => 'error',
					'message' => urlencode( $result['message'] ),
				)
			)
		);
		exit;
	}
}


include 'layouts/main.php';

// Get the type from URL parameter, default to 'label'.
$selected_type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : 'label';

// Initialize the appropriate manager based on type.
$items      = array();
$page_title      = '';
$add_new_text = '';

if ( 'board' === $selected_type ) {
	$items      = BoardManager::get_all_boards();
	$page_title      = __( 'Boards', 'decker' );
	$add_new_text = __( 'Add New Board', 'decker' );
} else {
	$items      = LabelManager::get_all_labels();
	$page_title      = __( 'Labels', 'decker' );
	$add_new_text = __( 'Add New Label', 'decker' );
}

?>

<head>
	<title><?php esc_html_e( 'Tasks', 'decker' ); ?> | Decker</title>
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
		
										<h4 class="page-title"><?php echo esc_html( $page_title ); ?> <a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#term-modal"><?php echo esc_html( $add_new_text ); ?></a></h4>


								<div class="page-title-right">

									<div class="input-group mb-3">
										<!-- Search icon integrated in the field -->
										<span class="input-group-text bg-white border-end-0">
											<i class="ri-search-line"></i>
										</span>
										
										<!-- Search field with clear button (X) inside -->
										<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attr_e( 'Search...', 'decker' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'decker' ); ?>">

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
														<th data-sort-default><?php esc_html_e( 'Name', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Slug', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Color', 'decker' ); ?></th>
														<th data-sort-method='none'><?php esc_html_e( 'Actions', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php
													foreach ( $items as $item ) {
														echo '<tr>';
														echo '<td class="term-name">';
														if ( ! empty( $item->color ) ) {
															echo '<span class="badge" style="background-color: ' . esc_attr( $item->color ) . ';">' . esc_html( $item->name ) . '</span>';
														} else {
															echo esc_html( $item->name );
														}
														echo '</td>';
														echo '<td class="term-slug">' . esc_html( $item->slug ) . '</td>';
														echo '<td class="term-color"><span class="color-box" style="display: inline-block; width: 20px; height: 20px; background-color: ' . esc_attr( $item->color ) . ';"></span> ' . esc_html( $item->color ) . '</td>';
														echo '<td>';
														echo '<a href="#" class="btn btn-sm btn-info me-2 edit-term" data-type="' . esc_attr( $selected_type ) . '" data-id="' . esc_attr( $item->id ) . '"><i class="ri-pencil-line"></i></a>';
														echo '<a href="#" class="btn btn-sm btn-danger delete-term" data-type="' . esc_attr( $selected_type ) . '" data-id="' . esc_attr( $item->id ) . '"><i class="ri-delete-bin-line"></i></a>';

														// Hidden span with the term-description (for boards).
														if ( 'board' === $selected_type ) {
															echo '<span class="term-description d-none">' . esc_html( $item->description ) . '</span>';
														}
														echo '</td>';
														echo '</tr>';
													}

													?>
													
													<!-- Additional task rows can be added here -->
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
					<h5 class="modal-title" id="termModalLabel"><?php esc_html_e( 'Add New Term', 'decker' ); ?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<form id="term-form" method="POST">
						<input type="hidden" name="term_type" value="<?php echo esc_attr( $selected_type ); ?>">
						<input type="hidden" name="term_id" id="term-id">

						<?php wp_nonce_field( 'decker_term_action', 'decker_term_nonce' ); ?>
						<div class="mb-3">
							<label for="term-name" class="form-label"><?php esc_html_e( 'Name', 'decker' ); ?> <span class="text-danger">*</span></label>
							<input type="text" class="form-control" id="term-name" name="term_name" required>
						</div>
						<div class="mb-3">
							<label for="term-slug" class="form-label"><?php esc_html_e( 'Slug', 'decker' ); ?></label>
							<input type="text" class="form-control" id="term-slug" name="term_slug">
							<div class="form-text"><?php esc_html_e( 'Leave empty for automatic generation from name', 'decker' ); ?></div>
						</div>
						<div class="mb-3">
							<label for="term-color" class="form-label"><?php esc_html_e( 'Color', 'decker' ); ?></label>
							<input type="color" class="form-control" id="term-color" name="term_color">
						</div>
						<?php if ( 'board' === $selected_type ) : ?>
						<div class="mb-3">
							<label for="term-description" class="form-label"><?php esc_html_e( 'Description', 'decker' ); ?></label>
							<textarea class="form-control" id="term-description" name="term_description" rows="3"></textarea>
							<div class="form-text"><?php esc_html_e( 'Only basic HTML formatting is allowed (i, b, em, strong, br)', 'decker' ); ?></div>
						</div>
						<?php endif; ?>
						<div class="modal-footer">
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
							<button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i><?php esc_html_e( 'Save', 'decker' ); ?></button>
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
	
	// Handle search functionality.
	$('#searchInput').on('keyup', function() {
		const searchText = $(this).val().toLowerCase();
		$('#termsTable tbody tr').each(function() {

			const name = $(this).find('.term-name').text().toLowerCase();
			const slug = $(this).find('.term-slug').text().toLowerCase();
			const color = $(this).find('.term-color').text().toLowerCase();			


			if (name.includes(searchText) || slug.includes(searchText) || color.includes(searchText)) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	});

	// Initialize the modal.
	const termModal = new bootstrap.Modal(document.getElementById('term-modal'));
	const termForm = document.getElementById('term-form');

	// Handle "Add New" button click.
	$('.btn-success').on('click', function() {
		$('#termModalLabel').text('<?php esc_html_e( 'Add New Term', 'decker' ); ?>');
		termForm.reset();
	});

	// Handle edit button click.
	$('.edit-term').on('click', function(e) {
		e.preventDefault();
		const row = $(this).closest('tr');

		const name = row.find('.term-name').text().trim();
		const slug = row.find('.term-slug').text().trim();
		const hexColor = row.find('.term-color').text().trim();
		const description = row.find('.term-description').text().trim();
		const id = $(this).data('id');
		$('#termModalLabel').text('<?php esc_html_e( 'Edit Term', 'decker' ); ?>');
		$('#term-id').val(id);
		$('#term-name').val(name);
		$('#term-slug').val(slug);
		$('#term-color').val(hexColor);
		$('#term-description').val(description);
		termModal.show();
	});

	// Handle delete term.
	$('.delete-term').on('click', function(e) {
		e.preventDefault();
		const row = $(this).closest('tr');
		const termSlug = row.find('.term-slug').text().trim();

		let promptMessage = "<?php echo esc_js( __( 'To confirm deletion, please enter the term slug:', 'decker' ) ); ?>";
		if ($(this).data('type') === 'board') {
			const warningMessage = "<?php echo esc_js( __( 'WARNING: Deleting a board is dangerous! All tasks assigned to this board will be left without a board.', 'decker' ) ); ?>";
			promptMessage = warningMessage + '\n\n' + promptMessage
		}
		const userInput = prompt(promptMessage + ' ' + termSlug);
		
		if (userInput === termSlug) {
			const termId = $(this).data('id');
			const termType = $(this).data('type');
			const form = $('<form method="POST">')
				.append($('<input type="hidden" name="term_type">').val(termType))
				.append($('<input type="hidden" name="term_id">').val(termId))
				.append($('<input type="hidden" name="action">').val('delete'))
				.append($('<input type="hidden" name="decker_term_nonce">').val('<?php echo esc_attr( wp_create_nonce( 'decker_term_action' ) ); ?>'));
			$('body').append(form);
			form.submit();
		} else if (userInput !== null) {
			alert('<?php esc_html_e( 'Incorrect slug. Deletion cancelled.', 'decker' ); ?>');
		}
	});
});
</script>

</html>
