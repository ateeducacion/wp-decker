/* eslint-disable */
/* global jQuery, heartbeat */

/**
 * Decker Heartbeat Notifications
 *
 * Handles real-time notifications received via the WordPress Heartbeat API.
 */
console.log('loading heartbeat.js');

(function($) {
    'use strict';

    /**
     * Appends a new notification to the UI.
     *
     * @param {Object} notificationData - Data with keys: url, taskId, iconColor, iconClass, title, action, time.
     */
    function addNotification(notificationData) {
        const notificationList = document.getElementById('notification-list');
        const notificationBadge = document.querySelector('.noti-icon-badge');

        if (!notificationList) {
            console.error("Notification list element not found.");
            return;
        }

        // Make badge visible if it was hidden
        if (notificationBadge && notificationBadge.style.display === 'none') {
            notificationBadge.style.display = '';
        }

        const notificationLink = document.createElement('a');
        notificationLink.href = notificationData.url;
        notificationLink.title = notificationData.title;
        notificationLink.dataset.bsToggle = 'modal';
        notificationLink.dataset.bsTarget = '#task-modal';
        notificationLink.dataset.taskId = notificationData.taskId;
        notificationLink.classList.add('dropdown-item', 'notify-item');

        const notificationDiv = document.createElement('div');
        notificationDiv.classList.add('d-flex');

        const iconDiv = document.createElement('div');
        iconDiv.classList.add('notify-icon', 'bg-' + notificationData.iconColor);
        const icon = document.createElement('i');
        icon.classList.add(notificationData.iconClass, 'fs-18');
        iconDiv.appendChild(icon);

        const contentDiv = document.createElement('div');
        contentDiv.classList.add('notification-content');

        const titleDiv = document.createElement('h5');
        titleDiv.classList.add('fw-semibold');
        titleDiv.textContent = notificationData.title;

        const actionDiv = document.createElement('small');
        actionDiv.classList.add('notification-action');

        const actionText = document.createElement('span');
        actionText.textContent = notificationData.action;

        const timeSpan = document.createElement('small');
        timeSpan.classList.add('text-muted');
        timeSpan.textContent = notificationData.time;

        actionDiv.appendChild(actionText);
        actionDiv.appendChild(timeSpan);

        contentDiv.appendChild(titleDiv);
        contentDiv.appendChild(actionDiv);
        notificationDiv.appendChild(iconDiv);
        notificationDiv.appendChild(contentDiv);
        notificationLink.appendChild(notificationDiv);

        notificationList.prepend(notificationLink);

        // Ensure the badge is visible
        if (notificationBadge) {
            notificationBadge.style.display = 'inline-block';
        }
    }

//Ejemplo de uso de la función
addNotification({
    url: 'http://localhost:8888/?decker_page=task&id=7',
    taskId: 7,
    iconColor: 'primary',
    iconClass: 'ri-message-3-line',
    title: 'Este es un título de notificación muy largo para probar el truncamiento',
    action: 'Tarea Creada',
    time: 'Hace unos segundos'
});

addNotification({
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'warning',
    iconClass: 'ri-user-add-line',
    title: 'Título corto',
    action: 'Usuario Asignado',
    time: 'Hace 2 minutos'
});


//Ejemplo de uso de la función
addNotification({
    url: 'http://localhost:8888/?decker_page=task&id=7',
    taskId: 7,
    iconColor: 'primary', // primary, warning, success, etc.
    iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
    title: 'Implementar el nuevo diseño',
    action: 'Tarea Creada',
    time: 'Hace unos segundos'
});

addNotification({
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'warning',
    iconClass: 'ri-user-add-line',
    title: 'Revisar la documentación',
    action: 'Usuario Asignado',
    time: 'Hace 2 minutos'
});

addNotification({
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'success',
    iconClass: 'ri-checkbox-circle-line',
    title: 'Probar la API:',
    action: 'Tarea Completada',
    time: 'Hace 2 minutos'
});

    /**
     * Clears all notifications from the UI.
     */
    function clearNotifications() {
        const notificationList = document.getElementById('notification-list');
        const notificationBadge = document.querySelector('.noti-icon-badge');

        if (notificationList) {
            notificationList.innerHTML = '';
        }
        if (notificationBadge) {
            notificationBadge.style.display = 'none';
        }
    }

    // Listen for "Clear All" link to remove notifications from the UI
    const clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
    if (clearAllLink) {
        clearAllLink.addEventListener('click', clearNotifications);
    }

    /**
     * Maps notification types to their icon classes and colors.
     */
    const iconMap = {
        task_created:   { icon: 'ri-add-line',           color: 'primary' },
        task_assigned:  { icon: 'ri-user-add-line',      color: 'warning' },
        task_completed: { icon: 'ri-checkbox-circle-line', color: 'success' },
        task_comment:   { icon: 'ri-message-3-line',     color: 'info' }
    };

    // Handle incoming Heartbeat data
    $(document).on('heartbeat-tick', function(event, data) {
        if (data.decker_notifications && Array.isArray(data.decker_notifications)) {
            data.decker_notifications.forEach(function(notification) {
                // Derive icon and color based on type
                const mapping = iconMap[ notification.type ] || { icon: 'ri-information-line', color: 'primary' };

                addNotification({
                    url:       notification.url || '#',
                    taskId:    notification.task_id,
                    iconColor: mapping.color,
                    iconClass: mapping.icon,
                    title:     notification.title || 'New Notification',
                    action:    notification.action || 'Action',
                    time:      notification.time || 'Just now'
                });

                // Optionally trigger a browser notification if user allowed it
                if (("Notification" in window) && Notification.permission === "granted") {
                    new Notification("Decker", { body: notification.title || 'New Notification' });
                }
            });
        }
    });

    // Request permission for browser notifications
    if ("Notification" in window && Notification.permission !== "denied") {
        Notification.requestPermission();
    }

})(jQuery);


