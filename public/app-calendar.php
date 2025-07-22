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

										<div class="input-group mb-3">
											<!-- Icono de búsqueda integrado en el campo -->
											<span class="input-group-text bg-white border-end-0">
												<i class="ri-search-line"></i>
											</span>
											
											<!-- Campo de búsqueda con botón de borrar (X) dentro -->
											<input id="searchInput" type="search" class="form-control border-start-0" placeholder="<?php esc_attr_e( 'Search...', 'decker' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'decker' ); ?>">

											<!-- Select de tipo -->
											<select id="eventTypeFilter" class="form-select ms-2">
												<option value=""><?php esc_html_e( 'All Types', 'decker' ); ?></option>
												<option value="meeting"><?php esc_html_e( 'Meeting', 'decker' ); ?></option>
												<option value="absence"><?php esc_html_e( 'Absence', 'decker' ); ?></option>
												<option value="warning"><?php esc_html_e( 'Warning', 'decker' ); ?></option>
												<option value="alert"><?php esc_html_e( 'Alert', 'decker' ); ?></option>
												<option value="task"><?php esc_html_e( 'Task', 'decker' ); ?></option>
											</select>


											<!-- Select de usuarios -->
											<select id="boardUserFilter" class="form-select ms-2">
												<option value=""><?php esc_html_e( 'All Users', 'decker' ); ?></option>
												<?php
												$users = get_users();
												foreach ( $users as $user ) {
													echo '<option value="' . esc_attr( $user->nickname ) . '">' . esc_html( $user->display_name ) . '</option>';
												}
												?>
											</select>
										</div>

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
												<div class="external-event bg-info-subtle text-info" data-class="bg-info"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Absence', 'decker' ); ?></div>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
	const searchInput = document.getElementById('searchInput');
	const boardUserFilter = document.getElementById('boardUserFilter');
	const eventTypeFilter = document.getElementById('eventTypeFilter');

	function getEventTypeClass(type) {
		switch (type) {
			case 'meeting':
				return 'bg-success';
			case 'absence':
				return 'bg-info';
			case 'warning':
				return 'bg-warning';
			case 'alert':
				return 'bg-danger';
			case 'task':
				return 'bg-secondary'; // Assign this class to task-type events
			default:
				return '';
		}
	}

	function filterCalendarEvents() {
		const searchText = searchInput.value.toLowerCase().trim();
		const selectedUser = boardUserFilter.value.toLowerCase().trim();
		const selectedType = eventTypeFilter.value.toLowerCase().trim();
		// const typeClass = getEventTypeClass(selectedType);

		document.querySelectorAll('.fc-event').forEach(event => {
			const titleElement = event.querySelector('.fc-event-title');
			const title = titleElement ? titleElement.textContent.toLowerCase() : '';
			const classes = event.className;
			const nicknamesAttr = event.getAttribute('data-user-nicknames') || '';
			const nicknames = nicknamesAttr.split(',').map(e => e.trim().toLowerCase());

			const matchesSearch = !searchText || title.includes(searchText) || nicknames.some(nickname => nickname.includes(searchText));
			const matchesUser = !selectedUser || nicknames.includes(selectedUser);
			// const matchesType = !selectedType || classes.includes(typeClass);
			const matchesType = !selectedType || event.classList.contains('event-type-' + selectedType);


			event.style.display = (matchesSearch && matchesUser && matchesType) ? '' : 'none';
		});
	}

	searchInput.addEventListener('input', filterCalendarEvents);
	boardUserFilter.addEventListener('change', filterCalendarEvents);
	eventTypeFilter.addEventListener('change', filterCalendarEvents);
});
</script>



</body>

</html>
