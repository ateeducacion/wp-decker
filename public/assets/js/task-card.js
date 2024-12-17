
(function() {
    console.log('loading task-card.js');

    // Variables globales recibidas desde PHP
    const ajaxUrl = deckerVars.ajax_url;
    const restUrl = deckerVars.rest_url;
    const homeUrl = deckerVars.home_url;
    const nonces = deckerVars.nonces;
    const strings = deckerVars.strings;
    const disabled = deckerVars.disabled;
    const userId = deckerVars.current_user_id;

    let quill = null;

    // Start of comment part
    var replyToCommentId = null;

    // Función para inicializar el envío de comentarios dentro del contexto dado
    function initializeSendComments(context) {
        // Comprobar si ya se ha inicializado en este contexto
        if ( context.dataset && context.dataset.sendCommentsInitialized === 'true') return;
        if ( context.dataset) context.dataset.sendCommentsInitialized = 'true';

        const commentTextArea = context.querySelector('#comment-text');
        const submitButton = context.querySelector('#submit-comment');

        if (commentTextArea && submitButton) {
            // Habilitar/deshabilitar el botón de enviar basado en el contenido del textarea
            commentTextArea.addEventListener('input', function() {
                submitButton.disabled = this.value.trim() === '';
            });

            // Manejar la sumisión de comentarios
            submitButton.addEventListener('click', function() {
                const commentText = commentTextArea.value;
                const parentId = replyToCommentId;

                if (commentText.trim() === '') {
                    return;
                }

                // Mostrar estado de carga
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="ri-loader-2-line ri-spin me-1"></i> Sending...';

                const formData = new FormData();
                const taskId = getTaskId();
                formData.append('action', 'add_task_comment');
                formData.append('task_id', taskId);
                formData.append('comment_content', commentText);
                formData.append('parent_id', parentId || 0);
                formData.append('nonce', nonces.task_comment_nonce);

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Limpiar el formulario
                        commentTextArea.value = '';
                        if (replyToCommentId) {
                            context.querySelector('#reply-indicator').classList.add('d-none');
                            replyToCommentId = null;
                        }

                        // Añadir el nuevo comentario a la lista
                        const commentsList = context.querySelector('#comments-list');
                        const newComment = document.createElement('div');
                        newComment.className = 'd-flex align-items-start mb-2';
                        if (parentId) {
                            newComment.style.marginLeft = '20px';
                        }

                        newComment.innerHTML = `
                            <img class="me-2 rounded-circle" src="${data.data.avatar_url}" alt="Avatar" height="32" />
                            <div class="w-100">
                                <h5 class="mt-0">${data.data.author} <small class="text-muted float-end">${data.data.date}</small></h5>
                                ${data.data.content}
                                <br />
                                <a href="javascript:void(0);" onclick="deleteComment(${data.data.comment_id});" 
                                   class="text-muted d-inline-block mt-2 comment-delete" 
                                   data-comment-id="${data.data.comment_id}">
                                    <i class="ri-delete-bin-line"></i> ${strings.delete}
                                </a>
                            </div>
                        `;

                        if (parentId) {
                            // Encontrar el comentario padre y añadir después
                            const parentComment = commentsList.querySelector(`[data-comment-id="${parentId}"]`).closest('.d-flex');
                            parentComment.after(newComment);
                        } else {
                            // Añadir al final de la lista principal
                            commentsList.appendChild(newComment);
                        }

                        // Actualizar el contador de comentarios
                        const commentCount = context.querySelector('#comment-count');
                        if (commentCount) {
                            commentCount.textContent = parseInt(commentCount.textContent) + 1;
                        }
                    } else {
                        alert(data.message || 'Error adding comment.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error adding comment. Please try again.');
                })
                .finally(() => {
                    // Reiniciar el estado del botón
                    submitButton.disabled = commentTextArea.value.trim() === '';
                    submitButton.innerHTML = '<i class="ri-chat-1-line me-1"></i> Comment';
                });
            });
        }
    }

    // Función para borrar un comentario
    function deleteComment(commentId) {
        if (!confirm(strings.confirm_delete_comment)) {
            return;
        }

        fetch(`${restUrl}comments/${commentId}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonces.wp_rest_nonce
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'trash') {
                // Encontrar y eliminar el elemento del comentario
                const commentElement = document.querySelector(`[data-comment-id="${commentId}"]`).closest('.d-flex');
                if (commentElement) {
                    commentElement.remove();

                    // Actualizar el contador de comentarios
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

    // Función para inicializar la página de tareas dentro del contexto dado
    function initializeTaskPage(context) {
        new Tablesort(context.querySelector('#user-history-table'));

        // Verificar si el task_id está presente en data-task-id
        const taskId = getTaskId();
        const taskElement = context.querySelector(`[data-task-id="${taskId}"]`);
        if (taskElement) {
            console.log('Task ID found in data-task-id:', taskElement.getAttribute('data-task-id'));
        } else {
            console.log('Task ID not found in data-task-id');
        }

        if (context.querySelector('#editor')) {
            if (quill === null) {
                // Registrar el módulo HTML Edit Button
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
        
        }

        // Inicializar Choices.js para los selectores de asignados y etiquetas
        if (context.querySelector('#task-assignees')) {
            assigneesSelect = new Choices(context.querySelector('#task-assignees'), { 
                removeItemButton: true,
                allowHTML: true,
                searchEnabled: true,
                shouldSort: true,
            });
        
            // Agregar el evento de cambio para los asignados
            assigneesSelect.passedElement.element.addEventListener('change', handleAssigneesChange);
        }

        if (context.querySelector('#task-labels')) {
            labelsSelect = new Choices(context.querySelector('#task-labels'), { 
                removeItemButton: true, 
                allowHTML: true,
                searchEnabled: true,
                shouldSort: true,
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
        
        // Mostrar/ocultar la etiqueta "High" para prioridad máxima
        var taskMaxPriority = context.querySelector('#task-max-priority');
        if (taskMaxPriority) {
            taskMaxPriority.addEventListener('change', function () {
                togglePriorityLabel(this);
            });
        }

        // Cambios estéticos al seleccionar/deseleccionar el checkbox de tareas
        const taskTodayCheckbox = context.querySelector('#task-today');
        if (taskTodayCheckbox) {
            taskTodayCheckbox.addEventListener('change', handleTaskTodayChange);
        }

        const saveButton = context.querySelector('#save-task');

        // Función para habilitar el botón de guardar cuando cualquier campo cambia
        const enableSaveButton = function() {
            saveButton.disabled = false;
        };

        const form = context.querySelector('#task-form');

        // Añadir event listeners a todos los inputs del formulario
        const inputIds = ['task-title', 'task-due-date', 'task-board', 'task-stack', 'task-author', 'task-today', 'task-max-priority'];

        inputIds.forEach(function(id) {
            const element = context.querySelector(`#${id}`);
            if (element) {
                element.addEventListener('change', enableSaveButton);
                element.addEventListener('input', enableSaveButton);
            }
        });

        // Verificar el estado inicial del checkbox de prioridad máxima y alternar la etiqueta
        var taskMaxPriorityCheck = context.querySelector('#task-max-priority');
        if (taskMaxPriorityCheck) {
            togglePriorityLabel(taskMaxPriorityCheck);
        }
        
        // Para el Editor Quill
        if (quill) {
            quill.on('text-change', function() {
                saveButton.disabled = false;
            });
        }

        // Para los selectores de Choices.js
        if (assigneesSelect) {
            assigneesSelect.passedElement.element.addEventListener('change', enableSaveButton);
        }
        if (labelsSelect) {
            labelsSelect.passedElement.element.addEventListener('change', enableSaveButton);
        }        

        document.querySelectorAll('.archive-task').forEach((element) => {

          element.removeEventListener('click', archiveTaskHandler);
          element.addEventListener('click', archiveTaskHandler);

        });

    }

    // Función para manejar cambios en el checkbox "task-today"
    function handleTaskTodayChange(event) {
        // Si el usuario marca una tarea para hoy
        if (event.target.checked) {
            // Verificar si el usuario ya está seleccionado
            const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
            // Y si no está seleccionando
            if (!selectedValues.includes(userId.toString())) {
                // Lo selecciona
                assigneesSelect.setChoiceByValue(userId.toString());
            }
        }
        // Si se desmarca, no hacer nada
    }

    // Función para manejar cambios en los asignados
    function handleAssigneesChange(event) {
        // Si el usuario se quita de los asignados a la tarea
        const selectedValues = assigneesSelect.getValue(true); // Obtener valores como array de números
        if (!selectedValues.includes(userId.toString())) {
            const taskTodayCheckbox = document.querySelector('#task-today');
            // Y tiene la tarea marcada para hoy
            if (taskTodayCheckbox && taskTodayCheckbox.checked) {
                // La desmarca
                taskTodayCheckbox.checked = false;
            }
        }
    }

    // Función para subir adjuntos
    function uploadAttachment(file, context) {
        var formData = new FormData();
        const taskId = getTaskId();
        formData.append('action', 'upload_task_attachment');
        formData.append('task_id', taskId);
        formData.append('attachment', file);
        formData.append('nonce', nonces.upload_attachment_nonce);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    // Añadir el nuevo adjunto a la lista en la interfaz
                    addAttachmentToList(response.data.attachment_id, response.data.attachment_url, response.data.attachment_title, response.data.attachment_extension, context);
                    // Limpiar el input de archivo
                    context.querySelector('#file-input').value = '';
                } else {
                    alert(response.data.message || strings.error_uploading_attachment);
                }
            } else {
                console.error('Server error.');
                alert('An error occurred while uploading the attachment.');
            }
        };

        xhr.onerror = function() {
            console.error('Request error.');
            alert('An error occurred while uploading the attachment.');
        };

        xhr.send(formData);
    }

    // Función para añadir un adjunto a la lista
    function addAttachmentToList(attachmentId, attachmentUrl, attachmentTitle, attachmentExtension, context) {
        var attachmentsList = context.querySelector('#attachments-list');
        var li = document.createElement('li');
        var attachmentFilename = `${attachmentTitle}.${attachmentExtension}`; 

        li.className = 'list-group-item d-flex justify-content-between align-items-center';
        li.setAttribute('data-attachment-id', attachmentId);

        li.innerHTML = `
            <a href="${attachmentUrl}" target="_blank" download="${attachmentFilename}">
                ${attachmentFilename} <i class="bi bi-box-arrow-up-right ms-2"></i>
            </a>
            <div>
                <button type="button" class="btn btn-sm btn-danger me-2 remove-attachment"${disabled ? ' disabled' : ''}>${strings.delete}</button>
            </div>
        `;

        attachmentsList.appendChild(li);

        // Actualizar el contador de adjuntos
        updateAttachmentCount(context, 1); // Incrementar en 1    
    }

    // Event delegation para eliminar adjuntos
    document.addEventListener('click', function(event) {
        if (event.target && event.target.classList.contains('remove-attachment')) {
            var listItem = event.target.closest('li');
            var attachmentId = listItem.getAttribute('data-attachment-id');
            const modalElement = document.querySelector('.task-modal.show'); // Selecciona el modal abierto, o null si no está en un modal
            if (modalElement) {
                deleteAttachment(attachmentId, listItem, modalElement);
            } else {
                deleteAttachment(attachmentId, listItem, document); // Asume que está cargado directamente en la página
            }
        }
    });

    // Función para eliminar un adjunto
    function deleteAttachment(attachmentId, listItem, context) {
        if (!confirm(strings.confirm_delete_attachment)) {
            return;
        }

        var formData = new FormData();
        const taskId = getTaskId();
        formData.append('action', 'delete_task_attachment');
        formData.append('task_id', taskId);
        formData.append('attachment_id', attachmentId);
        formData.append('nonce', nonces.delete_attachment_nonce);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    // Eliminar el adjunto de la lista en la interfaz
                    listItem.remove();

                    // Actualizar el contador de adjuntos
                    updateAttachmentCount(context, -1); // Decrementar en 1                
                } else {
                    alert(response.data.message || 'Error deleting attachment.');
                }
            } else {
                console.error('Server error.');
                alert('An error occurred while deleting the attachment.');
            }
        };

        xhr.onerror = function() {
            console.error('Request error.');
            alert('An error occurred while deleting the attachment.');
        };

        xhr.send(formData);
    }

    // Función para actualizar el contador de adjuntos
    function updateAttachmentCount(context, change) {
        var attachmentCountElement = context.querySelector('#attachment-count');
        if (attachmentCountElement) {
            var currentCount = parseInt(attachmentCountElement.textContent, 10) || 0;
            var newCount = currentCount + change;
            attachmentCountElement.textContent = newCount;
        }
    }

    // Función para alternar la etiqueta de prioridad máxima
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

    // Función para enviar el formulario vía AJAX
    function sendFormByAjax(event) {
        event.preventDefault();

        const form = document.getElementById('task-form'); // Fallback

        // const form = event.target; // Obtiene el formulario que disparó el evento

        // Remueve la clase 'was-validated' previamente
        form.classList.remove('was-validated');

        // Verifica la validez del formulario
        if (!form.checkValidity()) {
            event.stopPropagation();
            form.classList.add('was-validated');
            return;
        }

        // Si el formulario es válido, procede con el envío vía AJAX
        const selectedAssigneesValues = assigneesSelect.getValue().map(item => parseInt(item.value, 10));
        const selectedLabelsValues = labelsSelect.getValue().map(item => parseInt(item.value, 10));

        // Recopila los datos del formulario
        const formData = {
            action: 'save_decker_task',
            nonce: nonces.save_decker_task_nonce,
            task_id: form.querySelector('input[name="task_id"]').value,
            title: form.querySelector('#task-title').value,
            due_date: form.querySelector('#task-due-date').value,
            board: form.querySelector('#task-board').value,
            stack: form.querySelector('#task-stack').value,
            author: form.querySelector('#task-author').value,
            assignees: selectedAssigneesValues,
            labels: selectedLabelsValues,
            description: quill.root.innerHTML,
            max_priority: form.querySelector('#task-max-priority').checked ? 1 : 0,
            mark_for_today: form.querySelector('#task-today').checked ? 1 : 0,
        };

        // Envía la solicitud AJAX
        const xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 400) {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    const modalElement = document.querySelector('.task-modal.show'); // Selecciona el modal abierto, o null si no está en un modal
                    if (modalElement) {
                        var modalInstance = bootstrap.Modal.getInstance(modalElement);
                        if (modalInstance) {
                            modalInstance.hide();
                        }

                        // Recargar la página si la solicitud fue exitosa
                        location.reload();   
                    } else {
                        // Redirecciona o actualiza según la respuesta
                        window.location.href = `${homeUrl}?decker_page=task&id=${response.data.task_id}`;
                    }

                } else {
                    alert(response.data.message || 'Error al guardar la tarea.');
                }
            } else {
                console.error(strings.server_response_error);
                alert(strings.an_error_occurred_saving_task);
            }
        };

        xhr.onerror = function() {
            console.error(strings.request_error);
            alert(strings.error_saving_task);
        };

        const encodedData = Object.keys(formData)
            .map(key => encodeURIComponent(key) + '=' + encodeURIComponent(formData[key]))
            .join('&');

        xhr.send(encodedData);
    }

    // Obtener el task_id desde el input hidden
    function getTaskId() {
        const taskIdInput = document.querySelector('input[name="task_id"]');
        if (taskIdInput) {
            return taskIdInput.value;
        } else {
            console.error('Task ID input not found');
            return null;
        }
    }

    // Exportar funciones globalmente para que puedan ser llamadas desde HTML
    window.initializeSendComments = initializeSendComments;
    window.initializeTaskPage = initializeTaskPage;
    window.sendFormByAjax = sendFormByAjax;
    window.deleteComment = deleteComment;
    window.togglePriorityLabel = togglePriorityLabel;

    // Inicializar automáticamente si el contenido está cargado directamente en la página
    document.addEventListener('DOMContentLoaded', function() {
        // Verificar si existe el formulario de tarea directamente en la página
        const taskForm = document.querySelector('#task-form');
        if (taskForm && !taskForm.closest('.task-modal')) { // Asegurarse de que no está dentro de un modal
            initializeTaskPage(document);
            initializeSendComments(document);
        }
    });

})();

