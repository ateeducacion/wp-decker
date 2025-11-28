
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

    // Form fields collaboration binding state
    let formFieldsBinding = null;

    // Field mappings for collaboration
    const FIELD_MAPPINGS = [
        { id: 'task-title', key: 'title', type: 'text' },
        { id: 'task-max-priority', key: 'maxPriority', type: 'checkbox' },
        { id: 'task-today', key: 'today', type: 'checkbox' },
        { id: 'task-board', key: 'board', type: 'select' },
        { id: 'task-responsable', key: 'responsable', type: 'select' },
        { id: 'task-stack', key: 'stack', type: 'select' },
        { id: 'task-due-date', key: 'dueDate', type: 'date' },
    ];

    /**
     * Disable all form fields (used when task is archived)
     */
    function disableAllFormFields(context, message) {
        // Disable all input fields
        const form = context.querySelector('#task-form');
        if (!form) return;

        // Disable all inputs, selects, textareas, buttons
        form.querySelectorAll('input, select, textarea, button').forEach(el => {
            el.disabled = true;
        });

        // Disable Choices.js instances
        if (assigneesSelect) {
            assigneesSelect.disable();
        }
        if (labelsSelect) {
            labelsSelect.disable();
        }

        // Disable Quill editor
        if (quill) {
            quill.disable();
        }

        // Show archived overlay message
        let overlay = context.querySelector('.decker-archived-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'decker-archived-overlay';
            overlay.innerHTML = `
                <div class="decker-archived-message">
                    <i class="ri-archive-line"></i>
                    <span>${message || 'This task has been archived'}</span>
                </div>
            `;
            const modalBody = context.querySelector('#task-modal-body') || context.querySelector('#task-form');
            if (modalBody) {
                modalBody.style.position = 'relative';
                modalBody.appendChild(overlay);
            }
        }
    }

    /**
     * Simple debounce function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Animate a remote change on a field
     */
    function animateRemoteChange(element) {
        const wrapper = element.closest('.form-floating') || element.closest('.form-check') || element.parentElement;
        wrapper.classList.add('decker-remote-change');
        setTimeout(() => {
            wrapper.classList.remove('decker-remote-change');
        }, 400);
    }

    /**
     * Initialize form fields collaboration binding
     * @param {Object} session - The collaboration session object
     * @param {HTMLElement} context - The container element
     */
    function initFormFieldsCollaboration(session, context) {
        if (!session || !session.formFields) {
            console.warn('Decker: Cannot init form fields collaboration - no session or formFields');
            return null;
        }

        const formFields = session.formFields;
        const awareness = session.awareness;
        let isRemoteUpdate = false;

        // Get local Choices.js instances
        const choicesMappings = [
            { instance: assigneesSelect, key: 'assignees', id: 'task-assignees' },
            { instance: labelsSelect, key: 'labels', id: 'task-labels' },
        ];

        /**
         * Initialize form fields from Yjs or populate Yjs from local values.
         * Only the first user (no other peers) populates Yjs.
         * Subsequent users only receive and apply remote values.
         */
        function initializeFormFieldValues() {
            // Wait for WebRTC connection and sync
            setTimeout(() => {
                isRemoteUpdate = true;

                // Check if there are other peers connected (not just myself)
                const connectedPeers = awareness.getStates().size;
                const hasRemoteData = formFields.size > 0;
                const isFirstUser = connectedPeers <= 1 && !hasRemoteData;

                console.log('Decker: Connected peers:', connectedPeers, 'Has remote data:', hasRemoteData, 'Is first user:', isFirstUser);

                if (isFirstUser) {
                    // First user - populate Yjs with local values
                    console.log('Decker: First user, populating Yjs with local values');

                    FIELD_MAPPINGS.forEach(({ id, key, type }) => {
                        const el = context.querySelector(`#${id}`);
                        if (!el) return;

                        const localValue = type === 'checkbox' ? el.checked : el.value;
                        if (localValue !== undefined && localValue !== '') {
                            formFields.set(key, localValue);
                        }
                    });

                    choicesMappings.forEach(({ instance, key }) => {
                        if (!instance) return;

                        const localValues = instance.getValue(true);
                        if (localValues && localValues.length > 0) {
                            formFields.set(key, localValues);
                        }
                    });
                } else {
                    // Another user has data - only apply remote values, don't overwrite
                    console.log('Decker: Joining existing session, applying remote values only');

                    // Apply all remote values to local UI
                    FIELD_MAPPINGS.forEach(({ id, key, type }) => {
                        const el = context.querySelector(`#${id}`);
                        if (!el) return;

                        const remoteValue = formFields.get(key);
                        if (remoteValue !== undefined) {
                            if (type === 'checkbox') {
                                el.checked = remoteValue;
                                if (id === 'task-max-priority') {
                                    togglePriorityLabel(el);
                                }
                            } else {
                                el.value = remoteValue;
                            }
                        }
                    });

                    // Apply Choices.js values
                    choicesMappings.forEach(({ instance, key }) => {
                        if (!instance) return;

                        const remoteValue = formFields.get(key);
                        if (remoteValue !== undefined && Array.isArray(remoteValue)) {
                            instance.removeActiveItems();
                            remoteValue.forEach(v => instance.setChoiceByValue(v.toString()));
                        }
                    });

                    // Check if task is archived
                    if (formFields.get('archived') === true) {
                        disableAllFormFields(context, strings.task_is_archived || 'This task is archived');
                    }
                }

                isRemoteUpdate = false;
            }, 1000); // Increased timeout to allow WebRTC sync
        }

        /**
         * Bind local field changes to Yjs
         */
        function bindLocalChanges() {
            // Regular fields
            FIELD_MAPPINGS.forEach(({ id, key, type }) => {
                const el = context.querySelector(`#${id}`);
                if (!el) return;

                const sendChange = () => {
                    if (isRemoteUpdate) return;
                    const value = type === 'checkbox' ? el.checked : el.value;
                    formFields.set(key, value);
                };

                el.addEventListener('change', sendChange);

                // For text inputs, use debounced input event
                if (type === 'text') {
                    el.addEventListener('input', debounce(sendChange, 150));
                }

                // Focus tracking for awareness
                el.addEventListener('focus', () => session.setActiveField(id));
                el.addEventListener('blur', () => session.clearActiveField());
            });

            // Choices.js fields
            choicesMappings.forEach(({ instance, key, id }) => {
                if (!instance) return;

                instance.passedElement.element.addEventListener('change', () => {
                    if (isRemoteUpdate) return;
                    const values = instance.getValue(true);
                    formFields.set(key, values);
                });

                // Focus tracking for Choices.js dropdowns
                const choicesContainer = context.querySelector(`#${id}`)?.closest('.choices');
                if (choicesContainer) {
                    choicesContainer.addEventListener('focusin', () => session.setActiveField(id));
                    choicesContainer.addEventListener('focusout', () => session.clearActiveField());
                }
            });
        }

        /**
         * Observe Yjs changes and update local fields
         */
        function observeRemoteChanges() {
            formFields.observe((event) => {
                isRemoteUpdate = true;

                event.keysChanged.forEach(key => {
                    const value = formFields.get(key);

                    // Check for archived status change
                    if (key === 'archived' && value === true) {
                        disableAllFormFields(context, strings.task_archived_by_another_user || 'This task has been archived by another user');
                        return;
                    }

                    // Regular fields
                    const mapping = FIELD_MAPPINGS.find(m => m.key === key);
                    if (mapping) {
                        const el = context.querySelector(`#${mapping.id}`);
                        if (el) {
                            if (mapping.type === 'checkbox') {
                                el.checked = value;
                                // Trigger visual update for priority label
                                if (mapping.id === 'task-max-priority') {
                                    togglePriorityLabel(el);
                                }
                            } else {
                                el.value = value;
                            }
                            animateRemoteChange(el);
                        }
                    }

                    // Choices.js fields
                    const choicesMapping = choicesMappings.find(m => m.key === key);
                    if (choicesMapping && choicesMapping.instance) {
                        const instance = choicesMapping.instance;
                        instance.removeActiveItems();
                        (value || []).forEach(v => instance.setChoiceByValue(v.toString()));

                        // Animate the Choices container
                        const container = context.querySelector(`#${choicesMapping.id}`)?.closest('.choices');
                        if (container) {
                            container.classList.add('decker-remote-change');
                            setTimeout(() => container.classList.remove('decker-remote-change'), 400);
                        }
                    }
                });

                // Use setTimeout to ensure flag resets after any triggered events
                setTimeout(() => { isRemoteUpdate = false; }, 0);
            });
        }

        /**
         * Show indicators of who is editing which field
         */
        function observeFieldAwareness() {
            awareness.on('change', () => {
                // Clear existing indicators
                context.querySelectorAll('.decker-field-editor').forEach(el => el.remove());
                context.querySelectorAll('.decker-field-editing').forEach(el => {
                    el.classList.remove('decker-field-editing');
                    el.style.removeProperty('--editor-color');
                });
                context.querySelectorAll('.decker-field-editing-container').forEach(el => {
                    el.classList.remove('decker-field-editing-container');
                });

                // Create new indicators for remote users
                awareness.getStates().forEach((state, clientId) => {
                    if (clientId === awareness.clientID) return;
                    if (!state.activeField || !state.user) return;

                    const field = context.querySelector(`#${state.activeField}`);
                    if (!field) return;

                    // Add editing class to field
                    field.classList.add('decker-field-editing');
                    field.style.setProperty('--editor-color', state.user.color);

                    // Create indicator element
                    const wrapper = field.closest('.form-floating') || field.closest('.form-check') || field.closest('.choices') || field.parentElement;
                    wrapper.style.position = 'relative';

                    // Add special class for Choices.js containers to handle overflow
                    if (wrapper.classList.contains('choices')) {
                        wrapper.classList.add('decker-field-editing-container');
                    }

                    const indicator = document.createElement('div');
                    indicator.className = 'decker-field-editor';
                    indicator.style.backgroundColor = state.user.color;
                    indicator.textContent = state.user.name;
                    wrapper.appendChild(indicator);
                });
            });
        }

        // Initialize everything
        bindLocalChanges();
        observeRemoteChanges();
        observeFieldAwareness();
        initializeFormFieldValues();

        console.log('Decker: Form fields collaboration initialized');

        // Return binding object for cleanup and control
        return {
            /**
             * Set the task as archived (syncs to all peers)
             */
            setArchived(archived) {
                formFields.set('archived', archived);
                if (archived) {
                    disableAllFormFields(context, strings.task_archived || 'This task has been archived');
                }
            },

            destroy() {
                // Clear awareness
                session.clearActiveField();
                // Clear indicators
                context.querySelectorAll('.decker-field-editor').forEach(el => el.remove());
                context.querySelectorAll('.decker-field-editing').forEach(el => {
                    el.classList.remove('decker-field-editing');
                });
                context.querySelectorAll('.decker-field-editing-container').forEach(el => {
                    el.classList.remove('decker-field-editing-container');
                });
                console.log('Decker: Form fields collaboration destroyed');
            }
        };
    }

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
            // Check if collaborative editing is enabled to include cursors module
            const collabEnabled = window.deckerCollabConfig && window.deckerCollabConfig.enabled;

            // Register modules only once
            if (quill === null) {
                // Register the HTML Edit Button module
                Quill.register('modules/htmlEditButton', htmlEditButton);

                // Register quill-cursors module if available (for collaborative editing)
                if (typeof QuillCursors !== 'undefined') {
                    // Try default export first, then module itself
                    const CursorsModule = QuillCursors.default || QuillCursors;
                    Quill.register('modules/cursors', CursorsModule);
                    console.log('Decker: QuillCursors module registered');
                } else {
                    console.log('Decker: QuillCursors not available at registration time');
                }
            }

            // Build modules configuration
            const quillModules = {
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
            };

            // Add cursors module if collaborative editing is enabled and QuillCursors is available
            // Check again here in case it wasn't available during initial registration
            if (collabEnabled) {
                if (typeof QuillCursors !== 'undefined') {
                    // Register if not already registered
                    try {
                        const CursorsModule = QuillCursors.default || QuillCursors;
                        if (!Quill.imports['modules/cursors']) {
                            Quill.register('modules/cursors', CursorsModule);
                            console.log('Decker: QuillCursors module registered (late)');
                        }
                        quillModules.cursors = {
                            transformOnTextChange: true,
                            hideDelayMs: 3000,
                            hideSpeedMs: 400,
                            selectionChangeSource: null,
                        };
                        console.log('Decker: Cursors module enabled in config');
                    } catch (e) {
                        console.warn('Decker: Error registering cursors module:', e);
                    }
                } else {
                    console.warn('Decker: QuillCursors not available, remote cursors disabled');
                }
            }

            quill = new Quill(context.querySelector('#editor'), {
                theme: 'snow',
                readOnly: disabled,
                modules: quillModules
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

        // Initialize form fields collaboration after Choices.js is ready
        if (collabSession && !disabled) {
            // Destroy previous form fields binding if exists
            if (formFieldsBinding) {
                formFieldsBinding.destroy();
                formFieldsBinding = null;
            }
            formFieldsBinding = initFormFieldsCollaboration(collabSession, context);
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

    // Expose function to set task as archived (for collaborative sync)
    window.setTaskArchivedCollab = function(archived) {
        if (formFieldsBinding && typeof formFieldsBinding.setArchived === 'function') {
            formFieldsBinding.setArchived(archived);
        }
    };

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

