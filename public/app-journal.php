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
													<td><!-- Action buttons placeholder --></td>
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
	<?php include 'layouts/footer-scripts.php'; ?>

	<script>
		jQuery(document).ready(function () {
			jQuery('#table-journals').DataTable({
				language: {
					url: 'https://cdn.datatables.net/plug-ins/1.11.5/i18n/es-ES.json',
				},
				pageLength: 50,
				responsive: true,
				order: [[0, 'desc']],
			});
		});
	</script>

</body>
</html>
