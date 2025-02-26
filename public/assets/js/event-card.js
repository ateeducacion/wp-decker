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

        flatpickr.localize(flatpickr.l10ns.es);
        flatpickr.l10ns.default.firstDayOfWeek = 1; // Monday

        // Get selected date from modal if available
        // Obtener el modal
        const modal = document.querySelector('#event-modal');
        // Recuperar la fecha guardada
        const selectedDate = modal?.dataset.tempEventDate;
        
        // Limpiar el dato temporal si es necesario
        if (modal && selectedDate) delete modal.dataset.tempEventDate;

        // Flatpickr configuration
        const flatpickrConfig = {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            time_24hr: true,
            minuteIncrement: 15,
            // defaultDate: selectedDate || undefined
        };

        const startPicker = flatpickr("#event-start", flatpickrConfig);
        const endPicker = flatpickr("#event-end", flatpickrConfig);
        let assignedUsersChoices = null;

        // If we have a selected date, update both pickers while keeping their current times
        if (selectedDate) {
            const startInput = context.querySelector("#event-start");
            const endInput = context.querySelector("#event-end");
            
            if (startInput && endInput) {
                const startTime = startInput.value.split(' ')[1] || '';
                const endTime = endInput.value.split(' ')[1] || '';
                
                startPicker.setDate(selectedDate + (startTime ? ' ' + startTime : ''));
                endPicker.setDate(selectedDate + (endTime ? ' ' + endTime : ''));
            }
        }

        // All Day Event handler
        const allDaySwitch = context.querySelector('#event-allday');
        if (allDaySwitch) {
            // Function to handle all-day mode changes
            const handleAllDayChange = function(isAllDay) {
                startPicker.set('enableTime', !isAllDay);
                startPicker.set('dateFormat', isAllDay ? "Y-m-d" : "Y-m-d H:i");
                endPicker.set('enableTime', !isAllDay);
                endPicker.set('dateFormat', isAllDay ? "Y-m-d" : "Y-m-d H:i");

                // Adjust existing dates
                if (isAllDay) {
                    if (startPicker.selectedDates.length > 0) {
                        const startDate = new Date(startPicker.selectedDates[0]);
                        startDate.setHours(0, 0, 0, 0);
                        startPicker.setDate(startDate);
                    }
                    if (endPicker.selectedDates.length > 0) {
                        const endDate = new Date(endPicker.selectedDates[0]);
                        endDate.setHours(23, 59, 0, 0);
                        endPicker.setDate(endDate);
                    }
                }
            };

            // Initial setup based on current state
            handleAllDayChange(allDaySwitch.checked);

            // Handle changes
            allDaySwitch.addEventListener('change', function() {
                handleAllDayChange(this.checked);
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





        // const startPicker = flatpickr("#event-start", flatpickrConfig);
        // const endPicker = flatpickr("#event-end", flatpickrConfig);

        // // Manejar el cambio del switch "All Day Event"
        // const allDaySwitch = context.querySelector('#event-allday');
        // if (allDaySwitch) {
        //     allDaySwitch.addEventListener('change', function() {
        //         const isAllDay = this.checked;

        //         // Actualizar la configuración de flatpickr según el estado del switch
        //         startPicker.set('enableTime', !isAllDay);
        //         startPicker.set('dateFormat', isAllDay ? "Y-m-d" : "Y-m-d H:i");

        //         endPicker.set('enableTime', !isAllDay);
        //         endPicker.set('dateFormat', isAllDay ? "Y-m-d" : "Y-m-d H:i");

        //         // Ajustar las fechas si es un evento de todo el día
        //         if (isAllDay) {
        //             if (startPicker.selectedDates[0]) {
        //                 const startDate = startPicker.selectedDates[0];
        //                 startDate.setHours(0, 0, 0, 0);
        //                 startPicker.setDate(startDate);
        //             }
        //             if (endPicker.selectedDates[0]) {
        //                 const endDate = endPicker.selectedDates[0];
        //                 endDate.setHours(23, 59, 0, 0);
        //                 endPicker.setDate(endDate);
        //             }
        //         }
        //     });
        // }

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

                // Prepare event data
                const eventData = {
                    title: formData.get('event_title'),
                    status: 'publish',
                    meta: {
                        event_start: formData.get('event_start'),
                        event_end: formData.get('event_end'),
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
        

    //     // Inicializar Choices.js para el campo de usuarios asignados
    //     const assignedUsersSelect = context.querySelector('#event-assigned-users');
    //     if (assignedUsersSelect) {
    //         new Choices(assignedUsersSelect, { 
    //             removeItemButton: true,
    //             searchEnabled: true,
    //             shouldSort: true,
    //             placeholder: true,
    //             placeholderValue: strings.select_users_placeholder,
    //             searchPlaceholderValue: strings.search_users_placeholder
    //         });
    //     }

    //     // Manejar el envío del formulario
    //     const form = context.querySelector('#form-event');
    //     if (form) {
    //         form.addEventListener('submit', async function(e) {
    //             e.preventDefault();

    //             // Validar el formulario
    //             if (!form.checkValidity()) {
    //                 e.stopPropagation();
    //                 form.classList.add('was-validated');
    //                 return;
    //             }

    //             form.classList.add('was-validated');

    //             // Recopilar los datos del formulario
    //             const formData = new FormData(form);
    //             const eventId = formData.get('event_id');
    //             const isEdit = eventId && parseInt(eventId) > 0;

    //             // Construir el objeto de datos del evento
    //             const eventData = {
    //                 title: formData.get('event_title'),
    //                 status: 'publish',
    //                 meta: {
    //                     event_start: formData.get('event_start'),
    //                     event_end: formData.get('event_end'),
    //                     event_allday: formData.get('event_allday') === 'on',
    //                     event_category: formData.get('event_category'),
    //                     event_assigned_users: formData.getAll('event_assigned_users[]').map(id => parseInt(id)),
    //                     event_location: formData.get('event_location'),
    //                     event_url: formData.get('event_url') || ''
    //                 },
    //                 content: formData.get('event_description') || ''
    //             };

    //             // Definir la URL según si es creación o actualización
    //             const url = wpApiSettings.root + wpApiSettings.versionString + 'decker_event' + (id > 0 ? '/' + id : '');

    //             try {
    //                 const response = await fetch(url, {
    //                     method: 'POST',
    //                     headers: {
    //                         'X-WP-Nonce': wpApiSettings.nonce,
    //                         'Content-Type': 'application/json'
    //                     },
    //                     body: JSON.stringify(eventData)
    //                 });

    //                 if (!response.ok) {
    //                     const errorData = await response.json();
    //                     throw new Error(errorData.message || strings.error_saving_event);
    //                 }

    //                 // Recargar la página para reflejar los cambios
    //                 location.reload();
    //             } catch (error) {
    //                 console.error('Error:', error);
    //                 alert(error.message || strings.error_saving_event);
    //             }
    //         });
    //     }
    
        
    //     // Handle select all users
    //     context.querySelectorAll('#event-assigned-users-select-all').forEach(button => {
    //         button.addEventListener('click', function(e) {
    //             e.preventDefault();
    //             context.querySelectorAll('#event_assigned_users option').forEach(option => {
    //                 option.selected = true;
    //             });
    //         });
    //     });

    //     // Handle delete event buttons
    //     context.querySelectorAll('.delete-event').forEach(button => {
    //         button.addEventListener('click', function(e) {
    //             e.preventDefault();
    //             const id = this.dataset.id;
    //             const titleElement = this.closest('tr')?.querySelector('.event-title') || 
    //                                document.querySelector('#event-title');
    //             const title = titleElement ? titleElement.value || titleElement.textContent.trim() : '';
    //             deleteEvent(id, title);
    //         });
    //     });
    // }

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


    // // Initialize when document is ready - only for standalone pages
    // document.addEventListener('DOMContentLoaded', function() {
    //     // Only initialize if we're not in a modal context
    //     const modalContext = document.querySelector('.modal.show');
    //     if (!modalContext) {
    //         console.log('Initializing event card in standalone context');
    //         initializeEventCard();
    //     } else {
    //         console.log('Skipping initialization in modal context');
    //     }
    // });

    // // Export for use in modal and make it handle initialization tracking
    // window.initializeEventCard = function(context = document) {
    //     // Check if already initialized in this context
    //     if (context instanceof Element && context.hasAttribute('data-event-initialized')) {
    //         console.log('Event card already initialized in this context');
    //         return;
    //     }

    //     // Initialize the event card
    //     initializeEventCard(context);

    //     // Mark as initialized
    //     if (context instanceof Element) {
    //         context.setAttribute('data-event-initialized', 'true');
    //     }
    // };

})();
