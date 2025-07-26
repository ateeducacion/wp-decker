(function() {
    'use strict';
    console.log('loading event-card.js');

    // Global variables received from PHP
    const strings = deckerVars.strings;

    // Function to delete an event
    function deleteEvent(id, title) {
        if (confirm(strings.confirm_delete_event + ' "' + title + '"')) {
            fetch(wpApiSettings.root + wpApiSettings.versionString + 'decker_event/' + id, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce,
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert(strings.error_deleting_event);
            });
        }
    }

    window.deleteEvent = deleteEvent;


    /**
     * Inicializa las funcionalidades del formulario de eventos.
     * @param {Element} context - Contexto del DOM donde inicializar (por defecto es document).
     */
    function initializeEventCard(context = document) {

        const startInput = context.querySelector('#event-start');
        const endInput = context.querySelector('#event-end');

        // Sincroniza fechas: el "to" no puede ser menor que el "from"
        if (startInput && endInput) {
            startInput.addEventListener('change', () => {
                endInput.min = startInput.value;
            });

            endInput.addEventListener('change', () => {
                startInput.max = endInput.value;
            });
        }


        let assignedUsersChoices = null;

function toLocalDatetimeString(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toLocalDateString(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}



const allDaySwitch = context.querySelector('#event-allday');
// const startInput = context.querySelector('#event-start');
// const endInput = context.querySelector('#event-end');

if (allDaySwitch && startInput && endInput) {
    // Set the correct input types on load based on all-day checkbox
    if (allDaySwitch.checked) {
        startInput.value = startInput.value.split('T')[0] || // venimos de datetime‑local
                           new Date().toISOString().slice(0, 10);
        endInput.value   = endInput.value.split('T')[0]   ||
                           startInput.value;

        startInput.type = 'date';
        endInput.type = 'date';
    } else {
        startInput.type = 'datetime-local';
        endInput.type = 'datetime-local';
    }

    // Handle changes when the checkbox is toggled manually
    allDaySwitch.addEventListener('change', () => {
        const isAllDay = allDaySwitch.checked;

        const currentStart = new Date(startInput.value);
        const currentEnd = new Date(endInput.value || startInput.value);

        if (isAllDay) {
            startInput.type = 'date';
            endInput.type = 'date';

            startInput.value = toLocalDateString(currentStart);
            endInput.value = toLocalDateString(currentEnd);
        } else {
            startInput.type = 'datetime-local';
            endInput.type = 'datetime-local';


            const now = new Date();
            now.setSeconds(0, 0);

            // Redondear a la siguiente media hora
            const minutes = now.getMinutes();
            const remainder = minutes % 30;
            if (remainder !== 0) {
                now.setMinutes(minutes + (30 - remainder));
            }

            const start = new Date(now);


            currentStart.setHours(start);
            currentEnd.setHours(currentStart.getHours() + 1);

            startInput.value = toLocalDatetimeString(currentStart);
            endInput.value = toLocalDatetimeString(currentEnd);
        }
    });
}

        // Initialize Choices.js
        const assignedUsersSelect = context.querySelector('#event-assigned-users');
        if (assignedUsersSelect) {
            assignedUsersChoices = new Choices(assignedUsersSelect, { 
                removeItemButton: true,
                searchEnabled: true,
                placeholder: true,
                placeholderValue: strings.select_users_placeholder,
                searchPlaceholderValue: strings.search_users_placeholder
            });
        }


        // Select all users handler
        const selectAllLink = context.querySelector('#event-assigned-users-select-all');
        if (selectAllLink && assignedUsersChoices) {
            selectAllLink.addEventListener('click', function(e) {
                e.preventDefault();
                const allOptions = Array.from(assignedUsersSelect.options).map(opt => ({
                    value: opt.value,
                    label: opt.textContent.trim()
                }));
                assignedUsersChoices.removeActiveItems();
                assignedUsersChoices.setChoices(allOptions, 'value', 'label', true);
                allOptions.forEach(option => {
                    assignedUsersChoices.setChoiceByValue(option.value);
                });
            });
        }

        // Form submission handler
        const form = context.querySelector('#form-event');
        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!form.checkValidity()) {
                    e.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }

                const formData = new FormData(form);
                const eventId = formData.get('event_id');
                const isEdit = eventId > 0;

                // Build request parameters
                const url = wpApiSettings.root + wpApiSettings.versionString + 'decker_event' + (isEdit ? '/' + eventId : '');
                const method = isEdit ? 'PUT' : 'POST';

                const isAllDay = form.querySelector('#event-allday').checked;
                let startValue = formData.get('event_start');
                let endValue = formData.get('event_end');

                // if (isAllDay) {
                //     startValue = `${startValue}T00:00`;
                //     endValue = `${endValue}T23:59`;
                // }


                // Prepare event data
                const eventData = {
                    title: formData.get('event_title'),
                    status: 'publish',
                    meta: {
                        // Convert data to UTC
                        event_start: startValue,
                        event_end: endValue,
                        event_allday: formData.get('event_allday') === 'on',
                        event_category: formData.get('event_category'),
                        event_assigned_users: formData.getAll('event_assigned_users[]').map(Number),
                        event_location: formData.get('event_location'),
                        event_url: formData.get('event_url')
                    },
                    content: formData.get('event_description')
                };

                try {
                    const response = await fetch(url, {
                        method: method,
                        headers: {
                            'X-WP-Nonce': wpApiSettings.nonce,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(eventData)
                    });

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || strings.error_saving_event);
                    }

                    location.reload();
                } catch (error) {
                    console.error('Error:', error);
                    alert(error.message || strings.error_saving_event);
                }
            });
        }

        // Delete event handler
        context.querySelectorAll('.delete-event').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.dataset.id;
                const title = context.querySelector('#event-title').value || '';
                deleteEvent(id, title);
            });
        });
    }
    
    // Exportar funciones globalmente para que puedan ser llamadas desde HTML
    window.initializeEventCard = initializeEventCard;


    // Inicializar automáticamente si el contenido está cargado directamente en la página
    document.addEventListener('DOMContentLoaded', function() {
        // Verificar si existe el formulario de tarea directamente en la página
        const eventForm = document.querySelector('#event-form');
        if (eventForm && !eventForm.closest('.event-modal')) { // Asegurarse de que no está dentro de un modal
            initializeEventCard(document);
        }

    });

})();
