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

    var notificationStorageKey = 'decker_notification_read_'
        + (DeckerData.userId || 0);

    function getStoredReadNotifications() {
        var parsedNotifications;
        var storedNotifications;

        try {
            storedNotifications = window.localStorage.getItem(
                notificationStorageKey
            );
            parsedNotifications = storedNotifications
                ? JSON.parse(storedNotifications)
                : [];
        } catch (error) {
            return [];
        }

        return Array.isArray(parsedNotifications) ? parsedNotifications : [];
    }

    function storeReadNotifications(notificationIds) {
        try {
            window.localStorage.setItem(
                notificationStorageKey,
                JSON.stringify(notificationIds)
            );
        } catch (error) {
            console.error('Could not store notification state.', error);
        }
    }

    function isNotificationRead(notificationId) {
        if (!notificationId) {
            return false;
        }

        return getStoredReadNotifications().indexOf(notificationId) !== -1;
    }

    function markNotificationAsRead(notificationId) {
        var storedNotifications;

        if (!notificationId || isNotificationRead(notificationId)) {
            return;
        }

        storedNotifications = getStoredReadNotifications();
        storedNotifications.push(notificationId);
        storeReadNotifications(storedNotifications);
    }

    function removeStoredNotificationState(notificationId) {
        var filteredNotifications;

        if (!notificationId) {
            return;
        }

        filteredNotifications = getStoredReadNotifications().filter(
            function(storedId) {
                return storedId !== notificationId;
            }
        );

        storeReadNotifications(filteredNotifications);
    }

    function clearStoredNotificationState() {
        try {
            window.localStorage.removeItem(notificationStorageKey);
        } catch (error) {
            console.error('Could not clear notification state.', error);
        }
    }

    function updateNotificationBadge() {
        var notificationBadge = document.querySelector('.noti-icon-badge');
        var hasNotifications = document.querySelectorAll(
            '#notification-list .notify-item'
        ).length > 0;

        if (notificationBadge) {
            notificationBadge.style.display = hasNotifications
                ? 'inline-block'
                : 'none';
        }
    }

    function setNotificationState(notificationItem, isRead) {
        var notificationState = notificationItem.querySelector(
            '.notification-state-badge'
        );

        notificationItem.classList.toggle('notify-item-read', isRead);
        notificationItem.classList.toggle('notify-item-unread', !isRead);

        if (notificationState) {
            notificationState.textContent = isRead
                ? DeckerData.labels.read
                : DeckerData.labels.new;
        }
    }

    /**
     * Appends a new notification to the UI.
     *
     * @param {Object} notificationData Notification data with:
     * url, taskId, iconColor, iconClass, title, action, time, showAlert.
     * @param {Boolean} showAlert Whether we should trigger a SweetAlert or browser notification.
     */
    function addNotification(notificationData, showAlert) {
        var actionDiv = document.createElement('small');
        var actionText = document.createElement('span');
        var contentDiv = document.createElement('div');
        var deleteButton = document.createElement('button');
        var icon = document.createElement('i');
        var iconDiv = document.createElement('div');
        var notificationDiv = document.createElement('div');
        var notificationId = notificationData.notificationId || '';
        var notificationIsRead = isNotificationRead(notificationId);
        var notificationItem = document.createElement('div');
        var notificationLink = document.createElement('a');
        var notificationList = document.getElementById('notification-list');
        var stateBadge = document.createElement('span');
        var timeSpan = document.createElement('small');
        var titleDiv = document.createElement('h5');

        if (!notificationList) {
            console.error('Notification list element not found.');
            return;
        }

        notificationItem.classList.add('dropdown-item', 'notify-item');
        notificationItem.dataset.notificationId = notificationId;
        notificationItem.dataset.taskId = notificationData.taskId || '';
        notificationItem.dataset.notificationType = notificationData.type || '';

        notificationLink.href = notificationData.url || '#';
        notificationLink.title = notificationData.title || 'Notification';
        notificationLink.classList.add(
            'notify-item-link',
            'text-decoration-none'
        );

        if (notificationData.taskId) {
            notificationLink.dataset.bsToggle = 'modal';
            notificationLink.dataset.bsTarget = '#task-modal';
            notificationLink.dataset.taskId = notificationData.taskId;
        }

        notificationDiv.classList.add('d-flex');

        iconDiv.classList.add(
            'notify-icon',
            'bg-' + (notificationData.iconColor || 'primary')
        );
        icon.classList.add(
            notificationData.iconClass || 'ri-information-line',
            'fs-18'
        );
        iconDiv.appendChild(icon);

        contentDiv.classList.add('notification-content');

        titleDiv.classList.add('fw-semibold');
        titleDiv.textContent = notificationData.title || 'New Notification';

        actionDiv.classList.add('notification-action');

        actionText.textContent = notificationData.action || '';

        timeSpan.classList.add('text-muted');
        timeSpan.textContent = formatNotificationTime(notificationData.time);

        stateBadge.classList.add('notification-state-badge');

        actionDiv.appendChild(actionText);
        actionDiv.appendChild(timeSpan);
        actionDiv.appendChild(stateBadge);

        contentDiv.appendChild(titleDiv);
        contentDiv.appendChild(actionDiv);

        notificationDiv.appendChild(iconDiv);
        notificationDiv.appendChild(contentDiv);

        notificationLink.appendChild(notificationDiv);
        notificationLink.addEventListener('click', function() {
            if (!notificationIsRead) {
                markNotificationAsRead(notificationId);
                notificationIsRead = true;
                setNotificationState(notificationItem, true);
            }
        });

        deleteButton.type = 'button';
        deleteButton.classList.add(
            'btn',
            'btn-link',
            'btn-sm',
            'text-muted',
            'notify-item-delete'
        );
        deleteButton.setAttribute(
            'aria-label',
            DeckerData.labels.delete_notification
        );
        deleteButton.setAttribute(
            'title',
            DeckerData.labels.delete_notification
        );
        deleteButton.innerHTML =
            '<i class="ri-delete-bin-line" aria-hidden="true"></i>';

        deleteButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            removeSingleNotification(
                notificationId,
                notificationData.taskId,
                notificationData.type
            ).done(function() {
                removeStoredNotificationState(notificationId);
                notificationItem.remove();
                updateNotificationBadge();
            });
        });

        notificationItem.appendChild(notificationLink);
        notificationItem.appendChild(deleteButton);
        notificationList.prepend(notificationItem);

        setNotificationState(notificationItem, notificationIsRead);
        updateNotificationBadge();

        if (showAlert && typeof Swal !== 'undefined') {
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

        const hours = date.getHours().toString().padStart(2, '0');
        const minutes = date.getMinutes().toString().padStart(2, '0');
        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');

        if (date.toDateString() === now.toDateString()) {
            return `${hours}:${minutes}`;
        }

        return `${day}/${month} ${hours}:${minutes}`;
    }

    /**
     * Loads the last 15 notifications from the server on page load.
     * Does not trigger SweetAlert or browser notifications for these items.
     */
    function loadInitialNotifications() {
        $.ajax({
            url: DeckerData.ajaxUrl,
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

        if (notificationList) {
            notificationList.innerHTML = '';
        }

        clearStoredNotificationState();
        updateNotificationBadge();
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
     * Removes a single notification from user meta.
     *
     * @param {string} notificationId Notification identifier.
     * @param {number} taskId Task identifier.
     * @param {string} type Notification type.
     * @return {jqXHR} AJAX promise.
     */
    function removeSingleNotification(notificationId, taskId, type) {
        return $.ajax({
            url: DeckerData.ajaxUrl,
            method: 'POST',
            data: {
                action: 'remove_decker_notification',
                notification_id: notificationId || '',
                task_id: taskId || 0,
                type: type || ''
            }
        }).fail(function() {
            console.error('Failed to remove the notification from meta.');
        });
    }

    var clearAllLink = document.querySelector('.js-clear-notifications');
    if (clearAllLink) {
        clearAllLink.addEventListener('click', function(event) {
            event.preventDefault();
            clearAllNotifications();
        });
    }

    // Send data to the server on each heartbeat:
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

        console.log('Heartbeat data received:', data); // Full debug

        if (data.decker_notifications && Array.isArray(data.decker_notifications)) {

            console.log('Notifications received:', data.decker_notifications);

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
