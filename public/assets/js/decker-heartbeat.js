/* global jQuery, heartbeat, Swal */
/* eslint-disable */

/**
 * Decker Heartbeat Notifications
 *
 * Handles real-time notifications, initial load, and user actions like clear-all or single clear.
 */
console.log('loading decker-heartbeat.js');

(function($) {
    'use strict';

    // Test notification.
    const sendButton = document.getElementById("sendTestNotification");
    if (sendButton) {
        sendButton.addEventListener("click", function () {
            let userOptions = '<option value="all">All Users</option>';
            
            if (deckerVars.users && Array.isArray(deckerVars.users)) {
                deckerVars.users.forEach(user => {
                    userOptions += `<option value="${user.ID}">${user.display_name} (${user.nickname ? user.nickname : 'No nickname'})</option>`;
                });
            }

            Swal.fire({
                title: 'Send Notification',
                html:
                    '<select id="notificationUser" class="swal2-input">' +
                    userOptions +
                    '</select>' +
                    '<select id="notificationType" class="swal2-input">' +
                    '<option value="task_created">Task Created</option>' +
                    '<option value="task_assigned">User Assigned</option>' +
                    '<option value="task_completed">Task Completed</option>' +
                    '<option value="task_comment">New Comment</option>' +
                    '</select>' +
                    '<input type="text" id="notificationMessage" class="swal2-input" placeholder="Enter message">',
                showCancelButton: true,
                confirmButtonText: 'Send',
                preConfirm: () => {
                    const userId = document.getElementById("notificationUser").value;
                    const type = document.getElementById("notificationType").value;
                    const message = document.getElementById("notificationMessage").value.trim();

                    if (!message) {
                        Swal.showValidationMessage('The message cannot be empty');
                        return false;
                    }

                    return { userId, type, message };
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    jQuery.post(DeckerData.ajaxUrl, {
                        action: "send_test_notification",
                        user_id: result.value.userId,
                        type: result.value.type,
                        message: result.value.message
                    }, function(response) {
                        if (response.success) {
                            Swal.fire('Sent', 'The notification has been sent', 'success');
                        } else {
                            Swal.fire('Error', 'There was an issue sending the notification', 'error');
                        }
                    });
                }
            });
        });
    }
    // End test notification.


    /**
     * Appends a new notification to the UI.
     *
     * @param {Object} notificationData Notification data with:
     * url, taskId, iconColor, iconClass, title, action, time, showAlert.
     * @param {Boolean} showAlert Whether we should trigger a SweetAlert or browser notification.
     */
    function addNotification(notificationData, showAlert) {
        var notificationList = document.getElementById('notification-list');
        var notificationBadge = document.querySelector('.noti-icon-badge');

        if (!notificationList) {
            console.error('Notification list element not found.');
            return;
        }

        // Show the badge if it was hidden
        if (notificationBadge && notificationBadge.style.display === 'none') {
            notificationBadge.style.display = '';
        }

        var notificationLink = document.createElement('a');
        notificationLink.href = notificationData.url || '#';
        notificationLink.title = notificationData.title || 'Notification';

        // If it has a task ID, we assume it opens the modal.
        if (notificationData.taskId) {
            notificationLink.dataset.bsToggle = 'modal';
            notificationLink.dataset.bsTarget = '#task-modal';
            notificationLink.dataset.taskId = notificationData.taskId;
        }
        notificationLink.classList.add('dropdown-item', 'notify-item');

        var notificationDiv = document.createElement('div');
        notificationDiv.classList.add('d-flex');

        var iconDiv = document.createElement('div');
        iconDiv.classList.add('notify-icon', 'bg-' + (notificationData.iconColor || 'primary'));
        var icon = document.createElement('i');
        icon.classList.add(notificationData.iconClass || 'ri-information-line', 'fs-18');
        iconDiv.appendChild(icon);

        var contentDiv = document.createElement('div');
        contentDiv.classList.add('notification-content');

        var titleDiv = document.createElement('h5');
        titleDiv.classList.add('fw-semibold');
        titleDiv.textContent = notificationData.title || 'New Notification';

        var actionDiv = document.createElement('small');
        actionDiv.classList.add('notification-action');

        var actionText = document.createElement('span');
        actionText.textContent = notificationData.action || '';

        var timeSpan = document.createElement('small');
        timeSpan.classList.add('text-muted');
        // timeSpan.textContent = notificationData.time || '';

        timeSpan.textContent = formatNotificationTime(notificationData.time);


        actionDiv.appendChild(actionText);
        actionDiv.appendChild(timeSpan);

        contentDiv.appendChild(titleDiv);
        contentDiv.appendChild(actionDiv);


    var closeButton = document.createElement('button');
    closeButton.classList.add('btn-close', 'position-absolute', 'top-50', 'end-0', 'translate-middle-y', 'me-2');
    closeButton.setAttribute('aria-label', 'Close');
    closeButton.dataset.taskId = notificationData.taskId;

    closeButton.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation(); // Prevent opening the task modal
        removeSingleNotification(notificationData.taskId);
        notificationItem.remove();
    });


        notificationDiv.appendChild(iconDiv);
        notificationDiv.appendChild(contentDiv);

        notificationLink.appendChild(notificationDiv);
        notificationList.prepend(notificationLink);

        // Show the badge
        if (notificationBadge) {
            notificationBadge.style.display = 'inline-block';
        }

        // Trigger a SweetAlert if requested
        if (showAlert && typeof Swal !== 'undefined') {
            // Swal.fire({
            //     title: notificationData.title || 'New Notification',
            //     text: notificationData.action || '',
            //     icon: 'info',
            //     timer: 4000,
            //     toast: true,
            //     position: 'top-end',
            //     showConfirmButton: false
            // });

            Swal.fire({
                html: `<div style="display: flex; align-items: center;">
                         <div class="notify-icon bg-${notificationData.iconColor}" 
                              style="height: 36px; width: 36px; line-height: 36px; text-align: center; border-radius: 50%; color: #fff; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                           <i class="${notificationData.iconClass || 'ri-information-line'} fs-18"></i>
                         </div>
                         <div>
                           <h3 class="fw-semibold" style="margin: 0;">${notificationData.title || 'New Notification'}</h3>
                           <p style="margin: 0;">${notificationData.action || ''}</p>
                         </div>
                       </div>`,
                toast: true,
                position: 'top-end',
                showCloseButton: true,
                showConfirmButton: false,
                timer: 4000
            });

            
        }

        // Trigger a browser notification if requested
        if (
            showAlert &&
            ('Notification' in window) &&
            Notification.permission === 'granted'
        ) {
            new Notification(
                'Decker', 
                { body: notificationData.title || 'New Notification' }
            );
        }
    }