// console.log('loading heartbeat.js');


// // Configurar el manejo de notificaciones via Heartbeat API
// (function($) {

// function addNotification(notificationData) {
	
//     const notificationList = document.getElementById('notification-list');
//     const notificationBadge = document.querySelector('.noti-icon-badge');

//     if (!notificationList) {
//         console.error("Notification list element not found.");
//         return;
//     }

//         if (notificationBadge && notificationBadge.style.display === 'none') {
//         notificationBadge.style.display = '';
//     }

//     const notificationLink = document.createElement('a');
//     notificationLink.href = notificationData.url;
//     notificationLink.title = notificationData.title;

//     notificationLink.dataset.bsToggle = 'modal';
//     notificationLink.dataset.bsTarget = '#task-modal';
//     notificationLink.dataset.taskId = notificationData.taskId;
//     notificationLink.classList.add('dropdown-item', 'notify-item');

//     const notificationDiv = document.createElement('div');
//     notificationDiv.classList.add('d-flex');

//     const iconDiv = document.createElement('div');
//     iconDiv.classList.add('notify-icon', `bg-${notificationData.iconColor}`);
//     const icon = document.createElement('i');
//     icon.classList.add(notificationData.iconClass, 'fs-18');
//     iconDiv.appendChild(icon);

//     const contentDiv = document.createElement('div');
//     contentDiv.classList.add('notification-content'); // Nuevo div para el contenido

//     const titleDiv = document.createElement('h5');
//     titleDiv.classList.add('fw-semibold');
//     titleDiv.textContent = notificationData.title;

//     const actionDiv = document.createElement('small');
//     actionDiv.classList.add('notification-action');

//     const actionText = document.createElement('span');
//     actionText.textContent = notificationData.action;

//     const timeSpan = document.createElement('small');
//     timeSpan.classList.add('text-muted');
//     timeSpan.textContent = notificationData.time;

//     actionDiv.appendChild(actionText);
//     actionDiv.appendChild(timeSpan);


//     contentDiv.appendChild(titleDiv);
//     contentDiv.appendChild(actionDiv);


//     notificationDiv.appendChild(iconDiv);
//     notificationDiv.appendChild(contentDiv);
//     notificationLink.appendChild(notificationDiv);
//     notificationList.prepend(notificationLink);

//     // Show the red dot on notification badge.
// 	// const notificationBadge = document.querySelector('.noti-icon-badge');
// 	if (notificationBadge) {
// 		notificationBadge.style.display = 'inline-block';
// 	}

// }

