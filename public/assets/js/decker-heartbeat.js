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
        timeSpan.textContent = notificationData.time || '';

        actionDiv.appendChild(actionText);
        actionDiv.appendChild(timeSpan);

        contentDiv.appendChild(titleDiv);
        contentDiv.appendChild(actionDiv);

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
                         <div style="margin-right: 10px;">
                           <i class="${notificationData.iconClass || 'ri-information-line'}" style="font-size: 24px;"></i>
                         </div>
                         <div>
                           <h3>${notificationData.title || 'New Notification'}</h3>
                           <p>${notificationData.action || ''}</p>
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

//Ejemplo de uso de la función
// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary',
//     iconClass: 'ri-message-3-line',
//     title: 'Este es un título de notificación muy largo para probar el truncamiento',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Título corto',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// });


// //Ejemplo de uso de la función
// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary', // primary, warning, success, etc.
//     iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
//     title: 'Implementar el nuevo diseño',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Revisar la documentación',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'success',
//     iconClass: 'ri-checkbox-circle-line',
//     title: 'Probar la API:',
//     action: 'Tarea Completada',
//     time: 'Hace 2 minutos'
// });


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

// /* eslint-disable */
// /* global jQuery, heartbeat */

// /**
//  * Decker Heartbeat Notifications
//  *
//  * Handles real-time notifications received via the WordPress Heartbeat API.
//  */
// console.log('loading heartbeat.js');

// (function($) {
//     'use strict';

//     /**
//      * Appends a new notification to the UI.
//      *
//      * @param {Object} notificationData - Data with keys: url, taskId, iconColor, iconClass, title, action, time.
//      */
//     function addNotification(notificationData) {
//         const notificationList = document.getElementById('notification-list');
//         const notificationBadge = document.querySelector('.noti-icon-badge');

//         if (!notificationList) {
//             console.error("Notification list element not found.");
//             return;
//         }

//         // Make badge visible if it was hidden
//         if (notificationBadge && notificationBadge.style.display === 'none') {
//             notificationBadge.style.display = '';
//         }

//         const notificationLink = document.createElement('a');
//         notificationLink.href = notificationData.url;
//         notificationLink.title = notificationData.title;
//         notificationLink.dataset.bsToggle = 'modal';
//         notificationLink.dataset.bsTarget = '#task-modal';
//         notificationLink.dataset.taskId = notificationData.taskId;
//         notificationLink.classList.add('dropdown-item', 'notify-item');

//         const notificationDiv = document.createElement('div');
//         notificationDiv.classList.add('d-flex');

//         const iconDiv = document.createElement('div');
//         iconDiv.classList.add('notify-icon', 'bg-' + notificationData.iconColor);
//         const icon = document.createElement('i');
//         icon.classList.add(notificationData.iconClass, 'fs-18');
//         iconDiv.appendChild(icon);

//         const contentDiv = document.createElement('div');
//         contentDiv.classList.add('notification-content');

//         const titleDiv = document.createElement('h5');
//         titleDiv.classList.add('fw-semibold');
//         titleDiv.textContent = notificationData.title;

//         const actionDiv = document.createElement('small');
//         actionDiv.classList.add('notification-action');

//         const actionText = document.createElement('span');
//         actionText.textContent = notificationData.action;

//         const timeSpan = document.createElement('small');
//         timeSpan.classList.add('text-muted');
//         timeSpan.textContent = notificationData.time;

//         actionDiv.appendChild(actionText);
//         actionDiv.appendChild(timeSpan);

//         contentDiv.appendChild(titleDiv);
//         contentDiv.appendChild(actionDiv);
//         notificationDiv.appendChild(iconDiv);
//         notificationDiv.appendChild(contentDiv);
//         notificationLink.appendChild(notificationDiv);

//         notificationList.prepend(notificationLink);

//         // Ensure the badge is visible
//         if (notificationBadge) {
//             notificationBadge.style.display = 'inline-block';
//         }
//     }

