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
									<button class="btn btn-success btn-sm ms-3" id="btn-new-event">
										<i class="ri-add-circle-fill"></i> <?php esc_html_e( 'Create New Event', 'decker' ); ?>
									</button>
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
												<div class="external-event bg-success-subtle text-success" data-class="bg-success"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'New Theme Release', 'decker' ); ?></div>
												<div class="external-event bg-info-subtle text-info" data-class="bg-info"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'My Event', 'decker' ); ?></div>
												<div class="external-event bg-warning-subtle text-warning" data-class="bg-warning"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Meet manager', 'decker' ); ?></div>
												<div class="external-event bg-danger-subtle text-danger" data-class="bg-danger"><i class="ri-focus-fill me-2 vertical-middle"></i><?php esc_html_e( 'Create New theme', 'decker' ); ?></div>
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
	  l("#event-title").val(this.$selectedEvent.title);
	  l("#event-description").val(this.$selectedEvent.extendedProps.description || '');
	  l("#event-location").val(this.$selectedEvent.extendedProps.location || '');
	  l("#event-url").val(this.$selectedEvent.url || '');
	  // Set dates and times
	  if (this.$selectedEvent.allDay) {
		l("#event-start").val('');
		l("#event-end").val('');
	  } else {
		l("#event-start").val(moment(this.$selectedEvent.start).format('YYYY-MM-DDTHH:mm'));
		l("#event-end").val(moment(this.$selectedEvent.end || this.$selectedEvent.start).format('YYYY-MM-DDTHH:mm'));
	  }
	  l("#event-category").val(this.$selectedEvent.classNames[0]);
	  if (this.$selectedEvent.extendedProps.assigned_users) {
		l("#event-assigned-users").val(this.$selectedEvent.extendedProps.assigned_users);
	  }
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
		  []),
		a = this;
	  (a.$calendarObj = new FullCalendar.Calendar(a.$calendar[0], {
		slotDuration: "00:15:00",
		slotMinTime: "08:00:00",
		slotMaxTime: "19:00:00",
		themeSystem: "bootstrap",
		bootstrapFontAwesome: !1,
		buttonText: {
		  today: "<?php esc_html_e( 'Today', 'decker' ); ?>",
		  month: "<?php esc_html_e( 'Month', 'decker' ); ?>",
		  week: "<?php esc_html_e( 'Week', 'decker' ); ?>",
		  day: "<?php esc_html_e( 'Day', 'decker' ); ?>",
		  list: "<?php esc_html_e( 'List', 'decker' ); ?>",
		  prev: "<?php esc_html_e( 'Prev', 'decker' ); ?>",
		  next: "<?php esc_html_e( 'Next', 'decker' ); ?>",
		},
		initialView: "dayGridMonth",
		handleWindowResize: !0,
		height: l(window).height() - 200,
		dayMaxEvents: 4, // Show only 4 events per day
		firstDay: 1, // 1 means Monday
		headerToolbar: {
		  left: "prev,next today",
		  center: "title",
		  right: "dayGridMonth,timeGridWeek,timeGridDay,listMonth",
		},
		events: {
			url: '/wp-json/decker/v1/calendar',
			method: 'GET',
			failure: function() {
				alert('<?php esc_html_e( 'There was an error while fetching events!', 'decker' ); ?>');
			}
		},
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
		// Handle start datetime changes and sync end date
		l("#event-start").on("change", function() {
			let startVal = l(this).val();
			if (startVal) {
				l("#event-end").val(startVal);
			}
		});

		a.$formEvent.on("submit", function (e) {
		  e.preventDefault();
		  var t,
			n = a.$formEvent[0];
		  n.checkValidity()
			? (a.$selectedEvent
				? (a.$selectedEvent.setProp("title", l("#event-title").val()),
				  a.$selectedEvent.setProp("classNames", [l("#event-category").val()]),
				  a.$selectedEvent.setExtendedProp("description", l("#event-description").val()),
				  a.$selectedEvent.setExtendedProp("location", l("#event-location").val()),
				  a.$selectedEvent.setProp("url", l("#event-url").val()),
				  a.$selectedEvent.setAllDay(!l("#event-start").val() || !l("#event-end").val()),
				  a.$selectedEvent.setStart(l("#event-start").val() || null),
				  a.$selectedEvent.setEnd(l("#event-end").val() || null),
				  a.$selectedEvent.setExtendedProp("assigned_users", l("#event-assigned-users").val()))
				: ((t = {
					title: l("#event-title").val(),
					start: l("#event-start").val() || null,
					end: l("#event-end").val() || null,
					allDay: !l("#event-start").val() || !l("#event-end").val(),
					className: l("#event-category").val(),
					description: l("#event-description").val(),
					location: l("#event-location").val(),
					url: l("#event-url").val(),
					extendedProps: {
						description: l("#event-description").val(),
						location: l("#event-location").val(),
						assigned_users: l("#event-assigned-users").val()
					}
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
