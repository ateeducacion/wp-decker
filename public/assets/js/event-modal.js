document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('event-modal');

    jQuery('#event-modal').on('show.bs.modal', function (e) {
        var modal = jQuery(this);
        modal.find('#event-modal-body').html('<p>' + jsdata_event.loadingMessage + '</p>');

        var eventId = jQuery(e.relatedTarget).data('event-id'); // Puede ser 0 (nueva tarea).
        var url = jsdata_event.url;

        const params = new URLSearchParams(window.location.search);
        const boardSlug = params.get('slug'); // Si existe.

        jQuery.ajax({
            url: url,
            type: 'GET',
            data: { 
                id: eventId,
                slug: boardSlug,
                nonce: jsdata_event.nonce,
                nocache: new Date().getTime()
            },
            success: function (data) {
                modal.find('#event-modal-body').html(data);

                // Después de cargar el contenido, inicializar las funciones JS
                if (typeof window.initializeEventCard === 'function') {
                    window.initializeEventCard(modal[0]);
                }

            },
            error: function () {
                modal.find('#event-modal-body').html('<p>' + jsdata_event.errorMessage + '</p>');
            }
        });
    });

    // Limpiar atributos data-* al cerrar el modal para permitir una nueva inicialización
    jQuery('#event-modal').on('hidden.bs.modal', function () {
        var modal = jQuery(this);
        // Remover los atributos data-* utilizados para rastrear inicialización
        // modal[0].removeAttribute('data-event-page-initialized');

        // // Opcional: destruir instancias de Choices.js o Quill editor si es necesario
        // // Esto depende de tu implementación y uso de memoria
        // if (window.Choices) {
        //     const assigneesSelectInstance = window.assigneesSelect;
        //     if (assigneesSelectInstance) {
        //         assigneesSelectInstance.destroy();
        //         window.assigneesSelect = null;
        //     }
        // }


    });
});