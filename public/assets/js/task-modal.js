document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('task-modal');

    jQuery('#task-modal').on('show.bs.modal', function (e) {
        var modal = jQuery(this);
        modal.find('.modal-body').html('<p>' + jsdata.errorMessage + '</p>');

        var taskId = jQuery(e.relatedTarget).data('task-id');
        var url = jsdata.url;

        if (taskId) {
            url += '?id=' + taskId;
            url += '&nocache=' + new Date().getTime();
        } else {
            // Obtener los parámetros de,,,, la URL actual
            const params = new URLSearchParams(window.location.search);
            const boardSlug = params.get('slug');
            if (boardSlug) {
                url += '?slug=' + boardSlug; // Añadir el slug de la tarea a la URL si existe
            }

        }

        jQuery.ajax({
            url: url,
            type: 'GET',
            data: { nonce: jsdata.nonce },
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