function formatNotificationTime(timeString) {
    const date = new Date(timeString);
    const now = new Date();

    // Obtener componentes de la fecha
    const hours = date.getHours().toString().padStart(2, '0');
    const minutes = date.getMinutes().toString().padStart(2, '0');
    const day = date.getDate().toString().padStart(2, '0');
    const month = (date.getMonth() + 1).toString().padStart(2, '0'); // Meses van de 0 a 11

    // If it's the same day, show only HH:mm
    if (date.toDateString() === now.toDateString()) {
        return `${hours}:${minutes}`;
    }

    // If it's a previous day, show DD/MM HH:mm
    return `${day}/${month} ${hours}:${minutes}`;
}


    /**
     * Loads the last 15 notifications from the server on page load.
     * Does not trigger SweetAlert or browser notifications for these items.
     */
    function loadInitialNotifications() {
        $.ajax({
            url: DeckerData.ajaxUrl, // WordPress localizes this variable in admin
            method: 'POST',
            data: {
                action: 'get_decker_notifications'
            }
        })
        .done(function(response) {
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(function(notification) {
                    addNotification(notification, false);
                });
            }
        })
        .fail(function() {
            console.error('Could not load initial notifications.');
        });
    }

    /**
     * Clears all notifications from the UI.
     */
    function clearNotificationsUI() {
        var notificationList = document.getElementById('notification-list');
        var notificationBadge = document.querySelector('.noti-icon-badge');

        if (notificationList) {
            notificationList.innerHTML = '';
        }
        if (notificationBadge) {
            notificationBadge.style.display = 'none';
        }
    }

    function removeSingleNotification(taskId) {
        if (!taskId) {
            return;
        }
        $.ajax({
            url: DeckerData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'remove_decker_notification',
                task_id: taskId
            }
        }).fail(function () {
            console.error('Failed to remove the notification from meta.');
        });
    }

    /**
     * Makes an AJAX request to clear all notifications from the server.
     */
    function clearAllNotifications() {
        $.ajax({
            url: DeckerData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'clear_decker_notifications'
            }
        })
        .done(function(response) {
            if (response.success) {
                clearNotificationsUI();
            }
        })
        .fail(function() {
            console.error('Failed to clear notifications.');
        });
    }

    /**
     * Removes a single notification from user meta if it has an ID.
     * 
     * @param {Number} taskId The task ID, or null if no ID
     */
    function removeSingleNotification(taskId) {
        // If there's no task ID, do nothing in meta
        if (!taskId) {
            return;
        }
        $.ajax({
            url: DeckerData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'remove_decker_notification',
                task_id: taskId
            }
        }).fail(function() {
            console.error('Failed to remove the notification from meta.');
        });
    }

    // On "Clear All" link:
    var clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
    if (clearAllLink) {
        clearAllLink.addEventListener('click', function() {
            clearAllNotifications();
        });
    }

    // Delegate click on each notification:
    $('#notification-list').on('click', 'a.notify-item', function() {
        var taskId = $(this).data('task-id');
        removeSingleNotification(taskId);
        // We do not remove it from UI here because it's normal for a user to see the item remain.
        // If you prefer to remove it, do:
        // $(this).remove();
        // Hide the badge if none remain:
        // if ($('#notification-list a').length === 0) {
        //     $('.noti-icon-badge').hide();
        // }
    });

    // Enviamos datos al servidor en cada latido:
    $(document).on('heartbeat-send', function(e, data) {
        data.decker_custom_data = {
            foo: 'bar',
            timestamp: Date.now()
        };
    });


    /**
     * Handle incoming Heartbeat data
     */
    $(document).on('heartbeat-tick', function(event, data) {

        console.log('Datos recibidos del heartbeat:', data); // Debug completo

        if (data.decker_notifications && Array.isArray(data.decker_notifications)) {

            console.log('Notificaciones recibidas:', data.decker_notifications);

            data.decker_notifications.forEach(function(notification) {
                // showAlert = true for new incoming notifications
                addNotification(notification, true);
            });
        }
    });

    /**
     * Request permission for browser notifications if not denied
     */
    if ('Notification' in window && Notification.permission !== 'denied') {
        Notification.requestPermission();
    }

    /**
     * Init logic on DOM ready
     */
    $(function() {
        loadInitialNotifications(); // Load the last 15 notifications
    });

})(jQuery);

