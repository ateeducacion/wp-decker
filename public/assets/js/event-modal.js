(function($) {
    'use strict';

    function EventModal() {
        this.$modal = new bootstrap.Modal(document.getElementById('event-modal'), { backdrop: 'static' });
        this.$calendar = $('#calendar');
        this.$formEvent = $('#form-event');
        this.$btnNewEvent = $('#btn-new-event');
        this.$btnDeleteEvent = $('#btn-delete-event');
        this.$btnSaveEvent = $('#btn-save-event');
        this.$modalTitle = $('#modal-title');
        this.$calendarObj = null;
        this.$selectedEvent = null;
        this.$newEventData = null;

        this.init();
    }

    EventModal.prototype = {
        init: function() {
            this.initCalendar();
            this.initEventHandlers();
        },

        initCalendar: function() {
            if (this.$calendar.length) {
                // Initialize draggable events
                new FullCalendar.Draggable(document.getElementById('external-events'), {
                    itemSelector: '.external-event',
                    eventData: function(eventEl) {
                        return {
                            title: eventEl.innerText,
                            className: $(eventEl).data('class')
                        };
                    }
                });

                this.$calendarObj = new FullCalendar.Calendar(this.$calendar[0], {
                    slotDuration: '00:15:00',
                    slotMinTime: '08:00:00',
                    slotMaxTime: '19:00:00',
                    themeSystem: 'bootstrap',
                    bootstrapFontAwesome: false,
                    buttonText: {
                        today: deckerVars.strings.today,
                        month: deckerVars.strings.month,
                        week: deckerVars.strings.week,
                        day: deckerVars.strings.day,
                        list: deckerVars.strings.list,
                        prev: '<',
                        next: '>',
                    },
                    initialView: 'dayGridMonth',
                    handleWindowResize: true,
                    height: $(window).height() - 200,
                    dayMaxEvents: 4,
                    firstDay: 1,
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth',
                    },
                    events: {
                        url: deckerVars.rest_url + 'calendar',
                        method: 'GET',
                        failure: function() {
                            alert(deckerVars.strings.error_fetching_events);
                        }
                    },
                    editable: true,
                    droppable: true,
                    selectable: true,
                    dateClick: this.onSelect.bind(this),
                    eventClick: this.onEventClick.bind(this),
                    eventDidMount: function(info) {
                        if (info.event.extendedProps.type === 'task') {
                            info.el.style.backgroundColor = info.event.classNames[0];
                            info.event.setAllDay(true);
                            // Add task icon to title
                            const titleEl = info.el.querySelector('.fc-event-title');
                            if (titleEl) {
                                const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
                                svg.setAttribute("viewBox", "0 0 24 24");
                                svg.setAttribute("width", "14");
                                svg.setAttribute("height", "14");
                                svg.setAttribute("style", "margin-right: 4px; vertical-align: middle;");
                                svg.innerHTML = '<path fill="currentColor" d="M22,5.18L10.59,16.6l-4.24-4.24l1.41-1.41l2.83,2.83l10-10L22,5.18z M19.79,10.22C19.92,10.79,20,11.39,20,12 c0,4.42-3.58,8-8,8s-8-3.58-8-8c0-4.42,3.58-8,8-8c1.58,0,3.04,0.46,4.28,1.25l1.44-1.44C16.1,2.67,14.13,2,12,2C6.48,2,2,6.48,2,12 c0,5.52,4.48,10,10,10s10-4.48,10-10c0-1.19-0.22-2.33-0.6-3.39L19.79,10.22z"></path>';
                                titleEl.insertBefore(svg, titleEl.firstChild);
                            }
                        }
                    }
                });

                this.$calendarObj.render();
            }
        },

        initEventHandlers: function() {
            const self = this;

            // New event button
            this.$btnNewEvent.on('click', function() {
                self.onSelect({ date: new Date(), allDay: true });
            });

            // Sync dates
            $('#event-start').on('change', function() {
                let startVal = $(this).val();
                if (startVal) {
                    $('#event-end').val(startVal);
                }
            });

            // Form submission
            this.$formEvent.on('submit', this.handleFormSubmit.bind(this));

            // Delete event
            this.$btnDeleteEvent.on('click', this.handleDeleteEvent.bind(this));

            // Edit event
            $('.edit-event').on('click', function(e) {
                e.preventDefault();
                const row = $(this).closest('tr');
                const id = $(this).data('id');
                self.openEditModal(row, id);
            });
        },

        onEventClick: function(e) {
            this.$formEvent[0].reset();
            this.$formEvent.removeClass('was-validated');
            this.$newEventData = null;
            this.$btnDeleteEvent.show();
            this.$modalTitle.text(deckerVars.strings.edit_event);
            this.$modal.show();
            this.$selectedEvent = e.event;

            $('#event-title').val(this.$selectedEvent.title);
            $('#event-description').val(this.$selectedEvent.extendedProps.description || '');
            $('#event-location').val(this.$selectedEvent.extendedProps.location || '');
            $('#event-url').val(this.$selectedEvent.url || '');

            if (this.$selectedEvent.allDay) {
                $('#event-start').val('');
                $('#event-end').val('');
            } else {
                $('#event-start').val(moment(this.$selectedEvent.start).format('YYYY-MM-DDTHH:mm'));
                $('#event-end').val(moment(this.$selectedEvent.end || this.$selectedEvent.start).format('YYYY-MM-DDTHH:mm'));
            }

            $('#event-category').val(this.$selectedEvent.classNames[0]);
            if (this.$selectedEvent.extendedProps.assigned_users) {
                $('#event-assigned-users').val(this.$selectedEvent.extendedProps.assigned_users);
            }
        },

        onSelect: function(e) {
            this.$formEvent[0].reset();
            this.$formEvent.removeClass('was-validated');
            this.$selectedEvent = null;
            this.$newEventData = e;
            this.$btnDeleteEvent.hide();
            this.$modalTitle.text(deckerVars.strings.add_new_event);
            this.$modal.show();
            if (this.$calendarObj) {
                this.$calendarObj.unselect();
            }
        },

        handleFormSubmit: function(e) {
            e.preventDefault();
            const self = this;
            const form = e.target;

            if (!form.checkValidity()) {
                e.stopPropagation();
                form.classList.add('was-validated');
                return;
            }

            const formData = new FormData(form);
            const id = formData.get('event_id');
            const method = id ? 'PUT' : 'POST';
            const url = deckerVars.rest_url + 'events' + (id ? '/' + id : '');

            $.ajax({
                url: url,
                method: method,
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', deckerVars.nonces.wp_rest);
                },
                success: function(response) {
                    location.reload();
                },
                error: function(xhr, status, error) {
                    alert(deckerVars.strings.error_saving_event + ' ' + error);
                }
            });
        },

        handleDeleteEvent: function() {
            if (this.$selectedEvent) {
                this.$selectedEvent.remove();
                this.$selectedEvent = null;
                this.$modal.hide();
            }
        },

        openEditModal: function(row, id) {
            $('#eventModalLabel').text(deckerVars.strings.edit_event);
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

            this.$modal.show();
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        new EventModal();
    });

})(jQuery);