// //Ejemplo de uso de la función
// const notification1 = {
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary',
//     iconClass: 'ri-message-3-line',
//     title: 'Este es un título de notificación muy largo para probar el truncamiento',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// };

// const notification2 = {
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Título corto',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// };


// //Ejemplo de uso de la función
// const notification3 = {
//     url: 'http://localhost:8888/?decker_page=task&id=7',
//     taskId: 7,
//     iconColor: 'primary', // primary, warning, success, etc.
//     iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
//     title: 'Implementar el nuevo diseño',
//     action: 'Tarea Creada',
//     time: 'Hace unos segundos'
// };

// const notification4 = {
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'warning',
//     iconClass: 'ri-user-add-line',
//     title: 'Revisar la documentación',
//     action: 'Usuario Asignado',
//     time: 'Hace 2 minutos'
// };

// const notification5 = {
//     url: 'http://localhost:8888/?decker_page=task&id=8',
//     taskId: 8,
//     iconColor: 'success',
//     iconClass: 'ri-checkbox-circle-line',
//     title: 'Probar la API:',
//     action: 'Tarea Completada',
//     time: 'Hace 2 minutos'
// };

// addNotification(notification1);
// addNotification(notification2);
// addNotification(notification3);
// addNotification(notification4);	
// addNotification(notification5);		    

// function clearNotifications() {
//     const notificationList = document.getElementById('notification-list');
//     const notificationBadges = document.querySelectorAll('.noti-icon-badge');

//     if (notificationList) {
//         notificationList.innerHTML = '';
//     }

//     notificationBadges.forEach(badge => {
//         badge.style.display = 'none';
//     });
// }


// function clearNotifications() {
// 	const notificationList = document.getElementById('notification-list');
// 	const notificationBadge = document.querySelector('.noti-icon-badge');

//   if (notificationList) {
//     notificationList.innerHTML = '';
//   }

//   if (notificationBadge) {
//     notificationBadge.style.display = 'none';
//   }

// }

// const clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
// if (clearAllLink) {
//   clearAllLink.addEventListener('click', clearNotifications);
// }


//     $(document).on('heartbeat-tick', function(event, data) {
//         if (data.decker_notifications) {
//             data.decker_notifications.forEach(notification => {
//                 const iconMap = {
//                     'task_created': {icon: 'ri-add-line', color: 'primary'},
//                     'task_assigned': {icon: 'ri-user-add-line', color: 'warning'},
//                     'task_completed': {icon: 'ri-checkbox-circle-line', color: 'success'},
//                     'task_comment': {icon: 'ri-message-3-line', color: 'info'}
//                 };
                
//                 addNotification({
//                     taskId: notification.task_id,
//                     url: notification.url,
//                     iconClass: iconMap[notification.type].icon,
//                     iconColor: iconMap[notification.type].color,
//                     title: notification.title,
//                     action: notification.action,
//                     time: notification.time
//                 });
//             });
//         }
//     });


//     // Escuchar el evento 'heartbeat-tick' para recibir datos del servidor
//     $(document).on('heartbeat-tick', function(event, data) {
//         if (data.decker && data.decker.message) {
//             console.log('Mensaje del servidor:', data.decker.message);
//             alert(data.decker.message);
//         }

//         if (data.decker_notifications) {


//             data.decker_notifications.forEach(function(n) {
//                 const notificationData = {
//                     url: n.url || '#',
//                     taskId: n.task_id,
//                     iconColor: 'primary', // Usa lógica condicional según n.type
//                     iconClass: 'ri-message-3-line', // O el icono que quieras según el tipo
//                     title: n.task_title || 'New Notification',
//                     action: n.type || 'Action',
//                     time: 'Just now'
//                 };
//                 addNotification(notificationData);
//             });





//             data.decker_notifications.forEach(function(notification) {
//                 console.log('Nueva notificación Decker:', notification);
//                 alert(notification.type + ': ' + notification.task_title);
//                 if ("Notification" in window && Notification.permission === "granted") {
//                     new Notification("Decker", { body: notification.task_title });
//                 }
//             });
//         }
//     });
 

//     // Solicitar permiso para notificaciones del navegador
//     if ("Notification" in window && Notification.permission !== "denied") {
//         Notification.requestPermission();
//     }
// })(jQuery);
