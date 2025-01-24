<?php
/**
 * File app-event-manager
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

$events = EventManager::get_events();
?>

<head>
	<title><?php esc_html_e( 'Events', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>
	<?php include 'layouts/head-css.php'; ?>
</head>

<body <?php body_class(); ?>>
	<!-- Begin page -->
	<div class="wrapper">
		<?php include 'layouts/menu.php'; ?>
		<div class="content-page">
			<div class="content">
				<!-- Start Content-->
				<div class="container-fluid">
					<div class="row">
						<div class="col-xxl-12">
							<div class="page-title-box d-flex align-items-center justify-content-between">
								<h4 class="page-title">
									<?php esc_html_e( 'Events', 'decker' ); ?>
									<a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#event-modal">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Event', 'decker' ); ?>
									</a>
								</h4>
								<div class="page-title-right">
									<div class="input-group mb-3">
										<span class="input-group-text bg-white border-end-0">
											<i class="ri-search-line"></i>
										</span>
										<input id="searchInput" type="search" class="form-control border-start-0" 
											placeholder="<?php esc_attr_e( 'Search...', 'decker' ); ?>" 
											aria-label="<?php esc_attr_e( 'Search', 'decker' ); ?>">
									</div>
								</div>
							</div>

							<?php include 'layouts/top-alert.php'; ?>

							<div class="row">
								<div class="col-12">
									<div class="card">
										<div class="card-body table-responsive">
											<table id="eventsTable" class="table table-striped table-bordered dataTable no-footer dt-responsive nowrap w-100">
												<thead>
													<tr>
														<th data-sort-default><?php esc_html_e( 'Title', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Start', 'decker' ); ?></th>
														<th><?php esc_html_e( 'End', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Location', 'decker' ); ?></th>
														<th><?php esc_html_e( 'Category', 'decker' ); ?></th>
														<th data-sort-method='none'><?php esc_html_e( 'Actions', 'decker' ); ?></th>
													</tr>
												</thead>
												<tbody>
													<?php foreach ( $events as $event ) : ?>
														<tr>
															<td class="event-title">
																<?php echo esc_html( $event->get_title() ); ?>
															</td>
															<td class="event-start">
																<?php echo esc_html( $event->get_start_date()->format( 'Y-m-d H:i' ) ); ?>
															</td>
															<td class="event-end">
																<?php echo esc_html( $event->get_end_date()->format( 'Y-m-d H:i' ) ); ?>
															</td>
															<td class="event-location">
																<?php echo esc_html( $event->get_location() ); ?>
															</td>
															<td class="event-category">
																<span class="badge <?php echo esc_attr( $event->get_category() ); ?>">
																	<?php echo esc_html( str_replace( 'bg-', '', $event->get_category() ) ); ?>
																</span>
															</td>
															<td>
																<a href="#" class="btn btn-sm btn-info me-2 edit-event" 
																   data-id="<?php echo esc_attr( $event->get_id() ); ?>">
																	<i class="ri-pencil-line"></i>
																</a>
																<a href="#" class="btn btn-sm btn-danger delete-event" 
																   data-id="<?php echo esc_attr( $event->get_id() ); ?>">
																	<i class="ri-delete-bin-line"></i>
																</a>
																<span class="event-description d-none">
																	<?php echo esc_html( $event->get_description() ); ?>
																</span>
																<span class="event-url d-none">
																	<?php echo esc_url( $event->get_url() ); ?>
																</span>
																<span class="event-assigned-users d-none">
																	<?php echo esc_attr( json_encode( $event->get_assigned_users() ) ); ?>
																</span>
															</td>
														</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php include 'layouts/footer.php'; ?>
		</div>
	</div>

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php 
	include 'layouts/footer-scripts.php';
	wp_localize_script('jquery', 'wpApiSettings', array(
		'root' => esc_url_raw(rest_url()),
		'nonce' => wp_create_nonce('wp_rest')
	));
	?>

	<!-- Event Modal -->
	<div class="modal fade" id="event-modal" tabindex="-1">
		<div class="modal-dialog">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="eventModalLabel"><?php esc_html_e( 'Add New Event', 'decker' ); ?></h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">
					<form id="event-form" method="POST">
						<input type="hidden" name="event_id" id="event-id">
						<?php wp_nonce_field( 'decker_event_action', 'decker_event_nonce' ); ?>

						<div class="mb-3">
							<label for="event-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?> <span class="text-danger">*</span></label>
							<input type="text" class="form-control" id="event-title" name="event_title" required>
						</div>

						<div class="mb-3">
							<label for="event-description" class="form-label"><?php esc_html_e( 'Description', 'decker' ); ?></label>
							<textarea class="form-control" id="event-description" name="event_description" rows="3"></textarea>
						</div>

						<div class="mb-3">
							<label class="form-label"><?php esc_html_e( 'Date and Time', 'decker' ); ?></label>
							<div class="row g-2">
								<div class="col-md-6">
									<input type="datetime-local" class="form-control" name="event_start" id="event-start" 
										step="900" required />
									<small class="text-muted"><?php esc_html_e( 'From', 'decker' ); ?></small>
								</div>
								<div class="col-md-6">
									<input type="datetime-local" class="form-control" name="event_end" id="event-end" 
										step="900" required />
									<small class="text-muted"><?php esc_html_e( 'To', 'decker' ); ?></small>
								</div>
							</div>
						</div>

						<div class="mb-3">
							<label for="event-location" class="form-label"><?php esc_html_e( 'Location', 'decker' ); ?></label>
							<input type="text" class="form-control" id="event-location" name="event_location">
						</div>

						<div class="mb-3">
							<label for="event-url" class="form-label"><?php esc_html_e( 'URL', 'decker' ); ?></label>
							<input type="url" class="form-control" id="event-url" name="event_url">
						</div>

						<div class="mb-3">
							<label for="event-category" class="form-label"><?php esc_html_e( 'Category', 'decker' ); ?></label>
							<select class="form-select" id="event-category" name="event_category">
								<option value="bg-danger"><?php esc_html_e( 'Danger', 'decker' ); ?></option>
								<option value="bg-success"><?php esc_html_e( 'Success', 'decker' ); ?></option>
								<option value="bg-primary" selected><?php esc_html_e( 'Primary', 'decker' ); ?></option>
								<option value="bg-info"><?php esc_html_e( 'Info', 'decker' ); ?></option>
								<option value="bg-dark"><?php esc_html_e( 'Dark', 'decker' ); ?></option>
								<option value="bg-warning"><?php esc_html_e( 'Warning', 'decker' ); ?></option>
							</select>
						</div>

						<div class="mb-3">
							<label for="event-assigned-users" class="form-label"><?php esc_html_e( 'Assigned Users', 'decker' ); ?></label>
							<select class="form-select" id="event-assigned-users" name="event_assigned_users[]" multiple>
								<?php
								$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
								foreach ( $users as $user ) {
									printf(
										'<option value="%d">%s</option>',
										esc_attr( $user->ID ),
										esc_html( $user->display_name )
									);
								}
								?>
							</select>
						</div>

						<div class="modal-footer">
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
								<?php esc_html_e( 'Close', 'decker' ); ?>
							</button>
							<button type="submit" class="btn btn-primary">
								<i class="ri-save-line me-1"></i><?php esc_html_e( 'Save', 'decker' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

</body>

<script>
jQuery(document).ready(function($) {
	new Tablesort(document.getElementById('eventsTable'));
	
	// Handle search functionality
	$('#searchInput').on('keyup', function() {
		const searchText = $(this).val().toLowerCase();
		$('#eventsTable tbody tr').each(function() {
			const title = $(this).find('.event-title').text().toLowerCase();
			const start = $(this).find('.event-start').text().toLowerCase();
			const end = $(this).find('.event-end').text().toLowerCase();
			const location = $(this).find('.event-location').text().toLowerCase();
			const category = $(this).find('.event-category').text().toLowerCase();

			if (title.includes(searchText) || start.includes(searchText) || 
				end.includes(searchText) || location.includes(searchText) || 
				category.includes(searchText)) {
				$(this).show();
			} else {
				$(this).hide();
			}
		});
	});

	// Initialize the modal
	const eventModal = new bootstrap.Modal(document.getElementById('event-modal'));
	const eventForm = document.getElementById('event-form');

	// Handle "Add New" button click
	$('.btn-success').on('click', function() {
		$('#eventModalLabel').text('<?php esc_html_e( 'Add New Event', 'decker' ); ?>');
		eventForm.reset();
	});

	// Handle edit button click
	$('.edit-event').on('click', function(e) {
		e.preventDefault();
		const row = $(this).closest('tr');
		const id = $(this).data('id');

		$('#eventModalLabel').text('<?php esc_html_e( 'Edit Event', 'decker' ); ?>');
		$('#event-id').val(id);
		$('#event-title').val(row.find('.event-title').text().trim());
		$('#event-description').val(row.find('.event-description').text().trim());
		$('#event-start').val(row.find('.event-start').text().trim().replace(' ', 'T'));
		$('#event-end').val(row.find('.event-end').text().trim().replace(' ', 'T'));
		$('#event-location').val(row.find('.event-location').text().trim());
		$('#event-url').val(row.find('.event-url').text().trim());
		$('#event-category').val(row.find('.event-category .badge').attr('class').split(' ')[1]);
		
		const assignedUsers = JSON.parse(row.find('.event-assigned-users').text().trim());
		$('#event-assigned-users').val(assignedUsers);

		eventModal.show();
	});

	// Handle delete event
	$('.delete-event').on('click', function(e) {
		e.preventDefault();
		const id = $(this).data('id');
		const title = $(this).closest('tr').find('.event-title').text().trim();

		if (confirm('<?php esc_html_e( 'Are you sure you want to delete this event?', 'decker' ); ?> "' + title + '"')) {
			$.ajax({
				url: '/wp-json/decker/v1/events/' + id,
				method: 'DELETE',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
				},
				success: function(response) {
					location.reload();
				},
				error: function(xhr, status, error) {
					alert('<?php esc_html_e( 'Error deleting event:', 'decker' ); ?> ' + error);
				}
			});
		}
	});

	// Handle form submission
	$('#event-form').on('submit', function(e) {
		e.preventDefault();
		const formData = new FormData(this);
		const id = formData.get('event_id');
		const method = id ? 'PUT' : 'POST';
		const url = '/wp-json/decker/v1/events' + (id ? '/' + id : '');

		$.ajax({
			url: url,
			method: method,
			data: formData,
			processData: false,
			contentType: false,
			beforeSend: function(xhr) {
				xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
			},
			success: function(response) {
				location.reload();
			},
			error: function(xhr, status, error) {
				alert('<?php esc_html_e( 'Error saving event:', 'decker' ); ?> ' + error);
			}
		});
	});
});
</script>

</html>
