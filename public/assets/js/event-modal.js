document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('event-modal');

    jQuery('#event-modal').on('show.bs.modal', function (e) {
        var modal = jQuery(this);
        modal.find('#event-modal-body').html('<p>' + jsdata_event.loadingMessage + '</p>');

        var eventId = jQuery(e.relatedTarget).data('event-id'); // It can be 0 (new task).
        var url = jsdata_event.url;

        const params = new URLSearchParams(window.location.search);
        const boardSlug = params.get('slug'); // If present.

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
               // After loading the content, initialize the JS functions
                if (typeof window.initializeEventCard === 'function') {
                    window.initializeEventCard(modal[0]);
                }

            },
            error: function () {
                modal.find('#event-modal-body').html('<p>' + jsdata_event.errorMessage + '</p>');
            }
        });
    });

// Clear data-* attributes when closing the modal to allow reinitialization
    jQuery('#event-modal').on('hidden.bs.modal', function () {
        var modal = jQuery(this);
       // Remove the data-* attributes used to track initialization
        // modal[0].removeAttribute('data-event-page-initialized');

        // // Optional: destroy instances of Choices.js or Quill editor if necessary
        // // This depends on your implementation and memory usage
        // if (window.Choices) {
        //     const assigneesSelectInstance = window.assigneesSelect;
        //     if (assigneesSelectInstance) {
        //         assigneesSelectInstance.destroy();
        //         window.assigneesSelect = null;
        //     }
        // }


    });
});