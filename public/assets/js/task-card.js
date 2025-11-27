
(function() {
    console.log('loading task-card.js');

    // Global variables received from PHP
    const ajaxUrl = deckerVars.ajax_url;
    const restUrl = wpApiSettings.root + wpApiSettings.versionString;
    const homeUrl = deckerVars.home_url;
    const nonces = deckerVars.nonces;
    const strings = deckerVars.strings;
    const disabled = deckerVars.disabled;
    const userId = deckerVars.current_user_id;

    // Global variable to indicate if there are unsaved changes
    window.deckerHasUnsavedChanges = false;

    let quill = null;
    let collabSession = null;

    let assigneesSelect = null;
    let labelsSelect = null;

    // Start of comment part
    var replyToCommentId = null;

    // Function to initialize comment submission within the given context
    function initializeSendComments(context) {
        // Check if already initialized in this context
        if (context.dataset && context.dataset.sendCommentsInitialized === 'true') return;
        if (context.dataset) context.dataset.sendCommentsInitialized = 'true';

        const commentTextArea = context.querySelector('#comment-text');
        const submitButton = context.querySelector('#submit-comment');

        if (commentTextArea && submitButton) {
            // Enable/disable the submit button based on textarea content
            commentTextArea.addEventListener('input', function() {
                submitButton.disabled = this.value.trim() === '';
            });

            // Handle comment submission
            submitButton.addEventListener('click', function() {
                const commentText = commentTextArea.value.trim();
                const parentId = replyToCommentId || 0;

                if (commentText === '') {
                    return;
                }

                // Show loading state
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="ri-loader-2-line ri-spin me-1"></i> Sending...';

                const taskId = getTaskId();
                const payload = {
                    post: taskId,
                    content: commentText,
                    parent: parentId
                };

                fetch(`${restUrl}comments`, {
                    method: 'POST',
                    headers: {
                        'X-WP-Nonce': wpApiSettings.nonce,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload),
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    // Check for errors returned by the REST API
                    if (data.code) {
                        alert(data.message || 'Error adding comment.');
                    } else {
                        // Clear the form
                        commentTextArea.value = '';
                        if (replyToCommentId) {
                            context.querySelector('#reply-indicator').classList.add('d-none');
                            replyToCommentId = null;
                        }

                        // Add the new comment to the list
                        const commentsList = context.querySelector('#comments-list');
                        const newComment = document.createElement('div');
                        newComment.className = 'd-flex align-items-start mb-2';
                        if (parentId) {
                            newComment.style.marginLeft = '20px';
                        }

                        const avatarUrl = data.author_avatar_urls && data.author_avatar_urls['48'] ? data.author_avatar_urls['48'] : '';

                        newComment.innerHTML = `
                            <img class="me-2 rounded-circle" src="${avatarUrl}" alt="Avatar" height="32" />
                            <div class="w-100">
                                <h5 class="mt-0">${data.author_name} <small class="text-muted float-end">${data.date}</small></h5>
                                ${data.content.rendered}
                                <br />
                                <a href="javascript:void(0);" onclick="deleteComment(${data.id});" 
                                   class="text-muted d-inline-block mt-2 comment-delete" 
                                   data-comment-id="${data.id}">
                                    <i class="ri-delete-bin-line"></i> ${strings.delete}
                                </a>
                            </div>
                        `;

                        if (parentId) {
                            // Find the parent comment and insert after it
                            const parentComment = commentsList.querySelector(`[data-comment-id="${parentId}"]`)?.closest('.d-flex');
                            if (parentComment) {
                                parentComment.after(newComment);
                            } else {
                                commentsList.appendChild(newComment);
                            }
                        } else {
                            // Append to the main list
                            commentsList.appendChild(newComment);
                        }

                        // Update the comment count
                        const commentCount = context.querySelector('#comment-count');
                        if (commentCount) {
                            commentCount.textContent = parseInt(commentCount.textContent) + 1;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error adding comment. Please try again.');
                })
                .finally(() => {
                    // Reset the submit button state
                    submitButton.disabled = commentTextArea.value.trim() === '';
                    submitButton.innerHTML = '<i class="ri-chat-1-line me-1"></i> Comment';
                });
            });
        }
    }

    // Function to delete a comment
    function deleteComment(commentId) {
        if (!confirm(strings.confirm_delete_comment)) {
            return;
        }

        fetch(`${restUrl}comments/${commentId}`, {
            method: 'DELETE',
            headers: {
                'X-WP-Nonce': wpApiSettings.nonce,
                'Content-Type': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'trash' || data.deleted) {
                // Find and remove the comment element
                const commentElement = document.querySelector(`[data-comment-id="${commentId}"]`)?.closest('.d-flex');
                if (commentElement) {
                    commentElement.remove();

                    // Update the comment count
                    const commentCount = document.getElementById('comment-count');
                    if (commentCount) {
                        const currentCount = parseInt(commentCount.textContent);
                        if (!isNaN(currentCount)) {
                            commentCount.textContent = currentCount - 1;
                        }
                    }
                }
            } else {
                alert(strings.failed_delete_comment);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert(strings.error_deleting_comment);
        });
    }

    // Function to initialize the tasks page within the given context
    function initializeTaskPage(context) {
        new Tablesort(context.querySelector('#user-history-table'));

        // Check if the task_id is present in data-task-id
        const taskId = getTaskId();
        const taskElement = context.querySelector(`[data-task-id="${taskId}"]`);
        if (taskElement) {
            console.log('Task ID found in data-task-id:', taskElement.getAttribute('data-task-id'));
        } else {
            console.log('Task ID not found in data-task-id');
        }

        if (context.querySelector('#editor')) {
            if (quill === null) {
                // Register the HTML Edit Button module
                Quill.register('modules/htmlEditButton', htmlEditButton);

            }

            quill = new Quill(context.querySelector('#editor'), {
                theme: 'snow',
                readOnly: disabled,
                modules: {
                    toolbar: {
                        container: [
                            ['bold', 'italic', 'underline', 'strike'],
                            ['link', 'blockquote', 'code-block'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }, { 'list': 'check' }],
                            [{ 'indent': '-1' }, { 'indent': '+1' }],
                            ['clean'],
                            ['fullscreen'],
                        ],
                        handlers: {
                            'fullscreen': function() {
                                var editorContainer = context.querySelector('#editor-container');
                                if (!document.fullscreenElement) {
                                    editorContainer.requestFullscreen().catch(err => {
                                        alert('Error attempting to enable full-screen mode: ' + err.message);
                                    });
                                } else {
                                    document.exitFullscreen();
                                }
                            }
                        }
                    },
                    htmlEditButton: {
                        syntax: false,
                        buttonTitle: strings.show_html_source,
                        msg: strings.edit_html_content,
                        okText: strings.ok,
                        cancelText: strings.cancel,
                        closeOnClickOverlay: false,
                    },
                }
            });

            // Initialize collaborative editing if enabled and we have a task ID
            if (window.DeckerCollaboration && window.DeckerCollaboration.isEnabled() && !disabled) {
                const taskId = getTaskId();
                if (taskId && taskId !== '' && taskId !== '0') {
                    // Destroy any previous collaboration session
                    if (collabSession) {
                        collabSession.destroy();
                        collabSession = null;
                    }

                    // Get initial content before binding
                    const initialContent = quill.root.innerHTML;

                    // Initialize collaboration
                    collabSession = window.DeckerCollaboration.init(quill, taskId, context);

                    // If this is the first peer, set the initial content
                    if (collabSession && initialContent && initialContent !== '<p><br></p>') {
                        setTimeout(() => {
                            collabSession.setInitialContent(initialContent);
                        }, 500);
                    }

                    console.log('Decker: Collaborative editing initialized for task', taskId);
                }
            }

        }

        // Initialize Choices.js for assignee and label selectors
        if (context.querySelector('#task-assignees')) {
            assigneesSelect = new Choices(context.querySelector('#task-assignees'), { 
                removeItemButton: true,
                allowHTML: true,
                searchEnabled: true,
                shouldSort: true,
            });
        
            // Add change event for assignees
            assigneesSelect.passedElement.element.addEventListener('change', handleAssigneesChange);
        }

        if (context.querySelector('#task-labels')) {
            // labelsSelect = new Choices(context.querySelector('#task-labels'), { 
            //     removeItemButton: true, 
            //     allowHTML: true,
            //     searchEnabled: true,
            //     shouldSort: true,
            // });


            labelsSelect = new Choices(context.querySelector('#task-labels'), {
            removeItemButton: true,
            allowHTML: true,
            searchEnabled: true,
            shouldSort: true,
            callbackOnCreateTemplates: function (strToEl, escapeForTemplate, getClassNames) {
                const defaultTemplates = Choices.defaults.templates;
                


                return {
                    ...defaultTemplates,
                    item: (classNames, data) => {
                        // 1. Take the element generated by the default template
                        const el = defaultTemplates.item.call(this, classNames, data);

                        // 2. Apply background color according to the taxonomy
                        el.style.backgroundColor = data.customProperties?.color || 'blue';

                        // 3. Ensure that if removeItemButton=true, the element is set as "deletable"
                        if (this.config.removeItemButton) {
                            el.setAttribute('data-deletable', '');

                            // If the default template hasn't already generated the button, create it here
                            if (!el.querySelector('[data-button]')) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = this.config.classNames.button;
                                button.setAttribute('data-button', '');
                                button.setAttribute('aria-label', `Remove item: ${data.value}`);
                                button.innerHTML = '×';
                                el.appendChild(button);
                            }
                        }

                        return el;
                    }
                }

            }
        });


        }

        var uploadFileButton = context.querySelector('#upload-file');
        if (uploadFileButton) {
            uploadFileButton.addEventListener('click', function () {
                var fileInput = context.querySelector('#file-input');
                if (fileInput.files.length > 0) {
                    uploadAttachment(fileInput.files[0], context);
                } else {
                    alert(strings.please_select_file);
                }
            });
        }
        
        // Show/hide the "High" label for highest priority
        var taskMaxPriority = context.querySelector('#task-max-priority');
        if (taskMaxPriority) {
            taskMaxPriority.addEventListener('change', function () {
                togglePriorityLabel(this);
            });
        }

        // Aesthetic changes when selecting/deselecting the task checkbox
        const taskTodayCheckbox = context.querySelector('#task-today');
        if (taskTodayCheckbox) {
            taskTodayCheckbox.addEventListener('change', handleTaskTodayChange);
        }

        const saveButton = context.querySelector('#save-task');

        // Function to enable the save button when any field changes
        const enableSaveButton = function() {
            saveButton.disabled = false;
            // Mark that there are unsaved changes
            window.deckerHasUnsavedChanges = true;
        };

        const form = context.querySelector('#task-form');

        // Add event listeners to all form inputs
        const inputIds = ['task-title', 'task-due-date', 'task-board', 'task-stack', 'task-author-info', 'task-responsable', 'task-hidden', 'task-today', 'task-max-priority'];

        inputIds.forEach(function(id) {
            const element = context.querySelector(`#${id}`);
            if (element) {
                element.addEventListener('change', enableSaveButton);
                element.addEventListener('input', enableSaveButton);
            }
        });

        // Check the initial state of the highest priority checkbox and toggle the label
        var taskMaxPriorityCheck = context.querySelector('#task-max-priority');
        if (taskMaxPriorityCheck) {
            togglePriorityLabel(taskMaxPriorityCheck);
        }
        
        // For the Quill editor
        if (quill) {
            quill.on('text-change', function() {
                saveButton.disabled = false;
                window.deckerHasUnsavedChanges = true;
            });
        }

        // For the Choices.js selectors
        if (assigneesSelect) {
            assigneesSelect.passedElement.element.addEventListener('change', enableSaveButton);
        }
        if (labelsSelect) {
            labelsSelect.passedElement.element.addEventListener('change', enableSaveButton);
        }        

        document.querySelectorAll('.archive-task,.unarchive-task').forEach((element) => {

          element.removeEventListener('click', archiveTaskHandler);
          element.addEventListener('click', archiveTaskHandler);

        });

    }

    // Function to handle changes in the "task-today" checkbox
    function handleTaskTodayChange(event) {
        // If the user marks a task for today
        if (event.target.checked) {
            // Check if the user is already selected
            const selectedValues = assigneesSelect.getValue(true); // Get values as an array of numbers
            // And if it's not selected
            if (!selectedValues.includes(userId.toString())) {
                // Select it
                assigneesSelect.setChoiceByValue(userId.toString());
            }
        }
        // If it's unchecked, do nothing
    }

    // Function to handle changes in the assignees
    function handleAssigneesChange(event) {
        // If the user removes themselves from the task assignees
        const selectedValues = assigneesSelect.getValue(true); // Get values as an array of numbers
        if (!selectedValues.includes(userId.toString())) {
            const taskTodayCheckbox = document.querySelector('#task-today');
            // And has the task marked for today
            if (taskTodayCheckbox && taskTodayCheckbox.checked) {
                // Uncheck it
                taskTodayCheckbox.checked = false;
            }
        }
    }

    // Function to upload attachments via the native WP REST API
    function uploadAttachment(file, context) {
        var formData = new FormData();
        // Append the file with key "file" as expected by wp/v2/media
        formData.append('file', file);
        formData.append('post', getTaskId());
        // Optionally, you can add additional data (e.g. title) via formData.append()

        fetch(`${restUrl}media`, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': wpApiSettings.nonce,
                // Set Content-Disposition with the file name
                'Content-Disposition': 'attachment; filename="' + file.name + '"'
            },
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.id) {
                // Use data.source_url as the attachment URL and data.title.rendered for the title
                //const extension = data.mime_type.split('/')[1]; // "png"
                const extension = file.name.match(/\.([0-9a-z]+)(?:[\?#]|$)/i)[1];
                addAttachmentToList(data.id, data.source_url, data.title.rendered, extension, context);
                context.querySelector('#file-input').value = '';
            } else {
                alert(data.message || strings.error_uploading_attachment);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error uploading attachment.');
        });
    }

    // Function to add an attachment to the list
    function addAttachmentToList(attachmentId, attachmentUrl, attachmentTitle, attachmentExtension, context) {
        var attachmentsList = context.querySelector('#attachments-list');
        var li = document.createElement('li');
        var attachmentFilename = `${attachmentTitle}.${attachmentExtension}`; 

        li.className = 'list-group-item d-flex justify-content-between align-items-center';
        li.setAttribute('data-attachment-id', attachmentId);

        const link = document.createElement('a');
        link.href = attachmentUrl;
        link.target = '_blank';
        link.download = attachmentFilename;
        link.textContent = attachmentFilename;

        const icon = document.createElement('i');
        icon.className = 'bi bi-box-arrow-up-right ms-2';
        link.appendChild(icon);

        const buttonContainer = document.createElement('div');
        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'btn btn-sm btn-danger me-2 remove-attachment';
        if (disabled) {
            deleteButton.disabled = true;
        }
        deleteButton.textContent = strings.delete;
        buttonContainer.appendChild(deleteButton);

        li.appendChild(link);
        li.appendChild(buttonContainer);

        attachmentsList.appendChild(li);

        // Update the attachment count
        updateAttachmentCount(context, 1); // Increase by 1
    }

    // Event delegation to delete attachments
    document.addEventListener('click', function(event) {
        if (event.target && event.target.classList.contains('remove-attachment')) {
            var listItem = event.target.closest('li');
            var attachmentId = listItem.getAttribute('data-attachment-id');
            const modalElement = document.querySelector('.task-modal.show'); // Selects the open modal, or null if not in a modal
            if (modalElement) {
                deleteAttachment(attachmentId, listItem, modalElement);
            } else {
                deleteAttachment(attachmentId, listItem, document); // Assumes it's loaded directly on the page
            }
        }
    });

    // Function to delete an attachment using the REST API
    function deleteAttachment(attachmentId, listItem, context) {
        if (!confirm(strings.confirm_delete_attachment)) {
            return;
        }

        fetch(`${restUrl}media/${attachmentId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce // Required for authentication
            },
            body: JSON.stringify({ force: true }) // Force delete the attachment
        })
        .then(response => response.json())
        .then(data => {
            if (data.deleted) {
                listItem.remove();
                updateAttachmentCount(context, -1);
            } else {
                alert(data.message || 'Error deleting attachment.');
            }
        })
        .catch(error => {
            console.error('Request error:', error);
            alert('An error occurred while deleting the attachment.');
        });
    }

    // Function to update the attachment counter
    function updateAttachmentCount(context, change) {
        var attachmentCountElement = context.querySelector('#attachment-count');
        if (attachmentCountElement) {
            var currentCount = parseInt(attachmentCountElement.textContent, 10) || 0;
            var newCount = currentCount + change;
            attachmentCountElement.textContent = newCount;
        }
    }

    // Function to toggle the highest priority label
    function togglePriorityLabel(element) {
        var highLabel =document.querySelector('#high-label');
        if (highLabel) {
            if (element.checked) {
                highLabel.classList.remove('d-none');
            } else {
                highLabel.classList.add('d-none');
            }
        }
    }

    // Function to submit the form via AJAX
    function sendFormByAjax(event) {
        event.preventDefault();

        const form = document.getElementById('task-form'); // Fallback

        // const form = event.target; // Gets the form that triggered the event

        // Remove the 'was-validated' class beforehand
        form.classList.remove('was-validated');

        // Check the form's validity
        if (!form.checkValidity()) {
            event.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        // If the form is valid, proceed with the AJAX submission
        const selectedAssigneesValues = assigneesSelect.getValue().map(item => parseInt(item.value, 10));
        const selectedLabelsValues = labelsSelect.getValue().map(item => parseInt(item.value, 10));

        // Gather the form data
        const formData = {
            action: 'save_decker_task',
            nonce: nonces.save_decker_task_nonce,
            task_id: form.querySelector('input[name="task_id"]').value,
            title: form.querySelector('#task-title').value,
            due_date: form.querySelector('#task-due-date').value,
            board: form.querySelector('#task-board').value,
            stack: form.querySelector('#task-stack').value,
            author: form.querySelector('#task-author-info').value,
            responsable: form.querySelector('#task-responsable').value,
            hidden: form.querySelector('#task-hidden').checked ? 1 : 0,
            assignees: selectedAssigneesValues,
            labels: selectedLabelsValues,
            description: quill.root.innerHTML,
            max_priority: form.querySelector('#task-max-priority').checked ? 1 : 0,
            mark_for_today: form.querySelector('#task-today').checked ? 1 : 0,
        };

        // Disable save controls to prevent duplicate submissions
        const saveButton = document.getElementById('save-task');
        const saveDropdown = document.getElementById('save-task-dropdown');
        if (saveButton) {
            saveButton.disabled = true;
        }
        if (saveDropdown) {
            saveDropdown.disabled = true;
        }

        // Send the AJAX request
        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    window.deckerHasUnsavedChanges = false;
                    if (window.parent && window.parent.Swal) {
                        window.parent.Swal.fire({
                            icon: 'success',
                            title: strings.task_saved_success,
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500,
                            timerProgressBar: true
                        });
                    }
                    const modalElement = document.querySelector('.task-modal.show'); // Selects the open modal, or null if not in a modal
                    if (modalElement) {
                        var modalInstance = bootstrap.Modal.getInstance(modalElement);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                        
                        // Reload the page if the request was successful
                        location.reload();
                    } else {
                        // Redirect or update depending on the response
                        window.location.href = `${homeUrl}?decker_page=task&id=${response.data.task_id}`;
                    }

                } else {
                    alert(response.data.message || strings.error_saving_task);
                    if (saveButton) {
                        saveButton.disabled = false;
                    }
                    if (saveDropdown) {
                        saveDropdown.disabled = false;
                    }
                }
            } else {
                console.error(strings.server_response_error);
                alert(strings.an_error_occurred_saving_task);
                if (saveButton) {
                    saveButton.disabled = false;
                }
                if (saveDropdown) {
                    saveDropdown.disabled = false;
                }
            }
        };

        xhr.onerror = function() {
            console.error(strings.request_error);
            alert(strings.error_saving_task);
            if (saveButton) {
                saveButton.disabled = false;
            }
            if (saveDropdown) {
                saveDropdown.disabled = false;
            }
        };

        const encodedData = Object.keys(formData)
            .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(formData[key]))
            .join('&');

        xhr.send(encodedData);
    }

    // Get the task_id from the hidden input
    function getTaskId() {
        const taskIdInput = document.querySelector('input[name="task_id"]');
        if (taskIdInput) {
            return taskIdInput.value;
        } else {
            console.error('Task ID input not found');
            return null;
        }
    }

    // Export functions globally so they can be called from HTML
    window.initializeSendComments = initializeSendComments;
    window.initializeTaskPage = initializeTaskPage;
    window.sendFormByAjax = sendFormByAjax;
    window.deleteComment = deleteComment;
    window.togglePriorityLabel = togglePriorityLabel;

    // Automatically initialize if the content is loaded directly on the page
    document.addEventListener('DOMContentLoaded', function() {
        // Check if the task form exists directly on the page
        const taskForm = document.querySelector('#task-form');
        if (taskForm && !taskForm.closest('.task-modal')) { // Ensure that it is not inside a modal
            initializeTaskPage(document);
            initializeSendComments(document);
        }
    });

})();

