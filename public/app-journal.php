<?php
/**
 * File app-journal
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';

// Get filter parameters.
$board_slug = isset( $_GET['board'] ) ? sanitize_text_field( wp_unslash( $_GET['board'] ) ) : '';

// Get journal entries based on filters.
$args = array();
if ( ! empty( $board_slug ) ) {
	$board_term = get_term_by( 'slug', $board_slug, 'decker_board' );
	if ( $board_term ) {
		$args['tax_query'] = array(
			array(
				'taxonomy' => 'decker_board',
				'field'    => 'slug',
				'terms'    => $board_slug,
			),
		);
	}
}

$journal_data = Decker_Journal_CPT::get_journals( $args );

?>

<head>
	<title><?php esc_html_e( 'Board Journals', 'decker' ); ?> | Decker</title>
	<?php include 'layouts/title-meta.php'; ?>
	<?php include 'layouts/head-css.php'; ?>
</head>
<body <?php body_class(); ?>>

	<div class="wrapper">

		<?php include 'layouts/menu.php'; ?>

		<div class="content-page">
			<div class="content">
				<div class="container-fluid">

					<div class="row">
						<div class="col-xxl-12">
							<h4 class="page-title">
								<?php esc_html_e( 'Board Journals', 'decker' ); ?>
								<a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#journal-modal">
									<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Journal Entry', 'decker' ); ?>
								</a>
							</h4>
						</div>
					</div>

					<div class="row">
						<div class="col-12">
							<div class="card">
								<div class="card-body table-responsive">
									<table id="table-journals" class="table table-striped table-bordered dt-responsive nowrap w-100">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Date', 'decker' ); ?></th>
												<th><?php esc_html_e( 'Title', 'decker' ); ?></th>
												<th><?php esc_html_e( 'Topic', 'decker' ); ?></th>
												<th><?php esc_html_e( 'Users', 'decker' ); ?></th>
												<th><?php esc_html_e( 'Actions', 'decker' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $journal_data as $journal ) : ?>
												<tr>
													<td><?php echo esc_html( get_post_meta( $journal->ID, 'journal_date', true ) ); ?></td>
													<td><a href="<?php echo esc_url( get_permalink( $journal->ID ) ); ?>"><?php echo esc_html( $journal->post_title ); ?></a></td>
													<td><?php echo esc_html( get_post_meta( $journal->ID, 'topic', true ) ); ?></td>
													<td>
														<?php
														$users = get_post_meta( $journal->ID, 'assigned_users', true );
														if ( ! empty( $users ) ) {
															$user_names = array_map(
																function( $user_id ) {
																	$user = get_userdata( $user_id );
																	return $user ? $user->display_name : '';
																},
																$users
															);
															echo esc_html( implode( ', ', $user_names ) );
														}
														?>
													</td>
													<td>
														<button type="button" class="btn btn-sm btn-secondary me-2 view-journal-btn" data-journal-id="<?php echo esc_attr( $journal->ID ); ?>"><i class="ri-eye-line"></i></button>
														<button type="button" class="btn btn-sm btn-info me-2 edit-journal-btn" data-journal-id="<?php echo esc_attr( $journal->ID ); ?>"><i class="ri-pencil-line"></i></button>
														<button type="button" class="btn btn-sm btn-danger delete-journal-btn" data-journal-id="<?php echo esc_attr( $journal->ID ); ?>"><i class="ri-delete-bin-line"></i></button>
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

			<?php include 'layouts/footer.php'; ?>

		</div>

	</div>

	<?php include 'layouts/right-sidebar.php'; ?>
	<?php include 'layouts/journal-modal.php'; ?>
	<?php include 'layouts/journal-view-modal.php'; ?>
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
		jQuery(document).ready(function ($) {
			var journalTable = $('#table-journals').DataTable({
				language: {
					url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json',
				},
				pageLength: 50,
				responsive: true,
				order: [[0, 'desc']],
			});

			var journalModal = new bootstrap.Modal(document.getElementById('journal-modal'));
			var viewModal = new bootstrap.Modal(document.getElementById('journal-view-modal'));
			var quill = new Quill('#journal-description-editor', { theme: 'snow' });
			var userChoices = new Choices('#journal-users', { removeItemButton: true });
			var labelChoices = new Choices('#journal-labels', { removeItemButton: true });

			// Handle Add New button
			$('[data-bs-target="#journal-modal"]').on('click', function() {
				$('#journal-form')[0].reset();
				$('#journal-id').val('');
				quill.setText('');
				userChoices.clearInput();
				userChoices.setValue([]);
				labelChoices.clearInput();
				labelChoices.setValue([]);
				$('#journalModalLabel').text('<?php esc_html_e( "New Journal Entry", "decker" ); ?>');
			});

			// Handle Edit button
			$('#table-journals').on('click', '.edit-journal-btn', function() {
				var journalId = $(this).data('journal-id');
				$.ajax({
					url: wpApiSettings.root + 'wp/v2/decker-journals/' + journalId + '?_embed',
					method: 'GET',
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', wpApiSettings.nonce );
					},
					success: function(data) {
						$('#journal-id').val(data.id);
						$('#journal-title').val(data.title.rendered);
						$('#journal-date').val(data.meta.journal_date);
						$('#journal-topic').val(data.meta.topic);
						$('#journal-agreements').val(data.meta.agreements.join('\\n'));
						quill.root.innerHTML = data.content.rendered;

						userChoices.setValue(data.meta.assigned_users.map(String));
						labelChoices.setValue(data.decker_label.map(String));

						var boardId = data.decker_board.length > 0 ? data.decker_board[0] : '';
						$('#journal-board').val(boardId);

						$('#journalModalLabel').text('<?php esc_html_e( "Edit Journal Entry", "decker" ); ?>');
						journalModal.show();
					}
				});
			});

			// Handle View button
			$('#table-journals').on('click', '.view-journal-btn', function() {
				var journalId = $(this).data('journal-id');
				$.ajax({
					url: wpApiSettings.root + 'wp/v2/decker-journals/' + journalId,
					method: 'GET',
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', wpApiSettings.nonce );
					},
					success: function(data) {
						$('#journalViewModalLabel').html(data.title.rendered);
						$('#journal-view-content').html(data.content.rendered);
						viewModal.show();
					}
				});
			});

			// Handle Save button
			$('#save-journal-btn').on('click', function() {
				// Client-side validation
				if ( ! $('#journal-board').val() ) {
					alert('<?php esc_html_e( "Please select a board.", "decker" ); ?>');
					return;
				}
				if ( ! $('#journal-title').val() ) {
					alert('<?php esc_html_e( "Please enter a title.", "decker" ); ?>');
					return;
				}

				var journalId = $('#journal-id').val();
				var method = journalId ? 'POST' : 'POST';
				var url = journalId ? wpApiSettings.root + 'wp/v2/decker-journals/' + journalId : wpApiSettings.root + 'wp/v2/decker-journals';

				var data = {
					title: $('#journal-title').val(),
					content: quill.root.innerHTML,
					status: 'publish',
					decker_board: $('#journal-board').val(),
					decker_label: labelChoices.getValue(true),
					meta: {
						journal_date: $('#journal-date').val(),
						topic: $('#journal-topic').val(),
						assigned_users: userChoices.getValue(true),
						agreements: $('#journal-agreements').val().split('\\n'),
					}
				};

				$.ajax({
					url: url,
					method: method,
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', wpApiSettings.nonce );
					},
					data: JSON.stringify(data),
					contentType: 'application/json; charset=utf-8',
					success: function() {
						journalModal.hide();
						location.reload();
					},
					error: function(response) {
						alert(response.responseJSON.message);
					}
				});
			});

			// Handle Delete button
			$('#table-journals').on('click', '.delete-journal-btn', function() {
				if ( ! confirm('<?php esc_html_e( "Are you sure you want to delete this journal entry?", "decker" ); ?>') ) {
					return;
				}
				var journalId = $(this).data('journal-id');
				$.ajax({
					url: wpApiSettings.root + 'wp/v2/decker-journals/' + journalId,
					method: 'DELETE',
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', wpApiSettings.nonce );
					},
					success: function() {
						location.reload();
					},
					error: function(response) {
						alert(response.responseJSON.message);
					}
				});
			});
		});
	</script>

</body>
</html>