// //Ejemplo de uso de la función
// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary',
//     iconClass: 'ri-message-3-line',
//     title: 'Este es un título de notificación muy largo para probar el truncamiento',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Título corto',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// });


// //Ejemplo de uso de la función
// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary', // primary, warning, success, etc.
//     iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
//     title: 'Implementar el nuevo diseño',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Revisar la documentación',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// });

// addNotification({
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'success',
//     iconClass: 'ri-checkbox-circle-line',
//     title: 'Probar la API:',
//     action: 'Tarea Completada',
//     time: 'Hace 2 minutos'
// });

//     /**
//      * Clears all notifications from the UI.
//      */
//     function clearNotifications() {
//         const notificationList = document.getElementById('notification-list');
//         const notificationBadge = document.querySelector('.noti-icon-badge');

//         if (notificationList) {
//             notificationList.innerHTML = '';
//         }
//         if (notificationBadge) {
//             notificationBadge.style.display = 'none';
//         }
//     }

//     // Listen for "Clear All" link to remove notifications from the UI
//     const clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
//     if (clearAllLink) {
//         clearAllLink.addEventListener('click', clearNotifications);
//     }

//     /**
//      * Maps notification types to their icon classes and colors.
//      */
//     const iconMap = {
//         task_created:   { icon: 'ri-add-line',           color: 'primary' },
//         task_assigned:  { icon: 'ri-user-add-line',      color: 'warning' },
//         task_completed: { icon: 'ri-checkbox-circle-line', color: 'success' },
//         task_comment:   { icon: 'ri-message-3-line',     color: 'info' }
//     };

//     // Enviamos datos al servidor en cada latido:
//     // $(document).on('heartbeat-send', function(e, data) {
//     //     data.decker_custom_data = {
//     //         foo: 'bar',
//     //         timestamp: Date.now()
//     //     };
//     // });


//     // Handle incoming Heartbeat data
//     $(document).on('heartbeat-tick', function(event, data) {
        
//         console.log('Datos recibidos del heartbeat:', data); // Debug completo

//         if (data.decker_notifications && Array.isArray(data.decker_notifications)) {
           
//             console.log('Notificaciones recibidas:', data.decker_notifications);

//             data.decker_notifications.forEach(function(notification) {
//                 // Derive icon and color based on type
//                 const mapping = iconMap[ notification.type ] || { icon: 'ri-information-line', color: 'primary' };

//                 addNotification({
//                     url:       notification.url || '#',
//                     taskId:    notification.task_id,
//                     iconColor: mapping.color,
//                     iconClass: mapping.icon,
//                     title:     notification.title || 'New Notification',
//                     action:    notification.action || 'Action',
//                     time:      notification.time || 'Just now'
//                 });

//                 // Optionally trigger a browser notification if user allowed it
//                 if (("Notification" in window) && Notification.permission === "granted") {
//                     new Notification("Decker", { body: notification.title || 'New Notification' });
//                 }
//             });
//         }
//     });

//     // Request permission for browser notifications
//     if ("Notification" in window && Notification.permission !== "denied") {
//         Notification.requestPermission();
//     }




//    const sendButton = document.getElementById("sendTestNotification");
//     if (sendButton) {
//         sendButton.addEventListener("click", function () {
//             const message = document.getElementById("testNotificationMessage").value.trim();
//             if (!message) {
//                 alert("Please enter a notification message.");
//                 return;
//             }

//             // Enviar notificación a todos los usuarios (simulación)
//             jQuery.post('http://localhost:8888/wp-admin/admin-ajax.php', {
//                 action: "send_test_notification",
//                 message: message
//             }, function(response) {
//                 if (response.success) {
//                     alert("Test notification sent.");
//                 } else {
//                     alert("Error sending notification.");
//                 }
//             });

//             // Cerrar modal después de enviar
//             document.getElementById("testNotificationModal").querySelector(".btn-close").click();
//         });
//     }



