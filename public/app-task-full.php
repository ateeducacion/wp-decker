<?php
/**
 * File app-task-full
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
	<title><?php esc_html_e( 'Tasks Detail', 'decker' ); ?> | Decker</title>
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

                                    <!-- Page header -->
					<div class="row">
						<div class="col-12">
							<div class="page-title-box">
								<div class="page-title-right">
									<ol class="breadcrumb m-0">
										<li class="breadcrumb-item"><a href="javascript: void(0);">Decker</a></li>
										<li class="breadcrumb-item"><a href="javascript: void(0);"><?php esc_html_e( 'Tasks', 'decker' ); ?></a></li>
										<li class="breadcrumb-item active"><?php esc_html_e( 'Task Detail', 'decker' ); ?></li>
									</ol>
								</div>
							   <?php
									// Set default title to "New task".
									$task_title = __( 'New task', 'decker' );
								$valid_task                                = true;

								// TODO: Change to use Task class.
								$task_id = get_query_var( 'id' ) ? intval( get_query_var( 'id' ) ) : 0;
								if ( $task_id ) {
									$task = get_post( $task_id );
									if ( $task && 'decker_task' === $task->post_type ) {
										$task_title = $task->post_title;
									} else {
										$task_title      = __( 'Task not found', 'decker' );
										$valid_task = false;
									}
								}
								?>

								<h4 class="page-title">
									<?php echo esc_html( $task_title ); ?>
									<?php if ( $valid_task && $task_id ) : ?>
										<a href="#" class="copy-task-url" data-task-url="<?php echo esc_url( get_permalink( $task_id ) ); ?>" title="<?php esc_attr_e( 'Copy Task URL', 'decker' ); ?>">
											<i class="ri-clipboard-line"></i>
										</a>
									<?php endif; ?>
								</h4>

							</div>
						</div>
					</div>

					<!-- Detalles de la tarea -->
					<div class="row">
						<div class="col-xl-8 col-lg-7">
							
							<div id="task-card" class="card d-block">
								<div class="card-body">
								<?php
								if ( $valid_task ) {
									define( 'DECKER_TASK', true );
									include 'layouts/task-card.php';
								}
								?>
								</div>
							</div>

						</div>
					</div>
					<!-- end row -->

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
