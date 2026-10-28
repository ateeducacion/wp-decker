/**
 * Subtask list for the task card.
 *
 * The pane ships the stored list as JSON and this module owns the rows, so the
 * row markup exists once instead of being written in PHP for the first paint
 * and again here for every edit.
 *
 * Every change writes the whole list back through
 * decker/v1/tasks/<id>/subtasks, carrying the editor form's lock
 * generation: the server rejects a stale form, which is also what keeps two
 * people (or two tabs) from clobbering each other's list.
 */
/* global bootstrap, Choices, Sortable, deckerVars, wpApiSettings */

(function () {
    'use strict';

    const STATUSES = ['to-do', 'in-progress', 'done'];

    const STATUS_ICONS = {
        'to-do': 'ri-checkbox-blank-circle-line text-secondary',
        'in-progress': 'ri-progress-3-line text-warning',
        'done': 'ri-checkbox-circle-line text-success'
    };

    const SEGMENT_CLASSES = {
        'done': 'bg-success',
        'in-progress': 'bg-warning',
        'to-do': 'bg-secondary'
    };

    const SAVE_DEBOUNCE_MS = 500;

    /**
     * Read a localized string, falling back to the given English default.
     *
     * @param {string} key      The string key in deckerVars.strings.
     * @param {string} fallback The default text.
     * @return {string} The localized string.
     */
    function text(key, fallback) {
        const strings = (typeof deckerVars !== 'undefined' && deckerVars.strings) || {};
        return strings[key] || fallback;
    }

    /**
     * The localized label of one item state.
     *
     * @param {string} status The status slug.
     * @return {string} The label.
     */
    function statusLabel(status) {
        const labels = {
            'to-do': text('stack_to_do', 'To-Do'),
            'in-progress': text('stack_in_progress', 'In Progress'),
            'done': text('stack_done', 'Done')
        };
        return labels[status] || status;
    }

    /**
     * Fill a sprintf-style template with positional arguments.
     *
     * @param {string} template The template, using %1$s / %2$s placeholders.
     * @param {Array}  args     The values to interpolate.
     * @return {string} The filled string.
     */
    function format(template, args) {
        return template.replace(/%(\d+)\$[sd]/g, function (match, position) {
            const value = args[Number(position) - 1];
            return typeof value === 'undefined' ? match : String(value);
        });
    }

    /**
     * Parse a JSON data attribute, tolerating an empty or malformed value.
     *
     * @param {HTMLElement} element The element holding the attribute.
     * @param {string}      name    The dataset key.
     * @param {*}           fallback The value to use when parsing fails.
     * @return {*} The parsed value.
     */
    function readJson(element, name, fallback) {
        const raw = element.dataset[name];
        if (!raw) {
            return fallback;
        }
        try {
            const parsed = JSON.parse(raw);
            return parsed === null ? fallback : parsed;
        } catch (e) {
            return fallback;
        }
    }

    /**
     * Ask the viewer to confirm something that cannot be undone.
     *
     * SweetAlert like the rest of the card; window.confirm only where it is
     * missing, which in practice is the test environment. The card is always
     * in this document, so this window's instance is the one to use: reaching
     * for window.parent throws outright when the site is framed by another
     * origin, as WordPress Playground frames it.
     *
     * @param {string} question  The question, as the dialog title.
     * @param {string} detail    What it is about, usually the item's title.
     * @param {string} confirm   The label of the confirming button.
     * @param {Object} [options] `checkbox` adds an opt-in the answer carries
     *                           back as `checked`; `danger: false` drops the
     *                           red button, for a question that destroys
     *                           nothing.
     * @return {Promise<{confirmed: boolean, checked: boolean}>} The answer.
     */
    function ask(question, detail, confirm, options) {
        const settings = options || {};
        const swal = window.Swal;

        if (!swal) {
            return Promise.resolve({
                confirmed: window.confirm(detail ? `${question} ${detail}` : question),
                checked: false
            });
        }

        const dialog = {
            title: question,
            text: detail,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: confirm,
            cancelButtonText: text('cancel', 'Cancel'),
            confirmButtonColor: false === settings.danger ? '#3085d6' : '#d33',
            cancelButtonColor: '#3085d6'
        };

        if (settings.checkbox) {
            dialog.input = 'checkbox';
            dialog.inputValue = 0;
            dialog.inputPlaceholder = settings.checkbox;
        }

        return swal.fire(dialog).then(function (result) {
            const confirmed = !!(result && result.isConfirmed);
            return {
                confirmed: confirmed,
                checked: confirmed && !!(result && result.value)
            };
        });
    }

    /**
     * Today's date as a Y-m-d string in the browser's timezone.
     *
     * @return {string} The current date.
     */
    function today() {
        const now = new Date();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${now.getFullYear()}-${month}-${day}`;
    }

    /**
     * Controller for one subtask pane.
     *
     * @param {HTMLElement} root    The .decker-subtasks container.
     * @param {HTMLElement} context The card container (modal body or page).
     */
    function SubtaskList(root, context) {
        this.root = root;
        this.context = context;
        this.taskId = root.dataset.taskId || '0';
        this.readOnly = root.dataset.readonly === '1';
        this.items = readJson(root, 'items', []);
        this.people = readJson(root, 'people', []);

        this.listEl = root.querySelector('.decker-subtasks-list');
        this.emptyEl = root.querySelector('.decker-subtasks-empty');
        this.progressEl = root.querySelector('.decker-subtasks-progress');
        this.summaryEl = root.querySelector('.decker-subtasks-summary');
        this.statusEl = root.querySelector('.decker-subtasks-status');
        this.newInput = root.querySelector('.decker-subtask-new');
        this.addButton = root.querySelector('.decker-subtask-add-btn');

        this.saveTimer = null;
        this.savePending = false;
        this.saveSeq = 0;
        this.sortable = null;
        this.pickers = [];

        if (!Array.isArray(this.items)) {
            this.items = [];
        }
        if (!Array.isArray(this.people)) {
            this.people = [];
        }
    }

    SubtaskList.prototype = {

        /**
         * Render the pane and wire its controls.
         */
        init: function () {
            this.renderAll();
            this.wireAddControls();
            this.wireSorting();
        },

        /**
         * The lock generation of the open editor session.
         *
         * @return {string} The generation token, or an empty string.
         */
        lockGeneration: function () {
            const form = this.context.querySelector('#task-form');
            if (!form || !form.dataset.lock) {
                return '';
            }
            try {
                const lock = JSON.parse(form.dataset.lock);
                return (lock && lock.generation) || '';
            } catch (e) {
                return '';
            }
        },

        /**
         * The next free item id, so a new row is addressable before it is saved.
         *
         * @return {number} The id.
         */
        nextId: function () {
            return this.items.reduce(function (max, item) {
                return Math.max(max, Number(item.id) || 0);
            }, 0) + 1;
        },

        /**
         * Rebuild every row from the current state.
         */
        renderAll: function () {
            if (!this.listEl) {
                return;
            }

            this.destroyAssigneePickers();
            this.listEl.textContent = '';
            this.items.forEach(function (item) {
                this.listEl.appendChild(this.buildRow(item));
            }, this);

            if (this.emptyEl) {
                this.emptyEl.classList.toggle('d-none', this.items.length > 0);
            }

            this.updateProgress();
        },

        /**
         * Build the row element for one item.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The row.
         */
        buildRow: function (item) {
            const row = document.createElement('div');
            row.className = 'decker-subtask-row';
            row.dataset.itemId = String(item.id);

            if (!this.readOnly) {
                row.appendChild(this.buildHandle());
            }

            row.appendChild(this.buildStatusButton(item));
            row.appendChild(this.buildTitleField(item));
            row.appendChild(this.buildAssigneeField(item));
            row.appendChild(this.buildDueDateField(item));
            this.applyAssigneeAvatar(row, item);

            if (!this.readOnly) {
                row.appendChild(this.buildRowActions(item));
            }

            this.applyStatus(row, item);

            return row;
        },

        /**
         * Build the drag handle.
         *
         * @return {HTMLElement} The handle.
         */
        buildHandle: function () {
            const handle = document.createElement('i');
            handle.className = 'ri-draggable decker-subtask-handle';
            handle.setAttribute('role', 'presentation');
            handle.title = text('subtask_reorder', 'Drag to reorder');
            return handle;
        },

        /**
         * Build the button that cycles an item through the three states.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The button.
         */
        buildStatusButton: function (item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link p-0 border-0 decker-subtask-status';
            button.disabled = this.readOnly;
            button.appendChild(document.createElement('i'));

            if (!this.readOnly) {
                button.addEventListener('click', function () {
                    const current = STATUSES.indexOf(item.status);
                    item.status = STATUSES[(current + 1) % STATUSES.length];
                    this.applyStatus(button.closest('.decker-subtask-row'), item);
                    this.updateProgress();
                    // Written at once, not debounced: ticking an item off is a
                    // discrete action, and the card is often closed right after.
                    this.save(true);
                }.bind(this));
            }

            return button;
        },

        /**
         * Build the editable title field.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The field.
         */
        buildTitleField: function (item) {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm decker-subtask-title';
            input.value = item.title;
            input.maxLength = 255;
            input.setAttribute('aria-label', text('subtask_title', 'Subtask'));

            if (item.task_id) {
                // A promoted item is edited on its own card from now on, so the
                // title here is frozen and the row links to that task instead.
                input.readOnly = true;
            } else if (this.readOnly) {
                input.readOnly = true;
            } else {
                input.addEventListener('input', function () {
                    item.title = input.value;
                    this.save();
                }.bind(this));

                // Flush the pending write before the field loses focus, so a
                // closing modal cannot drop the last keystrokes.
                input.addEventListener('blur', this.flush.bind(this));

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        // The pane lives inside #task-form; Enter would submit it.
                        event.preventDefault();
                        this.flush();
                    }
                }.bind(this));
            }

            return input;
        },

        /**
         * Build the assignee select, limited to the people on the card.
         *
         * Upgraded to Choices.js like the card's own people pickers, so the row
         * shows the face rather than only the name. Without Choices (it comes
         * from a CDN) the plain select and a small avatar beside it carry the
         * same information.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The select.
         */
        buildAssigneeField: function (item) {
            const wrap = document.createElement('span');
            wrap.className = 'decker-subtask-assignee-field';
            const enhance = !this.readOnly && typeof Choices !== 'undefined';

            if (!enhance) {
                const avatar = document.createElement('img');
                avatar.className = 'decker-subtask-avatar';
                avatar.alt = '';
                avatar.setAttribute('aria-hidden', 'true');
                avatar.width = 20;
                avatar.height = 20;
                wrap.appendChild(avatar);
            }

            const select = document.createElement('select');
            select.className = 'form-select form-select-sm decker-subtask-assignee';
            select.disabled = this.readOnly;
            select.setAttribute('aria-label', text('subtask_assignee', 'Assignee'));

            const none = document.createElement('option');
            none.value = '0';
            none.textContent = text('subtask_unassigned', 'Unassigned');
            select.appendChild(none);

            this.people.forEach(function (person) {
                const option = document.createElement('option');
                option.value = String(person.id);
                option.textContent = person.name;
                if (person.avatar) {
                    option.dataset.customProperties = JSON.stringify({ avatar: person.avatar });
                }
                select.appendChild(option);
            });

            select.value = String(item.assignee || 0);
            wrap.appendChild(select);

            if (!this.readOnly) {
                // Choices.js forwards the pick to the original select, so this
                // one listener serves both the enhanced and the plain field.
                select.addEventListener('change', function () {
                    item.assignee = parseInt(select.value, 10) || 0;
                    this.applyAssigneeAvatar(wrap.closest('.decker-subtask-row'), item);
                    this.save(true);
                }.bind(this));
            }

            if (enhance) {
                this.enhanceAssignee(select);
            }

            return wrap;
        },

        /**
         * Turn one assignee select into a Choices.js picker showing faces.
         *
         * @param {HTMLElement} select The select to upgrade.
         */
        enhanceAssignee: function (select) {
            this.pickers.push(new Choices(select, {
                allowHTML: true,
                shouldSort: false,
                // A card's people are the responsable plus the assignees, so
                // the list is short; searching a handful of names is friction.
                searchEnabled: this.people.length > 7,
                itemSelectText: '',
                callbackOnCreateTemplates: function () {
                    const base = Choices.defaults.templates;

                    /**
                     * Put the person's picture before their name.
                     *
                     * @param {HTMLElement} el   The rendered element.
                     * @param {Object}      data The choice data.
                     * @return {HTMLElement} The same element.
                     */
                    const withAvatar = function (el, data) {
                        const url = data.customProperties && data.customProperties.avatar;
                        if (!url) {
                            return el;
                        }
                        const img = document.createElement('img');
                        img.className = 'decker-subtask-avatar';
                        img.src = url;
                        img.alt = '';
                        img.width = 20;
                        img.height = 20;
                        el.insertBefore(img, el.firstChild);
                        return el;
                    };

                    return Object.assign({}, base, {
                        item: function (...args) {
                            return withAvatar(base.item.apply(this, args), args[1]);
                        },
                        choice: function (...args) {
                            return withAvatar(base.choice.apply(this, args), args[1]);
                        }
                    });
                }
            }));
        },

        /**
         * Tear the assignee pickers down before their rows are replaced.
         *
         * Choices.js listens on the document, so an instance whose row was
         * dropped would keep doing so.
         */
        destroyAssigneePickers: function () {
            this.pickers.forEach(function (picker) {
                picker.destroy();
            });
            this.pickers = [];
        },

        /**
         * Show the assigned person's picture on a row, or hide it when nobody is.
         *
         * @param {HTMLElement} row  The row element.
         * @param {Object}      item The subtask item.
         */
        applyAssigneeAvatar: function (row, item) {
            if (!row) {
                return;
            }

            // Direct child only: an upgraded field renders the face inside the
            // Choices item, which Choices itself keeps in step.
            const avatar = row.querySelector('.decker-subtask-assignee-field > .decker-subtask-avatar');
            if (!avatar) {
                return;
            }

            const person = this.people.filter(function (candidate) {
                return candidate.id === item.assignee;
            })[0];

            if (person && person.avatar) {
                avatar.src = person.avatar;
                avatar.title = person.name;
                avatar.hidden = false;
                return;
            }

            avatar.removeAttribute('src');
            avatar.removeAttribute('title');
            avatar.hidden = true;
        },

        /**
         * Build the due-date field.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The input.
         */
        buildDueDateField: function (item) {
            const input = document.createElement('input');
            input.type = 'date';
            input.className = 'form-control form-control-sm decker-subtask-duedate';
            input.value = item.duedate || '';
            input.disabled = this.readOnly;
            input.setAttribute('aria-label', text('subtask_duedate', 'Due date'));

            if (!this.readOnly) {
                input.addEventListener('change', function () {
                    item.duedate = input.value;
                    this.applyStatus(input.closest('.decker-subtask-row'), item);
                    this.save(true);
                }.bind(this));
            }

            return input;
        },

        /**
         * Build the per-row actions: open or promote, and delete.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The action group.
         */
        buildRowActions: function (item) {
            const group = document.createElement('span');
            group.className = 'text-nowrap';

            group.appendChild(item.task_id ? this.buildOpenButton(item) : this.buildConvertButton(item));
            group.appendChild(this.buildDeleteButton(item));

            return group;
        },

        /**
         * Build the link to the task a promoted item became.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The link.
         */
        buildOpenButton: function (item) {
            const link = document.createElement('a');
            link.className = 'btn btn-link btn-sm p-1 text-muted';
            link.href = `${deckerVars.home_url}?decker_page=task&id=${encodeURIComponent(item.task_id)}`;
            link.title = text('subtask_open_task', 'Open the task this subtask became');
            link.setAttribute('aria-label', link.title);
            link.innerHTML = '<i class="ri-external-link-line"></i>';
            return link;
        },

        /**
         * Build the "convert into task" action.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The button.
         */
        buildConvertButton: function (item) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link btn-sm p-1 text-muted decker-subtask-convert';
            button.title = text('subtask_convert', 'Convert into a task');
            button.setAttribute('aria-label', button.title);
            button.innerHTML = '<i class="ri-git-branch-line"></i>';

            button.addEventListener('click', function () {
                // Promotion is one-way: the item freezes and a real task
                // appears on the board, so it asks first, like deleting does.
                ask(
                    text('confirm_convert_subtask_title', 'Turn this subtask into a task of its own?'),
                    (item.title || '').trim(),
                    text('yes_convert', 'Yes, convert')
                ).then(function (answer) {
                    if (answer.confirmed) {
                        this.convert(item, button);
                    }
                }.bind(this));
            }.bind(this));

            return button;
        },

        /**
         * Build the delete action.
         *
         * @param {Object} item The subtask item.
         * @return {HTMLElement} The button.
         */
        buildDeleteButton: function (item) {
            // A promoted item owns a real task now, so taking it off the list
            // destroys nothing: it is an unlink, and says so with its own
            // icon, its own colour and a question that promises the task
            // survives — with archiving it as an opt-in, since wanting the
            // list clean usually means the work is over.
            const linked = parseInt(item.task_id, 10) > 0;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link btn-sm p-1 decker-subtask-delete '
                + (linked ? 'text-muted decker-subtask-unlink' : 'text-danger');
            button.title = linked
                ? text('subtask_unlink', 'Unlink from this task')
                : text('subtask_delete', 'Delete subtask');
            button.setAttribute('aria-label', button.title);
            button.innerHTML = linked
                ? '<i class="ri-link-unlink"></i>'
                : '<i class="ri-delete-bin-line"></i>';

            button.addEventListener('click', function () {
                this.askBeforeRemoving(item, linked).then(function (answer) {
                    if (!answer.confirmed) {
                        return;
                    }

                    this.items = this.items.filter(function (candidate) {
                        return candidate !== item;
                    });
                    this.renderAll();

                    // Archiving only after the shorter list is stored, so a
                    // failure on either side keeps its own message.
                    this.save(true).then(function () {
                        if (linked && answer.checked) {
                            return this.archive(item.task_id);
                        }
                    }.bind(this));
                }.bind(this));
            }.bind(this));

            return button;
        },

        /**
         * Ask before taking an item off the list.
         *
         * @param {Object}  item   The subtask item.
         * @param {boolean} linked Whether it already became a task.
         * @return {Promise<{confirmed: boolean, checked: boolean}>} The answer.
         */
        askBeforeRemoving: function (item, linked) {
            const title = (item.title || '').trim();

            if (!linked) {
                return ask(
                    text('confirm_delete_subtask_title', 'Are you sure you want to delete this subtask?'),
                    title,
                    text('yes_delete', 'Yes, delete')
                );
            }

            return ask(
                text('confirm_unlink_subtask_title', 'Unlink this subtask?'),
                format(
                    text('confirm_unlink_subtask_text', '\u201c%1$s\u201d leaves this list. The task it became stays on the board.'),
                    [ title ]
                ),
                text('yes_unlink', 'Yes, unlink'),
                {
                    checkbox: text('archive_linked_task', 'Archive that task too'),
                    danger: false
                }
            );
        },

        /**
         * Archive the task an unlinked item had become.
         *
         * @param {number} taskId The task id.
         * @return {Promise} Resolved once the attempt is over.
         */
        archive: function (taskId) {
            const failed = function () {
                this.setStatusMessage(text('task_archive_failed', 'The task could not be archived.'), true);
            }.bind(this);

            return fetch(`${wpApiSettings.root}wp/v2/tasks/${encodeURIComponent(taskId)}`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce,
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ status: 'archived' })
            })
                .then(function (response) {
                    if (!response.ok) {
                        failed();
                    }
                })
                .catch(failed);
        },

        /**
         * Reflect an item's state and overdue-ness on its row.
         *
         * @param {HTMLElement} row  The row element.
         * @param {Object}      item The subtask item.
         */
        applyStatus: function (row, item) {
            if (!row) {
                return;
            }

            const icon = row.querySelector('.decker-subtask-status i');
            if (icon) {
                icon.className = STATUS_ICONS[item.status] || STATUS_ICONS['to-do'];
            }

            const statusButton = row.querySelector('.decker-subtask-status');
            if (statusButton) {
                const label = format(text('subtask_state', 'State: %1$s'), [statusLabel(item.status)]);
                statusButton.title = label;
                statusButton.setAttribute('aria-label', label);
            }

            row.classList.toggle('decker-subtask-done', item.status === 'done');

            // An unfinished item past its date is flagged on the row itself; the
            // card counter only carries the aggregate. Its own class, not
            // Bootstrap's is-invalid: the date is late, not wrong, and the
            // error styling made it look like the form had rejected it.
            const overdue = !!item.duedate && item.status !== 'done' && item.duedate < today();
            const dateField = row.querySelector('.decker-subtask-duedate');
            if (dateField) {
                dateField.classList.toggle('decker-subtask-overdue', overdue);
                if (overdue) {
                    dateField.title = text('subtask_overdue', 'This subtask is past its due date');
                } else {
                    dateField.removeAttribute('title');
                }
            }
        },

        /**
         * Update the progress bar, the pane summary and the tab badge.
         */
        updateProgress: function () {
            const counts = { 'to-do': 0, 'in-progress': 0, 'done': 0 };

            this.items.forEach(function (item) {
                if (Object.prototype.hasOwnProperty.call(counts, item.status)) {
                    counts[item.status] += 1;
                }
            });

            const total = this.items.length;
            const done = counts.done;

            if (this.progressEl) {
                this.progressEl.textContent = '';
                if (total > 0) {
                    const doneWidth = Math.round(done * 100 / total);
                    const progressWidth = Math.round(counts['in-progress'] * 100 / total);
                    const widths = {
                        'done': doneWidth,
                        'in-progress': progressWidth,
                        'to-do': Math.max(0, 100 - doneWidth - progressWidth)
                    };

                    Object.keys(widths).forEach(function (status) {
                        if (widths[status] <= 0) {
                            return;
                        }
                        const segment = document.createElement('div');
                        segment.className = `progress-bar ${SEGMENT_CLASSES[status]}`;
                        segment.style.width = `${widths[status]}%`;
                        this.progressEl.appendChild(segment);
                    }, this);
                }
            }

            if (this.summaryEl) {
                this.summaryEl.textContent = total > 0
                    ? format(text('subtasks_progress', '%1$d of %2$d done'), [done, total])
                    : '';
            }

            const badge = this.context.querySelector('#subtask-count');
            if (badge) {
                badge.textContent = `${done}/${total}`;
            }
        },

        /**
         * Wire the "add subtask" input and button.
         */
        wireAddControls: function () {
            if (this.readOnly || !this.newInput) {
                return;
            }

            const submit = function () {
                this.addFromText(this.newInput.value);
                this.newInput.value = '';
                this.newInput.focus();
            }.bind(this);

            this.newInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    // The pane lives inside #task-form; Enter would submit it.
                    event.preventDefault();
                    submit();
                }
            });

            // Pasting a block of lines is how an existing checklist or
            // spreadsheet column gets in here, so it adds one item per line
            // instead of one item with embedded newlines.
            this.newInput.addEventListener('paste', function (event) {
                const pasted = (event.clipboardData || window.clipboardData).getData('text');
                if (!pasted || pasted.indexOf('\n') === -1) {
                    return;
                }
                event.preventDefault();
                this.addFromText(pasted);
            }.bind(this));

            if (this.addButton) {
                this.addButton.addEventListener('click', submit);
            }
        },

        /**
         * Add one item per non-empty line of the given text.
         *
         * @param {string} value The raw text.
         */
        addFromText: function (value) {
            const titles = String(value || '')
                .split(/\r?\n/)
                .map(function (line) {
                    return line.trim();
                })
                .filter(function (line) {
                    return line !== '';
                });

            if (!titles.length) {
                return;
            }

            const assignee = this.defaultAssignee();
            const duedate = this.defaultDueDate();

            titles.forEach(function (title) {
                this.items.push({
                    id: this.nextId(),
                    title: title.slice(0, 255),
                    status: 'to-do',
                    assignee: assignee,
                    duedate: duedate,
                    task_id: 0
                });
            }, this);

            this.renderAll();
            this.save(true);
        },

        /**
         * The person a new item starts out assigned to.
         *
         * Whoever is breaking a task down is usually breaking down their own
         * work, so the viewer is the default — but only when they are on the
         * card, since the server rejects anyone else.
         *
         * @return {number} The user ID, or 0 when the viewer is not on the card.
         */
        defaultAssignee: function () {
            const me = (typeof deckerVars !== 'undefined' && parseInt(deckerVars.current_user_id, 10)) || 0;

            return this.people.some(function (person) {
                return person.id === me;
            }) ? me : 0;
        },

        /**
         * The date a new item starts out due: the parent task's.
         *
         * Read from the form rather than from the saved task, so a due date
         * that was changed but not yet saved is the one that is inherited.
         *
         * @return {string} The date in Y-m-d, or an empty string.
         */
        defaultDueDate: function () {
            const field = this.context.querySelector('#task-due-date');

            return (field && field.value) || '';
        },

        /**
         * Make the list reorderable by dragging.
         */
        wireSorting: function () {
            if (this.readOnly || !this.listEl || typeof Sortable === 'undefined') {
                return;
            }

            this.sortable = Sortable.create(this.listEl, {
                handle: '.decker-subtask-handle',
                animation: 150,
                ghostClass: 'decker-subtask-placeholder',
                onEnd: this.applyDomOrder.bind(this)
            });
        },

        /**
         * Adopt the order the rows are now in and persist it.
         */
        applyDomOrder: function () {
            const order = Array.prototype.map.call(
                this.listEl.querySelectorAll('.decker-subtask-row'),
                function (row) {
                    return row.dataset.itemId;
                }
            );

            this.items.sort(function (left, right) {
                return order.indexOf(String(left.id)) - order.indexOf(String(right.id));
            });

            this.save(true);
        },

        /**
         * Promote an item into a task of its own.
         *
         * @param {Object}      item   The subtask item.
         * @param {HTMLElement} button The button that triggered it.
         */
        convert: function (item, button) {
            if (button.getAttribute('aria-busy') === 'true') {
                return;
            }
            button.setAttribute('aria-busy', 'true');
            this.setStatusMessage(text('saving', 'Saving…'), false);

            const itemId = item.id;

            // The server promotes the item it has STORED, so the list is always
            // written first — not only when a debounced edit is pending. A row
            // the viewer can see is not proof the server kept it: if an earlier
            // write failed, converting would ask for an id that was never
            // stored and come back "subtask not found", blaming the wrong step.
            this.send()
                .then(function (saved) {
                    if (!saved) {
                        // send() already reported why the list could not be
                        // written, which is the error that actually matters.
                        return null;
                    }

                    if (!this.items.some(function (candidate) {
                        return candidate.id === itemId;
                    })) {
                        this.setStatusMessage(text('subtasks_save_failed', 'The subtasks could not be saved.'), true);
                        return null;
                    }

                    return fetch(`${wpApiSettings.root}decker/v1/tasks/${encodeURIComponent(this.taskId)}/subtasks/${encodeURIComponent(itemId)}/convert`, {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': wpApiSettings.nonce,
                            'Content-Type': 'application/json'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ lock_generation: this.lockGeneration() })
                    });
                }.bind(this))
                .then(function (response) {
                    if (!response) {
                        return null;
                    }
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    button.removeAttribute('aria-busy');
                    if (!result) {
                        return;
                    }
                    if (!result.ok || !result.data || !result.data.success) {
                        this.reportFailure(result.data);
                        return;
                    }
                    window.deckerSubtasksChangedInSession = true;
                    this.items = result.data.items;
                    this.renderAll();
                    this.setStatusMessage(text('subtask_converted', 'The subtask is now a task of its own.'), false);
                }.bind(this))
                .catch(function () {
                    button.removeAttribute('aria-busy');
                    this.reportFailure(null);
                }.bind(this));
        },

        /**
         * Queue a write of the whole list.
         *
         * @param {boolean} immediate Whether to skip the debounce window.
         * @return {Promise<boolean>} Whether the list is now stored. A debounced
         *                            save resolves false: nothing is written yet.
         */
        save: function (immediate) {
            if (this.readOnly) {
                return Promise.resolve(false);
            }

            window.clearTimeout(this.saveTimer);

            if (immediate) {
                return this.send();
            }

            this.savePending = true;
            this.saveTimer = window.setTimeout(this.send.bind(this), SAVE_DEBOUNCE_MS);

            return Promise.resolve(false);
        },

        /**
         * Write a pending debounced change immediately, if there is one.
         *
         * @return {Promise<boolean>} Whether a pending write reached the server.
         */
        flush: function () {
            if (!this.savePending) {
                return Promise.resolve(false);
            }
            window.clearTimeout(this.saveTimer);
            return this.send();
        },

        /**
         * Write the current list to the server.
         *
         * @return {Promise<boolean>} Whether the list is now stored.
         */
        send: function () {
            this.savePending = false;

            if (this.readOnly || this.taskId === '0') {
                return Promise.resolve(false);
            }

            // Writes are whole-list replacements, so two of them in flight at
            // once (typing a second item before the first write answered) can
            // come back out of order. The sequence marks which answer still
            // describes what is stored.
            const seq = ++this.saveSeq;

            this.setStatusMessage(text('saving', 'Saving…'), false);

            const url = `${wpApiSettings.root}decker/v1/tasks/${encodeURIComponent(this.taskId)}/subtasks`;

            // POST, although the route is a replacement: some hosts — the
            // WordPress Playground demo among them — never pass the body of a
            // PUT to PHP, and the whole list travels in that body.
            return fetch(url, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce,
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    items: this.items,
                    lock_generation: this.lockGeneration()
                })
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data || !result.data.success) {
                        this.reportFailure(result.data);
                        return false;
                    }

                    // Adopt the stored list: the server drops empty titles, caps
                    // the list and mints ids, so the pane must not keep showing
                    // rows that were not persisted. It usually stores exactly
                    // what was sent, and re-rendering then would detach the
                    // controls under the user's cursor for nothing, so the rows
                    // are rebuilt only when the stored list actually differs.
                    // A newer write is already on its way, and its answer —
                    // not this one — will describe the stored list. Adopting
                    // here would drop whatever was typed in the meantime.
                    if (seq !== this.saveSeq) {
                        return true;
                    }

                    // The board card behind the modal is PHP-rendered, so it
                    // now shows a stale list; the modal refreshes the view on
                    // close, the way the "For today" quick action does.
                    window.deckerSubtasksChangedInSession = true;

                    const stored = result.data.items;
                    if (JSON.stringify(stored) !== JSON.stringify(this.items)) {
                        this.items = stored;
                        this.renderAll();
                    }
                    this.setStatusMessage(text('saved', 'Saved'), false);

                    return true;
                }.bind(this))
                .catch(function () {
                    this.reportFailure(null);
                    return false;
                }.bind(this));
        },

        /**
         * Surface a failed write.
         *
         * @param {Object|null} data The error payload, when the server sent one.
         */
        reportFailure: function (data) {
            const message = (data && data.message) || text('subtasks_save_failed', 'The subtasks could not be saved.');
            this.setStatusMessage(message, true);
        },

        /**
         * Show a message in the pane's status line.
         *
         * @param {string}  message The message.
         * @param {boolean} isError Whether it reports a failure.
         */
        setStatusMessage: function (message, isError) {
            if (!this.statusEl) {
                return;
            }
            this.statusEl.textContent = message;
            this.statusEl.classList.toggle('text-danger', !!isError);
            this.statusEl.classList.toggle('text-muted', !isError);
        }
    };

    // The tab to activate on the next card load, set by a board card whose
    // subtask counter was clicked.
    let requestedTab = null;

    /**
     * Initialize the subtask pane of a freshly rendered card.
     *
     * Called by task-card.js after the card markup is in the DOM.
     *
     * @param {HTMLElement} context The card container.
     */
    window.deckerInitSubtasks = function (context) {
        if (!context) {
            return;
        }

        const root = context.querySelector('.decker-subtasks');
        if (!root || root.dataset.deckerSubtasksInited === '1') {
            return;
        }
        root.dataset.deckerSubtasksInited = '1';

        const list = new SubtaskList(root, context);
        list.init();

        // In the modal the wanted tab comes from the click on the opener; on
        // the full-page card it is the `decker_tab` the chip's href carries,
        // which is also what makes "open in a new tab" land on the right tab.
        let wanted = requestedTab;
        if (!wanted) {
            try {
                wanted = new URLSearchParams(window.location.search).get('decker_tab');
            } catch (e) {
                wanted = null;
            }
        }

        if (wanted === 'subtasks') {
            const trigger = context.querySelector('a[href="#subtasks-tab"]:not(.disabled)');
            if (trigger && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(trigger).show();
            }
        }
        requestedTab = null;
    };

    // A card opened from its subtask counter lands on the Subtasks tab. The
    // opener is only reachable from the modal's show event, which fires before
    // the card markup is fetched, so the request is parked until init runs.
    document.addEventListener('click', function (event) {
        const opener = event.target.closest('[data-decker-open-tab]');
        requestedTab = opener ? opener.getAttribute('data-decker-open-tab') : null;
    }, true);
})();
