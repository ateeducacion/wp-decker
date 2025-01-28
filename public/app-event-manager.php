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
									<a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#event-modal" data-event-id="0">
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
	wp_localize_script(
		'jquery',
		'wpApiSettings',
		array(
			'root' => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
	?>

	<?php 
	// Include event modal template
	include plugin_dir_path( __FILE__ ) . 'layouts/event-modal.php';
	?>
</body>
</html>