// })(jQuery);


// // console.log('loading heartbeat.js');


// // // Configurar el manejo de notificaciones via Heartbeat API
// // (function($) {

// // function addNotification(notificationData) {
	
// //     const notificationList = document.getElementById('notification-list');
// //     const notificationBadge = document.querySelector('.noti-icon-badge');

// //     if (!notificationList) {
// //         console.error("Notification list element not found.");
// //         return;
// //     }

// //         if (notificationBadge && notificationBadge.style.display === 'none') {
// //         notificationBadge.style.display = '';
// //     }

// //     const notificationLink = document.createElement('a');
// //     notificationLink.href = notificationData.url;
// //     notificationLink.title = notificationData.title;

// //     notificationLink.dataset.bsToggle = 'modal';
// //     notificationLink.dataset.bsTarget = '#task-modal';
// //     notificationLink.dataset.taskId = notificationData.taskId;
// //     notificationLink.classList.add('dropdown-item', 'notify-item');

// //     const notificationDiv = document.createElement('div');
// //     notificationDiv.classList.add('d-flex');

// //     const iconDiv = document.createElement('div');
// //     iconDiv.classList.add('notify-icon', `bg-${notificationData.iconColor}`);
// //     const icon = document.createElement('i');
// //     icon.classList.add(notificationData.iconClass, 'fs-18');
// //     iconDiv.appendChild(icon);

// //     const contentDiv = document.createElement('div');
// //     contentDiv.classList.add('notification-content'); // Nuevo div para el contenido

// //     const titleDiv = document.createElement('h5');
// //     titleDiv.classList.add('fw-semibold');
// //     titleDiv.textContent = notificationData.title;

// //     const actionDiv = document.createElement('small');
// //     actionDiv.classList.add('notification-action');

// //     const actionText = document.createElement('span');
// //     actionText.textContent = notificationData.action;

// //     const timeSpan = document.createElement('small');
// //     timeSpan.classList.add('text-muted');
// //     timeSpan.textContent = notificationData.time;

// //     actionDiv.appendChild(actionText);
// //     actionDiv.appendChild(timeSpan);


// //     contentDiv.appendChild(titleDiv);
// //     contentDiv.appendChild(actionDiv);


// //     notificationDiv.appendChild(iconDiv);
// //     notificationDiv.appendChild(contentDiv);
// //     notificationLink.appendChild(notificationDiv);
// //     notificationList.prepend(notificationLink);

// //     // Show the red dot on notification badge.
// // 	// const notificationBadge = document.querySelector('.noti-icon-badge');
// // 	if (notificationBadge) {
// // 		notificationBadge.style.display = 'inline-block';
// // 	}

// // }

// // //Ejemplo de uso de la función
// // const notification1 = {
// //     url: 'http://localhost:8888/?decker_page=task&id=7',
// //     taskId: 7,
// //     iconColor: 'primary',
// //     iconClass: 'ri-message-3-line',
// //     title: 'Este es un título de notificación muy largo para probar el truncamiento',
// //     action: 'Tarea Creada',
// //     time: 'Hace unos segundos'
// // };

// // const notification2 = {
// //     url: 'http://localhost:8888/?decker_page=task&id=8',
// //     taskId: 8,
// //     iconColor: 'warning',
// //     iconClass: 'ri-user-add-line',
// //     title: 'Título corto',
// //     action: 'Usuario Asignado',
// //     time: 'Hace 2 minutos'
// // };


// // //Ejemplo de uso de la función
// // const notification3 = {
// //     url: 'http://localhost:8888/?decker_page=task&id=7',
// //     taskId: 7,
// //     iconColor: 'primary', // primary, warning, success, etc.
// //     iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
// //     title: 'Implementar el nuevo diseño',
// //     action: 'Tarea Creada',
// //     time: 'Hace unos segundos'
// // };

