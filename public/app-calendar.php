<?php
/**
 * File app-calendar
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

include 'layouts/main.php';
?>
<head>
	<title><?php esc_html_e( 'Calendar', 'decker' ); ?> | Decker</title>
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
						<div class="col-12">
							<div class="page-title-box">
								<div class="page-title-right">
									<ol class="breadcrumb m-0">
										<li class="breadcrumb-item"><a href="javascript: void(0);"><?php esc_html_e( 'Decker', 'decker' ); ?></a></li>
										<li class="breadcrumb-item active"><?php esc_html_e( 'Calendar', 'decker' ); ?></li>
									</ol>
								</div>
								<h4 class="page-title">
									<?php esc_html_e( 'Calendar', 'decker' ); ?>

									<a href="#" class="btn btn-success btn-sm ms-3" data-bs-toggle="modal" data-bs-target="#event-modal" data-event-id="0">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Add New Event', 'decker' ); ?>
									</a>
								</h4>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-12">

							<div class="card">
								<div class="card-body">
									<div class="row">
										<div class="col-lg-2 d-none d-lg-block">
											<div id="external-events" class="mt-3">
												<p class="text-muted"><?php esc_html_e( 'Drag and drop your event or click in the calendar', 'decker' ); ?></p>
												<div class="external-event bg-success-subtle text-success" data-class="bg-success"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Meeting', 'decker' ); ?></div>
												<div class="external-event bg-info-subtle text-info" data-class="bg-info"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Holidays', 'decker' ); ?></div>
												<div class="external-event bg-warning-subtle text-warning" data-class="bg-warning"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Warning', 'decker' ); ?></div>
												<div class="external-event bg-danger-subtle text-danger" data-class="bg-danger"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Alert', 'decker' ); ?></div>
											</div>

										</div> <!-- end col-->

										<div class="col-lg-10">
											<div class="mt-4 mt-lg-0">
												<div id="calendar"></div>
											</div>
										</div> <!-- end col -->

									</div> <!-- end row -->
								</div> <!-- end card body-->
							</div> <!-- end card -->

							<?php include 'layouts/event-modal.php'; ?>
							<?php include 'layouts/task-modal.php'; ?>

						</div>
						<!-- end col-12 -->
					</div> <!-- end row -->

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

	<?php include 'layouts/footer-scripts.php'; ?>


</body>

</html>
