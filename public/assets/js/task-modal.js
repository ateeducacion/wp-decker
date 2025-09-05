document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('task-modal');

    jQuery('#task-modal').on('hide.bs.modal', function (e) {
       // If we have unsaved changes, ask for confirmation
        if (window.deckerHasUnsavedChanges) {
            e.preventDefault(); // Prevents modal closing

            // Show the confirm dialog (with sweetalert)
            Swal.fire({
                title: deckerVars.strings.unsaved_changes_title,
                text: deckerVars.strings.unsaved_changes_text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: deckerVars.strings.close_anyway,
                cancelButtonText: deckerVars.strings.cancel
            }).then((result) => {
                if (result.isConfirmed) {
                    // The user has confirmed to close and discard
                    window.deckerHasUnsavedChanges = false;
                    // Force closing the modal
                    jQuery('#task-modal').modal('hide');
                }
            });
        }
    });

    jQuery('#task-modal').on('show.bs.modal', function (e) {
        var modal = jQuery(this);
        modal.find('#task-modal-body').html('<p>' + jsdata_task.loadingMessage + '</p>');

       var taskId = jQuery(e.relatedTarget).data('task-id'); // It can be 0 (new task).
        var url = jsdata_task.url;

        const params = new URLSearchParams(window.location.search);
        const boardSlug = params.get('slug'); // If present.

        jQuery.ajax({
            url: url,
            type: 'GET',
            data: { 
                id: taskId,
                slug: boardSlug,
                nonce: jsdata_task.nonce,
                nocache: new Date().getTime()
            },
            success: function (data) {
                modal.find('#task-modal-body').html(data);

                const modalTitle = modal.find('#NewTaskModalLabel');
                if (taskId && taskId != 0) {
                    const permalink = deckerVars.taskPermalinkStructure.replace('%d', taskId);                    
                    const newTitle = `Task #${taskId} <a href="#" class="copy-task-url" data-task-url="${permalink}" title="${deckerVars.strings.copy_task_url}"><i class="ri-clipboard-line"></i></a>`;
                    modalTitle.html(newTitle);
                } else {
                    modalTitle.text('Task');
                }

               // After loading the content, initialize the JS functions
                if (typeof window.initializeSendComments === 'function' && typeof window.initializeTaskPage === 'function') {
                    window.initializeSendComments(modal[0]);
                    window.initializeTaskPage(modal[0]);
                }

            },
            error: function () {
                modal.find('#task-modal-body').html('<p>' + jsdata_task.errorMessage + '</p>');
            }
        });
    });

// Clear data-* attributes when closing the modal to allow reinitialization
    jQuery('#task-modal').on('hidden.bs.modal', function () {
        var modal = jQuery(this);
       // Remove the data-* attributes used to track initialization
        modal[0].removeAttribute('data-send-comments-initialized');
        modal[0].removeAttribute('data-task-page-initialized');

        // Opcional: destruir instancias de Choices.js o Quill editor si es necesario
       // This depends on your implementation and memory usage
        if (window.Choices) {
            const assigneesSelectInstance = window.assigneesSelect;
            if (assigneesSelectInstance) {
                assigneesSelectInstance.destroy();
                window.assigneesSelect = null;
            }

            const labelsSelectInstance = window.labelsSelect;
            if (labelsSelectInstance) {
                labelsSelectInstance.destroy();
                window.labelsSelect = null;
            }
        }

        if (window.quill) {
           window.quill = null; // Assuming that Quill doesn't need explicit destruction
        }
    });
});
