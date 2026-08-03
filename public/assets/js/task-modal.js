document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('task-modal');

    /**
     * Fetch the task card markup and (re)initialize it inside the modal body.
     *
     * @param {jQuery} modal  The jQuery-wrapped modal element.
     * @param {number} taskId The task ID to load (0 for a new task).
     */
    function loadTaskCard(modal, taskId) {
        modal.find('#task-modal-body').html('<p>' + jsdata_task.loadingMessage + '</p>');

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
                    const safeTaskId = String(parseInt(taskId, 10));
                    const permalink = deckerVars.taskPermalinkStructure.replace('%d', safeTaskId);

                    modalTitle.empty();
                    modalTitle.append(document.createTextNode(`Task #${safeTaskId} `));
                    const copyLink = jQuery('<a></a>')
                        .attr('href', '#')
                        .addClass('copy-task-url')
                        .attr('data-task-url', permalink)
                        .attr('title', deckerVars.strings.copy_task_url)
                        .append(jQuery('<i></i>').addClass('ri-clipboard-line'));
                    modalTitle.append(copyLink);
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
    }

    /**
     * Destroy per-card JS instances so the card can be reinitialized cleanly.
     *
     * @param {jQuery} modal The jQuery-wrapped modal element.
     */
    function cleanupTaskModal(modal) {
       // Remove the data-* attributes used to track initialization
        modal[0].removeAttribute('data-send-comments-initialized');
        modal[0].removeAttribute('data-task-page-initialized');

        // Destroy instances of Choices.js or Quill editor if necessary
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

        // Tear down the classic (TinyMCE) editor when it was used instead of Quill.
        if (typeof window.destroyTaskEditor === 'function') {
            window.destroyTaskEditor();
        }

        // Destroy collaborative editing session if active
        if (window.DeckerCollaboration) {
            window.DeckerCollaboration.destroyAll();
        }
    }

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
        var taskId = jQuery(e.relatedTarget).data('task-id'); // It can be 0 (new task).
        loadTaskCard(modal, taskId);
    });

// Clear data-* attributes when closing the modal to allow reinitialization
    jQuery('#task-modal').on('hidden.bs.modal', function () {
        var modal = jQuery(this);

        // Release the edit lock when the current user owns it.
        if (typeof window.deckerReleaseActiveTaskLock === 'function') {
            window.deckerReleaseActiveTaskLock();
        }

        cleanupTaskModal(modal);

        // If a "For today" change was made while the card was open, refresh the
        // parent view so the board/priority today indicators are up to date.
        if (window.deckerTodayChangedInSession) {
            window.deckerTodayChangedInSession = false;
            window.location.reload();
        }
    });

    /**
     * Reload the currently open task card in place, without closing the modal.
     *
     * Used after a lock takeover (to activate editing) or when the previous
     * editor reloads a card taken over by someone else. Falls back to false
     * when the card is not shown inside the modal (e.g. the full-page view).
     *
     * @param {number} taskId The task ID to reload.
     * @return {boolean} True when the in-place reload was triggered.
     */
    window.deckerReloadTaskCard = function (taskId) {
        if (!modalElement || !modalElement.classList.contains('show')) {
            return false;
        }
        const modal = jQuery(modalElement);
        cleanupTaskModal(modal);
        loadTaskCard(modal, taskId);
        return true;
    };
});