// // const notification4 = {
// //     url: 'http://localhost:8888/?decker_page=task&id=8',
// //     taskId: 8,
// //     iconColor: 'warning',
// //     iconClass: 'ri-user-add-line',
// //     title: 'Revisar la documentación',
// //     action: 'Usuario Asignado',
// //     time: 'Hace 2 minutos'
// // };

// // const notification5 = {
// //     url: 'http://localhost:8888/?decker_page=task&id=8',
// //     taskId: 8,
// //     iconColor: 'success',
// //     iconClass: 'ri-checkbox-circle-line',
// //     title: 'Probar la API:',
// //     action: 'Tarea Completada',
// //     time: 'Hace 2 minutos'
// // };

// // addNotification(notification1);
// // addNotification(notification2);
// // addNotification(notification3);
// // addNotification(notification4);	
// // addNotification(notification5);		    

// // function clearNotifications() {
// //     const notificationList = document.getElementById('notification-list');
// //     const notificationBadges = document.querySelectorAll('.noti-icon-badge');

// //     if (notificationList) {
// //         notificationList.innerHTML = '';
// //     }

// //     notificationBadges.forEach(badge => {
// //         badge.style.display = 'none';
// //     });
// // }


// // function clearNotifications() {
// // 	const notificationList = document.getElementById('notification-list');
// // 	const notificationBadge = document.querySelector('.noti-icon-badge');

// //   if (notificationList) {
// //     notificationList.innerHTML = '';
// //   }

// //   if (notificationBadge) {
// //     notificationBadge.style.display = 'none';
// //   }

// // }

// // const clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
// // if (clearAllLink) {
// //   clearAllLink.addEventListener('click', clearNotifications);
// // }


// //     $(document).on('heartbeat-tick', function(event, data) {
// //         if (data.decker_notifications) {
// //             data.decker_notifications.forEach(notification => {
// //                 const iconMap = {
// //                     'task_created': {icon: 'ri-add-line', color: 'primary'},
// //                     'task_assigned': {icon: 'ri-user-add-line', color: 'warning'},
// //                     'task_completed': {icon: 'ri-checkbox-circle-line', color: 'success'},
// //                     'task_comment': {icon: 'ri-message-3-line', color: 'info'}
// //                 };
                
// //                 addNotification({
// //                     taskId: notification.task_id,
// //                     url: notification.url,
// //                     iconClass: iconMap[notification.type].icon,
// //                     iconColor: iconMap[notification.type].color,
// //                     title: notification.title,
// //                     action: notification.action,
// //                     time: notification.time
// //                 });
// //             });
// //         }
// //     });


// //     // Escuchar el evento 'heartbeat-tick' para recibir datos del servidor
// //     $(document).on('heartbeat-tick', function(event, data) {
// //         if (data.decker && data.decker.message) {
// //             console.log('Mensaje del servidor:', data.decker.message);
// //             alert(data.decker.message);
// //         }

// //         if (data.decker_notifications) {


// //             data.decker_notifications.forEach(function(n) {
// //                 const notificationData = {
// //                     url: n.url || '#',
// //                     taskId: n.task_id,
// //                     iconColor: 'primary', // Usa lógica condicional según n.type
// //                     iconClass: 'ri-message-3-line', // O el icono que quieras según el tipo
// //                     title: n.task_title || 'New Notification',
// //                     action: n.type || 'Action',
// //                     time: 'Just now'
// //                 };
// //                 addNotification(notificationData);
// //             });





// //             data.decker_notifications.forEach(function(notification) {
// //                 console.log('Nueva notificación Decker:', notification);
// //                 alert(notification.type + ': ' + notification.task_title);
// //                 if ("Notification" in window && Notification.permission === "granted") {
// //                     new Notification("Decker", { body: notification.task_title });
// //                 }
// //             });
// //         }
// //     });
 

// //     // Solicitar permiso para notificaciones del navegador
// //     if ("Notification" in window && Notification.permission !== "denied") {
// //         Notification.requestPermission();
// //     }
// // })(jQuery);
