/**
 * EventCalendar Script
 * 
 * This script initializes and manages a FullCalendar instance within a web application.
 * It provides functionality for event creation, dragging, dropping, and updating via a REST API for Plugin Decker.
 * @link       https://github.com/ateeducacion/wp-decker
 * @since      1.0.0
 *
 * @package    Decker
 * @subpackage Decker/public/assets/js
 * 
 * Features:
 * - Initializes FullCalendar with various views and configurations.
 * - Handles event creation through user interaction (clicking, dragging).
 * - Supports updating events and tasks via API calls.
 * - Implements a tooltip notification system on event updates.
 * - Enhances the event UI with dynamic styles and icons.
 * 
 * Dependencies:
 * - jQuery
 * - FullCalendar.js
 * - Bootstrap (for modals)
 * - WordPress REST API (for event persistence)
 * 
 */

/**
 * Displays a tooltip in the center of the screen with a given message.
 *
 * @param {string} message - The text to display inside the tooltip.
 * @param {number} [duration=2000] - The time in milliseconds before the tooltip disappears (default: 2000ms).
 */
function showTootip(message, duration = 2000){
       
    document.querySelectorAll(".fc-tooltip-calendar").forEach(el => el.remove());
    // Create a new tooltip
    let tooltip = document.createElement("div");
    tooltip.className = "fc-tooltip-calendar";
    tooltip.innerText = message;
        // Position tooltip near the event
    tooltip.style.top = `${window.innerHeight - 60}px`;  // Middle of screen (vertically)
    tooltip.style.left = `${window.innerWidth / 2}px`;   // Middle of screen (horizontally)
    tooltip.style.transform = "translate(-50%, -50%)";   // Perfect centering
    document.body.appendChild(tooltip);-
      // Remove tooltip after 2 seconds
    setTimeout(() => tooltip.remove(), duration);
}

