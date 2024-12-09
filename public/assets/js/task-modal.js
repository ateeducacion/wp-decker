document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('task-modal');

    jQuery('#task-modal').on('show.bs.modal', function (e) {
        var modal = jQuery(this);
        modal.find('.modal-body').html('<p>' + jsdata.loadingMessage + '</p>');

        var taskId = jQuery(e.relatedTarget).data('task-id'); // Can be 0 (new task).
        var url = jsdata.url;

        const params = new URLSearchParams(window.location.search);
        const boardSlug = params.get('slug'); // If exists.

        jQuery.ajax({
            url: url,
            type: 'GET',
            data: { 
                id: taskId,
                slug: boardSlug,
                nonce: jsdata.nonce,
                nocache: new Date().getTime()
            },
            success: function (data) {
                modal.find('.modal-body').html(data);

                const event = new CustomEvent('contentLoaded');
                modalElement.dispatchEvent(event);

            },
            error: function () {
                modal.find('.modal-body').html('<p>' + jsdata.errorMessage + '</p>');
            }
        });
    });
});
