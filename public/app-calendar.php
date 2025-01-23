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

	<!-- Fullcalendar css -->
	<!-- <link href="public/assets/css/fullcalendar.min.css" rel="stylesheet" type="text/css" /> -->


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
										<li class="breadcrumb-item"><a href="javascript: void(0);">Decker</a></li>
										<li class="breadcrumb-item active">Calendar</li>
									</ol>
								</div>
								<h4 class="page-title">Calendar</h4>
							</div>
						</div>
					</div>

					<div class="row">
						<div class="col-12">

							<div class="card">
								<div class="card-body">
									<div class="row">
										<div class="col-lg-3">
											<div class="d-grid">
												<button class="btn btn-lg fs-16 btn-danger" id="btn-new-event">
													<i class="ri-add-circle-fill"></i> Create New Event
												</button>
											</div>
											<div id="external-events" class="mt-3">
												<p class="text-muted">Drag and drop your event or click in the calendar</p>
												<div class="external-event bg-success-subtle text-success" data-class="bg-success"><i class="ri-focus-fill me-2 vertical-middle"></i>New Theme Release</div>
												<div class="external-event bg-info-subtle text-info" data-class="bg-info"><i class="ri-focus-fill me-2 vertical-middle"></i>My Event</div>
												<div class="external-event bg-warning-subtle text-warning" data-class="bg-warning"><i class="ri-focus-fill me-2 vertical-middle"></i>Meet manager</div>
												<div class="external-event bg-danger-subtle text-danger" data-class="bg-danger"><i class="ri-focus-fill me-2 vertical-middle"></i>Create New theme</div>
											</div>

											<div class="mt-5 d-none d-xl-block">
												<h5 class="text-center">How It Works ?</h5>

												<ul class="ps-3">
													<li class="text-muted mb-3">
														It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.
													</li>
													<li class="text-muted mb-3">
														Richard McClintock, a Latin professor at Hampden-Sydney College in Virginia, looked up one of the more obscure Latin words, consectetur, from a Lorem Ipsum passage.
													</li>
													<li class="text-muted mb-3">
														It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged.
													</li>
												</ul>
											</div>

										</div> <!-- end col-->

										<div class="col-lg-9">
											<div class="mt-4 mt-lg-0">
												<div id="calendar"></div>
											</div>
										</div> <!-- end col -->

									</div> <!-- end row -->
								</div> <!-- end card body-->
							</div> <!-- end card -->

							<!-- Add New Event MODAL -->
							<div class="modal fade" id="event-modal" tabindex="-1">
								<div class="modal-dialog">
									<div class="modal-content">
										<form class="needs-validation" name="event-form" id="form-event" novalidate>
											<div class="modal-header py-3 px-4 border-bottom-0">
												<h5 class="modal-title" id="modal-title">Event</h5>
												<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
											</div>
											<div class="modal-body px-4 pb-4 pt-0">
												<div class="row">
													<div class="col-12">
														<div class="mb-3">
															<label class="control-label form-label">Event Name</label>
															<input class="form-control" placeholder="Insert Event Name" type="text" name="title" id="event-title" required />
															<div class="invalid-feedback">Please provide a valid event name</div>
														</div>
													</div>
													<div class="col-12">
														<div class="mb-3">
															<label class="control-label form-label">Category</label>
															<select class="form-select" name="category" id="event-category" required>
																<option value="bg-danger" selected>Danger</option>
																<option value="bg-success">Success</option>
																<option value="bg-primary">Primary</option>
																<option value="bg-info">Info</option>
																<option value="bg-dark">Dark</option>
																<option value="bg-warning">Warning</option>
															</select>
															<div class="invalid-feedback">Please select a valid event category</div>
														</div>
													</div>
												</div>
												<div class="row">
													<div class="col-6">
														<button type="button" class="btn btn-danger" id="btn-delete-event">Delete</button>
													</div>
													<div class="col-6 text-end">
														<button type="button" class="btn btn-light me-1" data-bs-dismiss="modal">Close</button>
														<button type="submit" class="btn btn-success" id="btn-save-event">Save</button>
													</div>
												</div>
											</div>
										</form>
									</div> <!-- end modal-content-->
								</div> <!-- end modal dialog-->
							</div>
							<!-- end modal-->
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

	<!-- Fullcalendar js -->
	<!-- <script src="assets/vendor/fullcalendar/main.min.js"></script> -->

	<!-- Calendar App Demo js -->
	<!-- <script src="assets/js/pages/demo.calendar.js"></script> -->

	<!-- App js -->
	<!-- <script src="assets/js/app.min.js"></script> -->

	<script type="text/javascript">
		
