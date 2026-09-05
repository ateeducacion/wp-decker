/* global jQuery, heartbeat, Swal, DeckerData, deckerVars */
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

    var notificationReadStateKey = 'decker_notification_read_'
        + (DeckerData.userId || 0);
    var CONNECTION_BANNER_ID = 'decker-connection-banner';
    var serverDown = false;
    var sessionExpired = false;

    /**
     * Returns a localized Decker UI string.
     *
     * @param {string} key String key from deckerVars.strings.
     * @return {string} Localized string, or an empty string when missing.
     */
    function deckerString(key) {
        return (typeof deckerVars !== 'undefined'
            && deckerVars.strings
            && deckerVars.strings[key]) || '';
    }

    /**
     * Confirms recovery with the toast style already used elsewhere in Decker.
     */
    function showConnectionRestoredToast() {
        if (typeof Swal === 'undefined' || !deckerString('connection_restored')) {
            return;
        }

        Swal.fire({
            icon: 'success',
            text: deckerString('connection_restored'),
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
    }

    /**
     * Shows, replaces or clears the connection banner.
     *
     * An offline browser, an unreachable server and an expired session all mean
     * "your changes cannot be saved right now", so they share a single banner and
     * differ only in wording, colour and whether a log-in link is offered. The
     * current state lives in the element itself, so calling this repeatedly with
     * the same state is a no-op.
     *
     * @param {string} state 'offline', 'server', 'session', or '' when all is well.
     */
    function setConnectionState(state) {
        var banner = document.getElementById(CONNECTION_BANNER_ID);
        var previous = banner ? banner.getAttribute('data-state') : '';
        var link;
        var variant;

        if (state === previous) {
            return;
        }

        if (banner) {
            banner.remove();
        }

        if (!state) {
            // Logging back in is not a connection problem; do not claim it was.
            if ('session' !== previous) {
                showConnectionRestoredToast();
            }

            return;
        }

        variant = {
            offline: { css: 'alert-warning', icon: 'ri-wifi-off-line', text: 'connection_offline' },
            server: { css: 'alert-danger', icon: 'ri-server-line', text: 'connection_lost' },
            session: { css: 'alert-danger', icon: 'ri-shield-user-line', text: 'session_expired_message' }
        }[state];

        banner = document.createElement('div');
        banner.id = CONNECTION_BANNER_ID;
        banner.setAttribute('data-state', state);
        banner.setAttribute('role', 'alert');
        banner.className = 'alert ' + variant.css
            + ' d-flex align-items-center gap-2 position-fixed top-0 start-50'
            + ' translate-middle-x mt-3 shadow';
        banner.style.zIndex = '1080';
        banner.innerHTML = '<i class="' + variant.icon + ' fs-4" aria-hidden="true"></i><span></span>';
        banner.querySelector('span').textContent = deckerString(variant.text);

        if ('session' === state && typeof deckerVars !== 'undefined' && deckerVars.login_url) {
            link = document.createElement('a');
            link.className = 'alert-link text-nowrap';
            link.href = deckerVars.login_url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = deckerString('log_in_again');
            banner.appendChild(link);
        }

        document.body.appendChild(banner);
    }

    /**
     * Renders the worst problem currently known, or clears the banner.
     *
     * Each signal owns its own flag and every handler re-renders from all of
     * them, so recovering from one problem cannot hide another one that is
     * still true. Being offline outranks an unreachable server, which outranks
     * an expired session: there is no point offering a log-in link to a browser
     * that cannot reach the network. Whether the browser has a network is not
     * tracked here, because the browser already tracks it.
     */
    function renderConnectionState() {
        setConnectionState(
            false === navigator.onLine ? 'offline'
                : serverDown ? 'server'
                    : sessionExpired ? 'session' : ''
        );
    }

    // The browser knows it has no network before any request is attempted.
    $(window).on('offline', renderConnectionState);
    $(window).on('online', renderConnectionState);

    // Heartbeat already retried and gave up, so this is the server, not a blip.
    $(document).on('heartbeat-connection-lost', function(event, error, status) {
        console.warn('Heartbeat connection lost:', error, status);
        serverDown = true;
        renderConnectionState();
    });

    // Deliberately does not render: core triggers this from the same
    // clearErrorState() call that is about to trigger heartbeat-tick for this
    // very response, and that tick renders knowing whether the session survived
    // the outage. Rendering here would announce "connection restored" a
    // microsecond before the session banner appeared.
    $(document).on('heartbeat-connection-restored', function() {
        serverDown = false;
    });

    function getStoredReadNotifications() {
        var parsedNotifications;
        var storedNotifications;

        try {
            storedNotifications = window.localStorage.getItem(
                notificationReadStateKey
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
                notificationReadStateKey,
                JSON.stringify(notificationIds)
            );
        } catch (error) {
            console.error(
                'Could not store notification state for key:',
                notificationReadStateKey,
                error
            );
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
            window.localStorage.removeItem(notificationReadStateKey);
        } catch (error) {
            console.error(
                'Could not clear notification state for key:',
                notificationReadStateKey,
                error
            );
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
        notificationItem.classList.toggle('notify-item-read', isRead);
        notificationItem.classList.toggle('notify-item-unread', !isRead);
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

        actionDiv.appendChild(actionText);
        actionDiv.appendChild(timeSpan);

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
            'btn-close',
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
                    // The response is ordered newest first and addNotification()
                    // prepends, so iterate a reversed copy to keep the newest
                    // notification at the top of the panel.
                    response.data.slice().reverse().forEach(function(notification) {
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

        // WordPress reports this on every tick, logged in or not: wp_auth_check()
        // is filtered onto both heartbeat_send and heartbeat_nopriv_send. A lost
        // session therefore looks like a normal, successful response.
        sessionExpired = false === data['wp-auth-check'];
        renderConnectionState();

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