(function($) {
    'use strict';

    function EventCalendar() {
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

    EventCalendar.prototype = {
        init: function() {
            this.initCalendar();
        },

        initCalendar: function() {
            if (this.$calendar.length) {
                // Initialize draggable events
                new FullCalendar.Draggable(document.getElementById('external-events'), {
                    itemSelector: '.external-event',
                    eventData: function(eventEl) {
                        return {
                            title: eventEl.innerText,
                            allDay: true,
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
                    events: function(fetchInfo, successCallback, failureCallback) {
                        // Hacer la petición manualmente con fetch
                        fetch(wpApiSettings.root + 'decker/v1/calendar', {
                            method: 'GET',
                            headers: {
                                'X-WP-Nonce': wpApiSettings.nonce // Usar el nonce de wpApiSettings
                            }
                        })
                        .then(response => {
                            if (!response.ok) throw new Error(response.statusText);
                            return response.json();
                        })
                        .then(data => successCallback(data))
                        .catch(error => {
                            console.error('Error fetching events:', error);
                            failureCallback(error);
                        });
                    },

                    editable: true,
                    droppable: true,
                    selectable: true,
                    dateClick: this.onSelect.bind(this),
                    eventClick: this.onEventClick.bind(this),
                    eventReceive: function(info) {
                        info.event.remove();
                    },
                    drop: function(info) {
                        console.log("drop:", info)
                        // Create event data.
                        var end_date =  info.allDay ? null : new Date( info.date.getTime() + 45*60000 );
                        const eventData = {
                            title: info.draggedEl.innerText,
                            status: 'publish',
                            meta: {
                                event_allday: info.allDay,
                                event_start: info.allDay ? info.date.toISOString().split('T')[0] : info.date.toISOString(),
                                event_end: info.allDay ? null : end_date.toISOString(),
                                event_category: info.draggedEl.dataset.class,
                                event_assigned_users: [deckerVars.current_user_id]
                            }
                        };
                        // Create event via REST API.
                        fetch(wpApiSettings.root + 'wp/v2/decker_event', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': wpApiSettings.nonce
                            },
                            body: JSON.stringify(eventData)
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(response.statusText);
                            }
                            return response.json();
                        })
                        .then((data) => {

                            const newEvent = {
                                id : 'event_' + data.id,
                                title: info.draggedEl.innerText, 
                                start: info.date, 
                                end: info.allDay ? null : end_date.toISOString(),
                                allDay: info.allDay,
                                extendedProps: {
                                    assigned_users: [deckerVars.current_user_id],
                                    description: "",
                                    location: "",
                                    type: "event"
                                },
                                className: info.draggedEl.dataset.class
                            };  
                            info.view.calendar.addEvent(newEvent);
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert(deckerVars.strings.error_saving_event);
                        });
                    },
                    eventDrop: function(info){
                        //Prevent null in event.end
                        console.log("event_drop:", info);
                        if ( null == info.event.end ){
                            var event_end =  info.event.allDay ? null : new Date( info.event.start.getTime() + 45*60000 );
                        } else {
                            var event_end = info.event.end;
                        }

                        var start_adjusted = info.event.start 
                            ? new Date(info.event.start.getTime() - (info.event.start.getTimezoneOffset() * 60000)) 
                            : null;

                        var end_adjusted = event_end 
                            ? new Date(event_end.getTime() - (event_end.getTimezoneOffset() * 60000)) 
                            : null;

                        if ( 'event' == info.event.extendedProps.type   ) {
                            var eventId=info.event.id.replace('event_', '');
                            var restroute=wpApiSettings.root + 'decker/v1/events/' + encodeURIComponent(eventId) + '/update';    
                            var restData = {
                                event_allday: info.event.allDay,
                                event_start: info.event.allDay ? start_adjusted.toISOString().split('T')[0] : start_adjusted.toISOString(),
                                event_end: info.event.allDay ? start_adjusted.toISOString().split('T')[0] : end_adjusted.toISOString()
                            };

                        } else if ('task' == info.event.extendedProps.type ) {
                            var eventId=info.event.id.replace('task_', '');
                            var restroute=wpApiSettings.root + 'decker/v1/tasks/' + encodeURIComponent(eventId) + '/update_due_date'; 
                            var restData = {
                                    duedate: start_adjusted.toISOString().split('T')[0]
                                };
                        }
                        
                        // Create event via REST API
                        fetch(restroute , {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': wpApiSettings.nonce
                            },
                            body: JSON.stringify(restData)
                        })
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(response.statusText);
                            }
                            return response.json();
                        })
                        .then(response => {
                            
                            if ( 'event' == info.event.extendedProps.type ) {
                                var message = `Event moved to ${info.event.start.toLocaleString().split(',')[0]}`;
                            } else if ( 'task' == info.event.extendedProps.type ){
                                var message = `Task moved to ${info.event.start.toLocaleString().split(',')[0]}`;
                            }
                            showTootip(message, 1500);

                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert(deckerVars.strings.error_saving_event);
                        });

                    },
                    eventDidMount: function(info) {
                        //console.log("event_mount:", info);
                        const titleEl = info.el.querySelector('.fc-event-title');
                        if (!titleEl) return;

                        if (info.event.extendedProps.type === 'task') {
                            info.el.style.backgroundColor = info.event.classNames[0];
                            info.event.setAllDay(true);
                            // Add task icon or fire emoji to title
                            if (info.event.extendedProps.max_priority) {
                                const emoji = document.createTextNode('🔥 ');
                                titleEl.insertBefore(emoji, titleEl.firstChild);
                            } else {
                                const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
                                svg.setAttribute("viewBox", "0 0 24 24");
                                svg.setAttribute("width", "14");
                                svg.setAttribute("height", "14");
                                svg.setAttribute("style", "margin-right: 4px; vertical-align: middle;");
                                svg.innerHTML = '<path fill="currentColor" d="M22,5.18L10.59,16.6l-4.24-4.24l1.41-1.41l2.83,2.83l10-10L22,5.18z M19.79,10.22C19.92,10.79,20,11.39,20,12 c0,4.42-3.58,8-8,8s-8-3.58-8-8c0-4.42,3.58-8,8-8c1.58,0,3.04,0.46,4.28,1.25l1.44-1.44C16.1,2.67,14.13,2,12,2C6.48,2,2,6.48,2,12 c0,5.52,4.48,10,10,10s10-4.48,10-10c0-1.19-0.22-2.33-0.6-3.39L19.79,10.22z"></path>';
                                titleEl.insertBefore(svg, titleEl.firstChild);
                            }
                        } else if (info.event.extendedProps.type === 'event') {
                            // Set background color based on category class
                            info.el.style.backgroundColor = info.event.classNames[0];
                            info.el.style.opacity = '0.7'; // Make it lighter

                            // For events, add assigned users before title
                            const users = info.event.extendedProps.assigned_users || [];

                            if (users.length > 0) {
                                const userNicknames = users.map(userId => {
                                    // Convertir a número si es necesario (depende de cómo vengan los IDs)
                                    const id = typeof userId === 'string' ? parseInt(userId, 10) : userId;
                                    // Buscar el usuario por ID en el array
                                    const user = deckerVars.users.find(u => u.id == id); // == para compatibilidad string/number
                                    return user?.nickname || '';
                                }).filter(Boolean);
                                
                                if (userNicknames.length > 0) {
                                    const prefix = document.createTextNode(userNicknames.join(', ') + ': ');
                                    titleEl.insertBefore(prefix, titleEl.firstChild);
                                }
                            }


                        }
                        titleEl.setAttribute('title', info.event.title);
                    }
                });

                this.$calendarObj.render();
            }
        },

        onEventClick: function(info) {
            if (info.event.extendedProps.type === 'task') {
                // For tasks, open the task modal
                const taskId = info.event.id.replace('task_', '');
                const taskButton = document.createElement('a');
                taskButton.setAttribute('data-bs-toggle', 'modal');
                taskButton.setAttribute('data-bs-target', '#task-modal');
                taskButton.setAttribute('data-task-id', taskId);
                taskButton.style.display = 'none';
                document.body.appendChild(taskButton);
                taskButton.click();
                document.body.removeChild(taskButton);
            } else {
                // For regular events, open the event modal with the event ID
                const eventId = info.event.id.replace('event_', '');
                const eventButton = document.createElement('a');
                eventButton.setAttribute('data-bs-toggle', 'modal');
                eventButton.setAttribute('data-bs-target', '#event-modal');
                eventButton.setAttribute('data-event-id', eventId);
                eventButton.style.display = 'none';
                document.body.appendChild(eventButton);
                eventButton.click();
                document.body.removeChild(eventButton);
            }
        },

        onSelect: function(info) {
            // For new events, open the event modal with the clicked date
            const eventId = 0;
            const eventButton = document.createElement('a');
            eventButton.setAttribute('data-bs-toggle', 'modal');
            eventButton.setAttribute('data-bs-target', '#event-modal');
            eventButton.setAttribute('data-event-id', eventId);
            eventButton.setAttribute('data-event-date', info.dateStr);
            eventButton.style.display = 'none';
            document.body.appendChild(eventButton);
            eventButton.click();

            // Guardamos el eventDate
            const eventDate = eventButton.dataset.eventDate;
            document.querySelector('#event-modal').dataset.tempEventDate = eventDate; // Guardar en el moda

            document.body.removeChild(eventButton);

            if (this.$calendarObj) {
                this.$calendarObj.unselect();
            }
        },

        handleDeleteEvent: function() {
            if (this.$selectedEvent) {
                this.$selectedEvent.remove();
                this.$selectedEvent = null;
                this.$modal.hide();
            }
        },

    };

    // Initialize when document is ready
    $(document).ready(function() {
        new EventCalendar();
    });

})(jQuery);