!(function (l) {
  "use strict";
  function e() {
	(this.$body = l("body")),
	  (this.$modal = new bootstrap.Modal(
		document.getElementById("event-modal"),
		{ backdrop: "static" }
	  )),
	  (this.$calendar = l("#calendar")),
	  (this.$formEvent = l("#form-event")),
	  (this.$btnNewEvent = l("#btn-new-event")),
	  (this.$btnDeleteEvent = l("#btn-delete-event")),
	  (this.$btnSaveEvent = l("#btn-save-event")),
	  (this.$modalTitle = l("#modal-title")),
	  (this.$calendarObj = null),
	  (this.$selectedEvent = null),
	  (this.$newEventData = null);
  }
  (e.prototype.onEventClick = function (e) {
	this.$formEvent[0].reset(),
	  this.$formEvent.removeClass("was-validated"),
	  (this.$newEventData = null),
	  this.$btnDeleteEvent.show(),
	  this.$modalTitle.text("Edit Event"),
	  this.$modal.show(),
	  (this.$selectedEvent = e.event),
	  l("#event-title").val(this.$selectedEvent.title),
	  l("#event-category").val(this.$selectedEvent.classNames[0]);
  }),
	(e.prototype.onSelect = function (e) {
	  this.$formEvent[0].reset(),
		this.$formEvent.removeClass("was-validated"),
		(this.$selectedEvent = null),
		(this.$newEventData = e),
		this.$btnDeleteEvent.hide(),
		this.$modalTitle.text("Add New Event"),
		this.$modal.show(),
		this.$calendarObj.unselect();
	}),
	(e.prototype.init = function () {
	  var e = new Date(l.now()),
		e =
		  (new FullCalendar.Draggable(
			document.getElementById("external-events"),
			{
			  itemSelector: ".external-event",
			  eventData: function (e) {
				return { title: e.innerText, className: l(e).data("class") };
			  },
			}
		  ),
		  [
			{
			  title: "Meeting with Mr. Shreyu",
			  start: new Date(l.now() + 158e6),
			  end: new Date(l.now() + 338e6),
			  className: "bg-warning",
			},
			{
			  title: "Interview - Backend Engineer",
			  start: e,
			  end: e,
			  className: "bg-success",
			},
			{
			  title: "Phone Screen - Frontend Engineer",
			  start: new Date(l.now() + 168e6),
			  className: "bg-info",
			},
			{
			  title: "Buy Design Assets",
			  start: new Date(l.now() + 338e6),
			  end: new Date(l.now() + 4056e5),
			  className: "bg-primary",
			},
		  ]),
		a = this;
	  (a.$calendarObj = new FullCalendar.Calendar(a.$calendar[0], {
		slotDuration: "00:15:00",
		slotMinTime: "08:00:00",
		slotMaxTime: "19:00:00",
		themeSystem: "bootstrap",
		bootstrapFontAwesome: !1,
		buttonText: {
		  today: "Today",
		  month: "Month",
		  week: "Week",
		  day: "Day",
		  list: "List",
		  prev: "Prev",
		  next: "Next",
		},
		initialView: "dayGridMonth",
		handleWindowResize: !0,
		height: l(window).height() - 200,
		headerToolbar: {
		  left: "prev,next today",
		  center: "title",
		  right: "dayGridMonth,timeGridWeek,timeGridDay,listMonth",
		},
		initialEvents: e,
		editable: !0,
		droppable: !0,
		selectable: !0,
		dateClick: function (e) {
		  a.onSelect(e);
		},
		eventClick: function (e) {
		  a.onEventClick(e);
		},
	  })),
		a.$calendarObj.render(),
		a.$btnNewEvent.on("click", function (e) {
		  a.onSelect({ date: new Date(), allDay: !0 });
		}),
		a.$formEvent.on("submit", function (e) {
		  e.preventDefault();
		  var t,
			n = a.$formEvent[0];
		  n.checkValidity()
			? (a.$selectedEvent
				? (a.$selectedEvent.setProp("title", l("#event-title").val()),
				  a.$selectedEvent.setProp("classNames", [
					l("#event-category").val(),
				  ]))
				: ((t = {
					title: l("#event-title").val(),
					start: a.$newEventData.date,
					allDay: a.$newEventData.allDay,
					className: l("#event-category").val(),
				  }),
				  a.$calendarObj.addEvent(t)),
			  a.$modal.hide())
			: (e.stopPropagation(), n.classList.add("was-validated"));
		}),
		l(
		  a.$btnDeleteEvent.on("click", function (e) {
			a.$selectedEvent &&
			  (a.$selectedEvent.remove(),
			  (a.$selectedEvent = null),
			  a.$modal.hide());
		  })
		);
	}),
	(l.CalendarApp = new e()),
	(l.CalendarApp.Constructor = e);
})(window.jQuery),
  (function () {
	"use strict";
	window.jQuery.CalendarApp.init();
  })();

	</script>

</body>

</html>
