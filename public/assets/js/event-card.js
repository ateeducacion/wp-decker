(function() {
    'use strict';
    console.log('loading event-card.js');

    // Global variables received from PHP
    const restUrl = deckerVars.rest_url;
    const nonces = deckerVars.nonces;
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

    // Initialize event card functionality
    function initializeEventCard(context = document) {
        // Initialize Choices.js for assigned users
        if (context.querySelector('#event-assigned-users')) {
            new Choices('#event-assigned-users', { 
                removeItemButton: true,
                allowHTML: true,
                searchEnabled: true,
                shouldSort: true,
            });
        }

        // Handle form submission
        const form = context.querySelector('#form-event');
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!form.checkValidity()) {
                    e.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }

                const formData = new FormData(form);
                const id = formData.get('event_id');
                const url = wpApiSettings.root + wpApiSettings.versionString + 'decker_event' + (id > 0 ? '/' + id : '');

                // Build the event data object
                const eventData = {
                    title: formData.get('event_title'),
                    status: 'publish',
                    meta: {
                        event_start: formData.get('event_start_date') + 'T' + (formData.get('event_start_time') || '00:00'),
                        event_end: formData.get('event_end_date') + 'T' + (formData.get('event_end_time') || '00:00'),
                        event_category: formData.get('event_category'),
                        event_assigned_users: Array.from(formData.getAll('event_assigned_users[]')).map(Number)
                    }
                };

                // Add content if provided
                const description = formData.get('event_description');
                if (description) {
                    eventData.content = {
                        raw: description,
                        rendered: description
                    };
                }

                fetch(url, {
                    method: 'POST',
                    body: JSON.stringify(eventData),
                    headers: {
                        'X-WP-Nonce': wpApiSettings.nonce,
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    location.reload();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert(strings.error_saving_event);
                });
            });
        }

        // Handle date/time field interactions
        const startTimeInput = context.querySelector('#event-start-time');
        const endTimeInput = context.querySelector('#event-end-time');
        const endDateInput = context.querySelector('#event-end-date');
        const timeInputs = context.querySelector('#time-inputs');

        if (startTimeInput && endTimeInput && endDateInput && timeInputs) {
            [startTimeInput, endTimeInput].forEach(input => {
                input.addEventListener('input', function() {
                    const hasTime = startTimeInput.value || endTimeInput.value;
                    if (hasTime) {
                        endDateInput.value = '';
                    }
                    endDateInput.closest('.col-md-6').style.display = hasTime ? 'none' : '';
                });
            });

            endDateInput.addEventListener('input', function() {
                const hasEndDate = this.value;
                if (hasEndDate) {
                    startTimeInput.value = '';
                    endTimeInput.value = '';
                }
                timeInputs.style.display = hasEndDate ? 'none' : '';
            });
        }
        
        // Handle search functionality (only on main page, not in modal)
        if (!(context instanceof Element) || !context.closest('.modal')) {
            const searchInput = document.querySelector('#searchInput');
            if (searchInput) {
                searchInput.addEventListener('keyup', function() {
                    const searchText = this.value.toLowerCase();
                    document.querySelectorAll('#eventsTable tbody tr').forEach(row => {
                        const title = row.querySelector('.event-title').textContent.toLowerCase();
                        const start = row.querySelector('.event-start').textContent.toLowerCase();
                        const end = row.querySelector('.event-end').textContent.toLowerCase();
                        const category = row.querySelector('.event-category').textContent.toLowerCase();

                        if (title.includes(searchText) || start.includes(searchText) || 
                            end.includes(searchText) || category.includes(searchText)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        }
        
        // Handle select all users
        context.querySelectorAll('.select-all-users').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                context.querySelectorAll('#event_assigned_users option').forEach(option => {
                    option.selected = true;
                });
            });
        });

        // Handle delete event buttons
        context.querySelectorAll('.delete-event').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.dataset.id;
                const titleElement = this.closest('tr')?.querySelector('.event-title') || 
                                   document.querySelector('#event-title');
                const title = titleElement ? titleElement.value || titleElement.textContent.trim() : '';
                deleteEvent(id, title);
            });
        });
    }

    // Exportar funciones globalmente para que puedan ser llamadas desde HTML
    window.initializeEventCard = initializeEventCard;


    // Inicializar automáticamente si el contenido está cargado directamente en la página
    document.addEventListener('DOMContentLoaded', function() {
        // Verificar si existe el formulario de tarea directamente en la página
        const taskForm = document.querySelector('#event-form');
        if (taskForm && !taskForm.closest('.event-modal')) { // Asegurarse de que no está dentro de un modal
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
