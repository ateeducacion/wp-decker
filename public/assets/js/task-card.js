
(function() {
    console.log('loading task-card.js');

    // Global variables received from PHP
    const ajaxUrl = deckerVars.ajax_url;
    const restUrl = wpApiSettings.root + wpApiSettings.versionString;
    const deckerRestUrl = wpApiSettings.root + 'decker/v1/';
    const homeUrl = deckerVars.home_url;
    const nonces = deckerVars.nonces;
    const strings = deckerVars.strings;
    let disabled = false;
    const userId = deckerVars.current_user_id;

    // Global variable to indicate if there are unsaved changes
    window.deckerHasUnsavedChanges = false;

    // Edit-lock state for the task currently open in edit mode.
    // { postId: number, owned: boolean } or null when no card is being edited.
    let activeLock = null;

    // Prevent a heartbeat response from invalidating the session while its save
    // is rotating the generation on the server.
    let taskSaveInFlight = false;

    let taskEditor = null;

    let assigneesSelect = null;
    let labelsSelect = null;

    /* ---------------------------------------------------------------------
     * Task edit locking (WordPress post lock compatible).
     * ------------------------------------------------------------------- */

    /**
     * Read the lock state serialized by the server into the task form.
     *
     * Accepts either a page/modal container that contains `#task-form`, or the
     * form element itself. `Element.querySelector()` only matches descendants,
     * so passing the form must not look for a nested `#task-form` (which would
     * miss `data-lock` and send an empty `lock_generation` on save).
     *
     * @param {HTMLElement} context - The container or the task form element.
     * @returns {Object|null} The lock info, or null when unavailable.
     */
    function readTaskLockState(context) {
        if (!context) {
            return null;
        }
        const form = (typeof context.matches === 'function' && context.matches('#task-form'))
            ? context
            : context.querySelector('#task-form');
        if (!form || !form.dataset.lock) {
            return null;
        }
        try {
            return JSON.parse(form.dataset.lock);
        } catch (e) {
            return null;
        }
    }

    /**
     * Whether a save AJAX body represents a lock conflict.
     *
     * The server returns HTTP 409 with success:false and code
     * decker_task_locked; callers must not gate this on 2xx statuses alone.
     *
     * @param {Object|null} response - Parsed JSON response body.
     * @returns {boolean} True when the response is a lock conflict.
     */
    function isTaskLockConflictResponse(response) {
        return !!(response && response.data && response.data.code === 'decker_task_locked');
    }

    /**
     * Perform a REST request against the Decker task lock endpoints.
     * @param {string} method - HTTP method.
     * @param {number|string} taskId - The task ID.
     * @param {string} path - Optional sub-path (e.g. '/takeover').
     * @param {Object} options - Extra fetch options.
     * @returns {Promise} The fetch promise.
     */
    function lockRequest(method, taskId, path, options) {
        const url = `${deckerRestUrl}tasks/${taskId}/lock${path || ''}`;
        return fetch(url, Object.assign({
            method: method,
            headers: { 'X-WP-Nonce': wpApiSettings.nonce },
            credentials: 'same-origin'
        }, options || {}));
    }

    /**
     * Disable every editing control in the card (fields, editor, save button).
     * @param {HTMLElement} context - The container element.
     */
    function disableEditingControls(context) {
        const form = context.querySelector('#task-form');
        if (form) {
            form.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = true;
            });
        }
        if (assigneesSelect) {
            assigneesSelect.disable();
        }
        if (labelsSelect) {
            labelsSelect.disable();
        }
        if (taskEditor && typeof taskEditor.setMode === 'function') {
            taskEditor.setMode('readonly');
        }
        // Editor chrome not covered by the form-field selector: the Quicktags
        // toolbar, media buttons and the Visual/Text switcher would otherwise
        // keep mutating the textarea programmatically.
        context.querySelectorAll(
            '.wp-editor-wrap button, .wp-editor-wrap input[type="button"], #add-attachment-link, #upload-file, .remove-attachment, #attachment-link-label'
        ).forEach((button) => {
            button.disabled = true;
        });
        const saveButton = context.querySelector('#save-task');
        if (saveButton) {
            saveButton.disabled = true;
        }
        const saveDropdown = context.querySelector('#save-task-dropdown');
        if (saveDropdown) {
            saveDropdown.disabled = true;
        }
    }

    /**
     * Wire the server-rendered "Take over editing" button.
     * @param {HTMLElement} context - The container element.
     */
    function wireTakeOverButton(context) {
        const button = context.querySelector('.decker-take-over-lock');
        if (!button) {
            return;
        }
        button.addEventListener('click', function () {
            const taskId = button.dataset.taskId || getTaskId();
            button.disabled = true;
            button.textContent = strings.taking_over;
            lockRequest('POST', taskId, '/takeover')
                .then(response => response.json().then(data => ({ ok: response.ok, data })))
                .then(({ ok, data }) => {
                    if (ok && data && data.owned_by_current_user) {
                        // Reopen the card so it renders in editable state.
                        reloadTaskCard(taskId);
                    } else {
                        button.disabled = false;
                        button.textContent = strings.take_over_editing;
                        alert(strings.lock_takeover_failed);
                    }
                })
                .catch(() => {
                    button.disabled = false;
                    button.textContent = strings.take_over_editing;
                    alert(strings.lock_takeover_failed);
                });
        });
    }

    /**
     * Re-render the card in its new lock state.
     *
     * Inside the modal the card is reloaded in place so it is not closed: after
     * a takeover it becomes editable, and a previous editor who lost the lock
     * sees the read-only state without the modal disappearing. On the full-page
     * view there is no modal to preserve, so the page is reloaded.
     *
     * @param {number|string} taskId - The task ID to reload.
     */
    function reloadTaskCard(taskId) {
        window.deckerHasUnsavedChanges = false;
        const id = taskId || getTaskId();
        if (typeof window.deckerReloadTaskCard === 'function' && window.deckerReloadTaskCard(id)) {
            return;
        }
        window.location.reload();
    }

    /**
     * Apply the read-only locked state to a card locked by another user.
     * @param {HTMLElement} context - The container element.
     */
    function applyLockedState(context) {
        disableEditingControls(context);
        wireTakeOverButton(context);
    }

    /**
     * Render a "lock lost" warning when another user takes over while editing.
     * @param {HTMLElement} context - The container element.
     * @param {Object} info - The lock info from the server.
     */
    function handleLockLost(context, info) {
        if (activeLock) {
            activeLock.owned = false;
        }
        disableEditingControls(context);

        const form = context.querySelector('#task-form');
        if (!form || form.querySelector('[data-decker-lock-lost]')) {
            return;
        }

        let message = strings.lock_lost_message;
        if (info && info.owner && info.owner.display_name) {
            message = strings.card_locked_by.replace('%s', info.owner.display_name)
                + ' ' + strings.lock_lost_message;
        }

        const banner = document.createElement('div');
        banner.className = 'alert alert-danger d-flex align-items-center justify-content-between';
        banner.setAttribute('role', 'alert');
        banner.setAttribute('data-decker-lock-lost', '');
        banner.setAttribute('aria-live', 'assertive');

        const text = document.createElement('span');
        text.className = 'd-flex align-items-center';
        text.innerHTML = '<i class="ri-lock-line me-2"></i>';
        const textSpan = document.createElement('span');
        textSpan.textContent = message;
        text.appendChild(textSpan);

        const reloadButton = document.createElement('button');
        reloadButton.type = 'button';
        reloadButton.className = 'btn btn-sm btn-danger ms-2';
        reloadButton.textContent = strings.reload_card;
        reloadButton.addEventListener('click', function () {
            reloadTaskCard(getTaskId());
        });

        banner.appendChild(text);
        banner.appendChild(reloadButton);
        form.insertBefore(banner, form.firstChild);
    }

    /**
     * Speed up or restore the WordPress heartbeat while a card is being edited,
     * so a takeover blocks the previous editor within a few seconds.
     * @param {boolean} fast - True to use the fast (~5s) interval.
     */
    function setLockHeartbeatSpeed(fast) {
        if (window.wp && window.wp.heartbeat && typeof window.wp.heartbeat.interval === 'function') {
            window.wp.heartbeat.interval(fast ? 'fast' : 'standard');
        }
    }

    // Expose so the lock activation in initializeTaskPage can call it.
    window.deckerSetLockHeartbeatSpeed = setLockHeartbeatSpeed;

    /**
     * Release the active lock when the current user owns it.
     */
    function releaseActiveLock() {
        setLockHeartbeatSpeed(false);
        if (!activeLock || !activeLock.postId || !activeLock.owned) {
            activeLock = null;
            return;
        }
        const taskId = activeLock.postId;
        // Send our session generation so the server releases only this session and
        // never a newer session of the same user that took over.
        const lock = readTaskLockState(document.getElementById('task-form'));
        const generation = lock && lock.generation ? lock.generation : '';
        const path = generation ? `?lock_generation=${encodeURIComponent(generation)}` : '';
        activeLock = null;
        // keepalive lets the request complete even while the modal/page unloads.
        lockRequest('DELETE', taskId, path, { keepalive: true }).catch(() => {});
    }

    /**
     * Clear in-memory lock tracking without contacting the server.
     *
     * Used when the lock was already released through another path (for example
     * an explicit REST DELETE in tests) so a later pagehide does not issue a
     * duplicate release request.
     */
    function clearActiveLockState() {
        setLockHeartbeatSpeed(false);
        activeLock = null;
    }

    // Expose the release helper so the modal close handler can call it.
    window.deckerReleaseActiveTaskLock = releaseActiveLock;
    window.deckerClearActiveTaskLockState = clearActiveLockState;

    /**
     * Whether a task-lock heartbeat response belongs to an obsolete form state.
     *
     * @param {Object} info - The lock information returned by the heartbeat.
     * @param {string} currentGeneration - Generation currently stored by the form.
     * @param {boolean} saveInFlight - Whether the form is awaiting a save response.
     * @returns {boolean} True when the heartbeat response must be ignored.
     */
    function shouldIgnoreTaskLockHeartbeat(info, currentGeneration, saveInFlight) {
        if (saveInFlight) {
            return true;
        }

        return !!(info && info.request_generation
            && info.request_generation !== currentGeneration);
    }

    // Release the lock when the tab is closed or the user navigates away
    // (covers the full-page view and closing without saving).
    window.addEventListener('pagehide', function () {
        releaseActiveLock();
    });

    // Keep the current user's lock alive through the WordPress heartbeat and
    // detect takeovers. Bound once at module load.
    if (window.jQuery) {
        jQuery(document).on('heartbeat-send.deckerLock', function (e, data) {
            if (!taskSaveInFlight && activeLock && activeLock.postId && activeLock.owned) {
                // Send the session generation so the server refreshes only this
                // exact session and never re-acquires on our behalf.
                const lock = readTaskLockState(document.getElementById('task-form'));
                data.decker_task_lock = {
                    post_id: activeLock.postId,
                    generation: lock && lock.generation ? lock.generation : '',
                };
            }
        });

        jQuery(document).on('heartbeat-tick.deckerLock', function (e, data) {
            if (!activeLock || !data || !data.decker_task_lock) {
                return;
            }
            const info = data.decker_task_lock;
            const currentLock = readTaskLockState(document.getElementById('task-form'));
            const currentGeneration = currentLock && currentLock.generation
                ? currentLock.generation
                : '';
            if (shouldIgnoreTaskLockHeartbeat(info, currentGeneration, taskSaveInFlight)) {
                return;
            }
            // Lost to another active editor, or our session was superseded (a
            // takeover, even one already released, or another session of the same
            // user): block this stale editor. We never adopt a server-sent token
            // into an open form — validity is proven only by the token we already
            // hold, so a stale session can never be silently re-authorized.
            if ((info.locked && !info.owned_by_current_user) || info.stale_session) {
                setLockHeartbeatSpeed(false);
                const modal = document.querySelector('.task-modal.show') || document;
                handleLockLost(modal, info);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * "For today" quick action (user-specific, avoids a full task save).
     * ------------------------------------------------------------------- */

    /**
     * Wire the quick "Add/Remove to today" button for a pristine existing task.
     * @param {HTMLElement} context - The container element.
     */
    function initializeTodayQuickAction(context) {
        const button = context.querySelector('#task-today-quick');
        if (!button) {
            return;
        }
        button.addEventListener('click', function () {
            // Only act while the form is pristine; once dirty the checkbox rules.
            if (window.deckerHasUnsavedChanges) {
                return;
            }
            const marked = button.dataset.marked !== '1';
            submitTodayQuickAction(context, marked);
        });
    }

    /**
     * Reveal the standard "For today" checkbox and hide the quick action.
     * @param {HTMLElement} context - The container element.
     */
    function showTodayCheckbox(context) {
        const button = context.querySelector('#task-today-quick');
        if (button) {
            button.classList.add('d-none');
        }
        const wrapper = context.querySelector('.decker-today-checkbox');
        if (wrapper) {
            wrapper.classList.remove('d-none');
        }
        const checkbox = context.querySelector('#task-today');
        if (checkbox) {
            checkbox.disabled = false;
            checkbox.removeAttribute('aria-hidden');
            checkbox.removeAttribute('tabindex');
        }
    }

    /**
     * Canonical transition from pristine to dirty edit mode. One-way and
     * idempotent for the current modal session.
     * @param {HTMLElement} context - The container element.
     */
    function enterDirtyEditMode(context) {
        if (window.deckerHasUnsavedChanges) {
            return;
        }
        window.deckerHasUnsavedChanges = true;
        const saveButton = context.querySelector('#save-task');
        if (saveButton) {
            saveButton.disabled = false;
        }
        showTodayCheckbox(context);
    }

    /**
     * Toggle the quick-action button loading state accessibly.
     * @param {HTMLElement} context - The container element.
     * @param {boolean} loading - Whether the request is in flight.
     */
    function setTodayQuickActionLoading(context, loading) {
        const button = context.querySelector('#task-today-quick');
        if (!button) {
            return;
        }
        button.disabled = loading;
        button.setAttribute('aria-busy', loading ? 'true' : 'false');
        const label = button.querySelector('.decker-today-quick-label');
        if (!label) {
            return;
        }
        if (loading) {
            button.dataset.idleLabel = label.textContent;
            label.textContent = button.dataset.marked === '1'
                ? strings.removing_from_today
                : strings.adding_to_today;
        } else if (button.dataset.idleLabel) {
            label.textContent = button.dataset.idleLabel;
        }
    }

    /**
     * Send the lightweight today request for the current user only.
     * @param {HTMLElement} context - The container element.
     * @param {boolean} marked - The desired today state.
     */
    function submitTodayQuickAction(context, marked) {
        const button = context.querySelector('#task-today-quick');
        const taskId = (button && button.dataset.taskId) || getTaskId();
        if (!taskId || taskId === '0') {
            return;
        }
        // Prevent duplicate submissions.
        if (button && button.getAttribute('aria-busy') === 'true') {
            return;
        }
        setTodayQuickActionLoading(context, true);

        // Send `marked` both in the query string and the JSON body. Some hosts
        // do not parse the body of a PUT request, so the query string keeps the
        // parameter reachable while the body stays spec-compliant.
        const url = `${deckerRestUrl}tasks/${taskId}/today?marked=${marked ? 'true' : 'false'}`;
        fetch(url, {
            method: 'PUT',
            headers: {
                'X-WP-Nonce': wpApiSettings.nonce,
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ marked: marked })
        })
            .then(response => response.json().then(data => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (ok && data && data.success) {
                    onTodayQuickActionSuccess(context, data);
                } else {
                    setTodayQuickActionLoading(context, false);
                    const message = (data && data.message) || strings.today_update_failed;
                    notifyTodayResult(message, false);
                }
            })
            .catch(() => {
                setTodayQuickActionLoading(context, false);
                notifyTodayResult(strings.today_update_failed, false);
            });
    }

    /**
     * Toggle the quick-action button between its "add" and "remove" states.
     * @param {HTMLElement} context - The container element.
     * @param {boolean} marked - The new marked state.
     */
    function updateTodayQuickButton(context, marked) {
        const button = context.querySelector('#task-today-quick');
        if (!button) {
            return;
        }
        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
        button.dataset.marked = marked ? '1' : '0';
        delete button.dataset.idleLabel;
        button.classList.remove('btn-success', 'btn-outline-secondary');
        button.classList.add(marked ? 'btn-outline-secondary' : 'btn-success');
        const label = button.querySelector('.decker-today-quick-label');
        if (label) {
            label.textContent = marked ? strings.remove_from_today : strings.add_to_today;
        }
    }

    /**
     * Handle a successful quick action: toggle the control in place and keep the
     * card open so the user can mark/unmark freely. The parent view is refreshed
     * on modal close (or on the next navigation) rather than reloading here.
     * @param {HTMLElement} context - The container element.
     * @param {Object} data - The server response.
     */
    function onTodayQuickActionSuccess(context, data) {
        const checkbox = context.querySelector('#task-today');
        if (checkbox) {
            checkbox.checked = !!data.marked;
        }
        updateTodayQuickButton(context, !!data.marked);

        // The quick action never dirties the form.
        window.deckerHasUnsavedChanges = false;

        // Remember a change was made so the parent view can refresh on close.
        window.deckerTodayChangedInSession = true;

        notifyTodayResult(data.message, true);

        // Extension point for targeted parent-view updates.
        document.dispatchEvent(new CustomEvent('decker:task-today-changed', {
            detail: {
                taskId: data.task_id,
                marked: data.marked,
                userId: data.user_id,
                date: data.date
            }
        }));
    }

    /**
     * Show an accessible toast for the quick-action result.
     * @param {string} message - The message to show.
     * @param {boolean} success - Whether the action succeeded.
     */
    function notifyTodayResult(message, success) {
        const swal = window.Swal;
        if (swal) {
            swal.fire({
                icon: success ? 'success' : 'error',
                title: message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: success ? 1500 : 3000,
                timerProgressBar: true
            });
        } else if (!success) {
            alert(message);
        }
    }

    /**
     * Simple debounce function
     */
    function debounce(func, wait) {
        let timeout = null;
        const executedFunction = function(...args) {
            const later = () => {
                clearTimeout(timeout);
                timeout = null;
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };

        executedFunction.cancel = function() {
            clearTimeout(timeout);
            timeout = null;
        };

        return executedFunction;
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

    /**
     * Initialize the WordPress classic (TinyMCE) editor for the task
     * description.
     *
     * @param {HTMLElement} context The container element holding the task form.
     * @return {Promise} Resolves once the editor is ready.
     */
    function initializeTaskEditor(context, readOnly) {
        const textarea = context.querySelector('#task-description');

        if (!textarea || typeof wp === 'undefined' || !wp.editor) {
            return Promise.resolve();
        }

        if (taskEditor && taskEditor.initialized) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            const session = { cancelled: false, ready: false, resolve };
            textarea.deckerEditorSession = session;
            // Quicktags buttons modify the textarea programmatically and fire
            // 'change' even when it is read-only or disabled, so dirty tracking
            // must re-check the field state at event time (covers cards that
            // are read-only from the start and locks applied while editing).
            const markDirty = () => {
                if ((session.cancelled && !session.failed) || !textarea.isConnected || textarea.disabled || textarea.readOnly) {
                    return;
                }
                enterDirtyEditMode(context);
            };
            const debouncedMarkDirty = debounce(markDirty, 150);

            // Quicktags (Text tab) edits bypass TinyMCE events entirely; track
            // the textarea directly so they still mark the card dirty.
            textarea.addEventListener('input', debouncedMarkDirty);
            textarea.addEventListener('change', debouncedMarkDirty);
            if (readOnly) {
                textarea.readOnly = true;
            }

            const config = {
                tinymce: {
                    // Descriptions already store HTML; autop loses attributed empty paragraphs.
                    wpautop: false,
                    container: 'description-tab',
                    // Locked or archived cards must not be editable.
                    readonly: readOnly ? true : false,
                    toolbar1: 'formatselect bold italic link bullist numlist decker_checklist decker_check_item blockquote alignleft aligncenter alignright wp_adv fullscreen',
                    toolbar2: 'strikethrough hr forecolor pastetext removeformat charmap outdent indent undo redo wp_help',
                    menubar: false,
                    // Keep the native padding rule too: empty items must survive serialization.
                    extended_valid_elements: '#li[data-list|value|class|style|id|dir|title|lang]',
                    // Draw the checkboxes for checklist items; state handling lives
                    // in tinymce-checklist.js (DeckerChecklist).
                    content_style: 'li[data-list=checked],li[data-list=unchecked]{list-style:none;position:relative;padding-left:1.7em;}' +
                        'li[data-list=checked]::before,li[data-list=unchecked]::before{content:"\\2610";position:absolute;left:0;top:0;width:1.4em;cursor:pointer;}' +
                        'li[data-list="checked"]::before{content:"\\2611";}',
                    setup: function(editor) {
                        if (session.cancelled || !textarea.isConnected) {
                            editor.on('init', () => { editor.remove(); resolve(); });
                            return;
                        }
                        taskEditor = editor;
                        if (window.DeckerChecklist) {
                            window.DeckerChecklist.attach(editor);
                        }
                        editor.on('init', function() {
                            // Use the local reference: taskEditor may already be
                            // null if the modal was closed before init fired.
                            if (session.cancelled || !textarea.isConnected || textarea.deckerEditorSession !== session) {
                                if (typeof editor.remove === 'function') editor.remove();
                                resolve();
                                return;
                            }
                            editor.initialized = true;
                            session.ready = true;
                            if (textarea.disabled || textarea.readOnly) {
                                if (typeof editor.setMode === 'function') editor.setMode('readonly');
                                disableEditingControls(context);
                            }
                            resolve();
                        });
                        // Only genuine user edits switch the card into dirty mode;
                        // the initial content load (SetContent during init) is
                        // intentionally excluded so opening a card stays pristine.
                        editor.on('input keyup change ExecCommand', function() {
                            debouncedMarkDirty();
                        });
                    }
                },
                // No Text tab or media buttons on read-only cards: Quicktags
                // edits the textarea programmatically, bypassing readOnly.
                quicktags: !readOnly,
                mediaButtons: !readOnly
            };

            try {
                wp.editor.initialize('task-description', config);
            } catch (error) {
                session.cancelled = true;
                session.failed = true; // Keep dirty tracking for the plain Text fallback.
                resolve();
                console.error('Task editor initialization failed', error);
            }
        });
    }

    /**
     * Get the current task description from whichever editor is active.
     *
     * @param {HTMLElement} context The container element holding the task form.
     * @return {string} The description HTML.
     */
    function getTaskDescription(context) {

        // wp.editor.getContent() syncs TinyMCE into the textarea when the
        // Visual tab is active and always returns the textarea value, so
        // edits made in the Text (quicktags) tab are never lost.
        if (
            context.querySelector('#task-description') &&
            typeof wp !== 'undefined' &&
            wp.editor &&
            typeof wp.editor.getContent === 'function'
        ) {
            return wp.editor.getContent('task-description');
        }

        if (taskEditor && typeof taskEditor.isHidden === 'function' && !taskEditor.isHidden()) {
            return taskEditor.getContent();
        }

        const textarea = context.querySelector('#task-description');
        return textarea ? textarea.value : '';
    }

    /**
     * Destroy the active classic editor instance so the card can be
     * reinitialized cleanly.
     */
    function destroyTaskEditor() {
        const textarea = document.getElementById('task-description');
        if (textarea?.deckerEditorSession) {
            textarea.deckerEditorSession.cancelled = true;
            textarea.deckerEditorSession.failed = false;
            textarea.deckerEditorSession.resolve();
        }
        // Remove any instance bound to the textarea even if 'init' has not
        // fired yet (fast modal open/close), so no orphan editor survives.
        // wp.editor.remove() is a safe no-op when nothing was initialized.
        if (typeof wp !== 'undefined' && wp.editor && typeof wp.editor.remove === 'function') {
            wp.editor.remove('task-description');
        }

        taskEditor = null;
    }

    // Function to initialize the tasks page within the given context
    function initializeTaskPage(context) {
        // A freshly rendered card always starts pristine, so the quick-action
        // mode and the one-way pristine-to-dirty transition reset per session.
        window.deckerHasUnsavedChanges = false;
        window.deckerTodayChangedInSession = false;

        // Read the server-provided edit-lock state for this card.
        const lockState = readTaskLockState(context);
        const lockedByOther = !!(lockState && lockState.locked);
        const taskForm = context.querySelector('#task-form');
        disabled = taskForm?.dataset.archived === '1';

        new Tablesort(context.querySelector('#user-history-table'));

        // Check if the task_id is present in data-task-id
        const taskId = getTaskId();
        const taskElement = context.querySelector(`[data-task-id="${taskId}"]`);
        if (taskElement) {
            console.log('Task ID found in data-task-id:', taskElement.getAttribute('data-task-id'));
        } else {
            console.log('Task ID not found in data-task-id');
        }

        // Initialize the classic editor with the current card’s read-only state.
        if (context.querySelector('#task-description')) {
            initializeTaskEditor(context, disabled || lockedByOther || taskForm?.dataset.readonly === '1');
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
        
        context.querySelectorAll('[name="attachment-type"]').forEach(select => {
            select.addEventListener('change', () => {
                const isLink = select.value === 'link';
                context.querySelector('#file-input').hidden = isLink;
                context.querySelector('#upload-file').hidden = isLink;
                context.querySelector('#attachment-url').hidden = !isLink;
                context.querySelector('#attachment-link-label').hidden = !isLink;
                context.querySelector('#add-attachment-link').hidden = !isLink;
                context.querySelector('#attachment-url-help').hidden = !isLink;
                context.querySelector('#attachment-url').disabled = !isLink;
                context.querySelector('#attachment-link-label').disabled = !isLink;
                context.querySelector('#attachment-url').setCustomValidity('');
            });
        });
        const attachmentUrl = context.querySelector('#attachment-url');
        if (attachmentUrl) {
            attachmentUrl.addEventListener('input', () => attachmentUrl.setCustomValidity(''));
            const attachmentLabel = context.querySelector('#attachment-link-label');
            [attachmentUrl, attachmentLabel].forEach(field => {
                field.addEventListener('keydown', event => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        context.querySelector('#add-attachment-link').click();
                    }
                });
            });
            context.querySelector('#add-attachment-link').addEventListener('click', () => {
                attachmentUrl.value = attachmentUrl.value.trim();
                let valid = false;
                try {
                    const url = new URL(attachmentUrl.value);
                    valid = ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password;
                    if (valid) attachmentUrl.value = url.href;
                } catch (e) {
                    // Native validation displays the localized error below.
                }
                attachmentUrl.setCustomValidity(valid ? '' : strings.invalid_attachment_url);
                if (attachmentUrl.reportValidity()) {
                    updateAttachmentLink('POST', attachmentUrl.value, context, null, attachmentLabel.value.trim());
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

        // Canonical dirty transition: shared-field edits switch the card from the
        // quick-action mode to the normal checkbox + full-save mode. 'task-today'
        // is intentionally NOT a dirty trigger — it is handled by the quick action.
        const enableSaveButton = function() {
            enterDirtyEditMode(context);
        };

        const form = context.querySelector('#task-form');

        // Add event listeners to all shared editable form inputs.
        const inputIds = ['task-title', 'task-due-date', 'task-board', 'task-stack', 'task-author-info', 'task-responsable', 'task-hidden', 'task-max-priority'];

        inputIds.forEach(function(id) {
            const element = context.querySelector(`#${id}`);
            if (element) {
                element.addEventListener('change', enableSaveButton);
                element.addEventListener('input', enableSaveButton);
            }
        });

        // Wire the "For today" quick action for pristine existing tasks.
        initializeTodayQuickAction(context);

        // Check the initial state of the highest priority checkbox and toggle the label
        var taskMaxPriorityCheck = context.querySelector('#task-max-priority');
        if (taskMaxPriorityCheck) {
            togglePriorityLabel(taskMaxPriorityCheck);
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

        document.querySelectorAll('.clone-task').forEach((element) => {

          element.removeEventListener('click', cloneTaskHandler);
          element.addEventListener('click', cloneTaskHandler);

        });

        document.querySelectorAll('.merge-task').forEach((element) => {

          element.removeEventListener('click', mergeTaskHandler);
          element.addEventListener('click', mergeTaskHandler);

        });

        // Activate edit-lock behavior for existing, non-archived cards.
        const lockTaskId = getTaskId();
        if (lockTaskId && lockTaskId !== '' && lockTaskId !== '0' && !disabled) {
            if (lockedByOther) {
                activeLock = { postId: parseInt(lockTaskId, 10), owned: false };
                applyLockedState(context);
            } else if (lockState && lockState.owned_by_current_user) {
                activeLock = { postId: parseInt(lockTaskId, 10), owned: true };
                // Poll faster while editing so a takeover blocks the previous
                // editor within a few seconds instead of a full heartbeat cycle.
                if (typeof window.deckerSetLockHeartbeatSpeed === 'function') {
                    window.deckerSetLockHeartbeatSpeed(true);
                }
            }
        }

        // The subtask pane owns its own rows and writes; it is only present
        // when the feature is enabled.
        if (typeof window.deckerInitSubtasks === 'function') {
            window.deckerInitSubtasks(context);
        }

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
                const extension = (file.name.match(/\.([0-9a-z]+)$/i) || [])[1] || '';
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

    // Links use the same task permissions and editing-session token as task saves.
    function updateAttachmentLink(method, url, context, listItem, label = '') {
        const button = listItem ? listItem.querySelector('.remove-attachment') : context.querySelector('#add-attachment-link');
        if (button.disabled) return;
        button.disabled = true;
        fetch(`${deckerRestUrl}tasks/${context.querySelector('input[name="task_id"]').value}/attachment-links`, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
            body: JSON.stringify({ url, label, lock_generation: (readTaskLockState(context) || {}).generation || '' })
        })
        .then(async response => {
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || strings.error_attachment_link);
            if (listItem) {
                listItem.remove();
                updateAttachmentCount(context, -1);
            } else {
                addAttachmentToList(null, data.url, data.label || data.url, '', context, true, data.label ? data.url : '');
                context.querySelector('#attachment-url').value = '';
                context.querySelector('#attachment-link-label').value = '';
            }
        })
        .catch(error => alert(error.message || strings.error_attachment_link))
        .finally(() => {
            const lock = readTaskLockState(context);
            button.disabled = !!(lock && lock.locked) || context.querySelector('#attachment-type').disabled;
        });
    }

    // Function to add an attachment to the list
    function addAttachmentToList(attachmentId, attachmentUrl, attachmentTitle, attachmentExtension, context, isLink = false, captionUrl = '') {
        var attachmentsList = context.querySelector('#attachments-list');
        var li = document.createElement('li');
        var attachmentFilename = isLink || !attachmentExtension ? attachmentTitle : `${attachmentTitle}.${attachmentExtension}`;

        li.className = 'list-group-item d-flex justify-content-between align-items-center';
        if (isLink) {
            li.dataset.attachmentUrl = attachmentUrl;
        } else {
            li.setAttribute('data-attachment-id', attachmentId);
        }

        const link = document.createElement('a');
        link.href = attachmentUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.className = 'text-break me-2';
        if (!isLink) link.download = attachmentFilename;
        const text = document.createElement('span');
        text.className = 'attachment-link-text';
        text.textContent = attachmentFilename;
        link.appendChild(text);
        if (captionUrl) {
            const caption = document.createElement('span');
            caption.className = 'text-muted d-block small';
            caption.textContent = captionUrl;
            link.appendChild(caption);
        }

        const icon = document.createElement('i');
        icon.className = isLink ? 'ri-link me-2' : 'ri-file-line me-2';
        icon.setAttribute('aria-hidden', 'true');
        link.prepend(icon);

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
            const context = listItem.closest('.task-modal') || document;
            if (listItem.dataset.attachmentUrl) {
                if (confirm(strings.confirm_delete_attachment)) {
                    updateAttachmentLink('DELETE', listItem.dataset.attachmentUrl, context, listItem);
                }
            } else {
                deleteAttachment(attachmentId, listItem, context);
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

        // Embed the lock generation from the form session so the server can
        // reject this save after a takeover even if the active lock was released.
        const lockGeneration = (readTaskLockState(form) || {}).generation ?? '';

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
            description: getTaskDescription(form),
            max_priority: form.querySelector('#task-max-priority').checked ? 1 : 0,
            mark_for_today: form.querySelector('#task-today').checked ? 1 : 0,
            lock_generation: lockGeneration,
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
            let response = null;
            try {
                response = JSON.parse(xhr.responseText);
            } catch (e) {
                response = null;
            }

            taskSaveInFlight = false;

            if (xhr.status >= 200 && xhr.status < 400 && response && response.success) {
                window.deckerHasUnsavedChanges = false;
                // Adopt the server's rotated generation (only ever from our own
                // save response) so the next save uses it and a second tab of the
                // same user holding the old token can no longer overwrite us.
                if (response.data && response.data.generation && form.dataset.lock) {
                    try {
                        const lock = JSON.parse(form.dataset.lock);
                        lock.generation = response.data.generation;
                        form.dataset.lock = JSON.stringify(lock);
                    } catch (e) {
                        // Leave the existing data-lock untouched when unparseable.
                    }
                }
                if (window.Swal) {
                    window.Swal.fire({
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
                    return;
                }

                // Full-page view: only navigate when this save created a new
                // task. Reloading an existing card would fire pagehide and
                // release the edit lock; the server also invalidates stale
                // sessions via lock generation, but staying put avoids the race.
                const savedId = response.data && response.data.task_id
                    ? String(response.data.task_id)
                    : '';
                const currentId = formData.task_id ? String(formData.task_id) : '';
                if (savedId && (!currentId || currentId === '0' || currentId !== savedId)) {
                    window.location.href = `${homeUrl}?decker_page=task&id=${savedId}`;
                    return;
                }

                // Return to pristine mode: keep Save disabled until the next edit.
                if (saveButton) {
                    saveButton.disabled = true;
                }
                if (saveDropdown) {
                    // Existing-task split actions stay available (archive, etc.).
                    saveDropdown.disabled = false;
                }
                return;
            }

            // Lock conflicts are returned as HTTP 409 with success:false.
            // Handle them for any status so the previous editor is blocked
            // immediately instead of only on 2xx bodies.
            if (isTaskLockConflictResponse(response)) {
                const lockContext = document.querySelector('.task-modal.show') || document;
                handleLockLost(lockContext, response.data);
                alert(response.data.message || strings.lock_lost_message);
                return;
            }

            if (response && response.data && response.data.message) {
                alert(response.data.message);
            } else if (xhr.status >= 200 && xhr.status < 400) {
                alert(strings.error_saving_task);
            } else {
                console.error(strings.server_response_error);
                alert(strings.an_error_occurred_saving_task);
            }
            if (saveButton) {
                saveButton.disabled = false;
            }
            if (saveDropdown) {
                saveDropdown.disabled = false;
            }
        };

        xhr.onerror = function() {
            taskSaveInFlight = false;
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

        taskSaveInFlight = true;
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
    window.destroyTaskEditor = destroyTaskEditor;
    window.togglePriorityLabel = togglePriorityLabel;

    window.addEventListener('beforeunload', function(event) {
        if (window.deckerHasUnsavedChanges) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

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
