console.log('loading heartbeat.js');


// Configurar el manejo de notificaciones via Heartbeat API
(function($) {

function addNotification(notificationData) {
	
    const notificationList = document.getElementById('notification-list');
    const notificationBadge = document.querySelector('.noti-icon-badge');

    if (!notificationList) {
        console.error("Notification list element not found.");
        return;
    }

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
    iconDiv.classList.add('notify-icon', `bg-${notificationData.iconColor}`);
    const icon = document.createElement('i');
    icon.classList.add(notificationData.iconClass, 'fs-18');
    iconDiv.appendChild(icon);

    const contentDiv = document.createElement('div');
    contentDiv.classList.add('notification-content'); // Nuevo div para el contenido

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

    // Show the red dot on notification badge.
	// const notificationBadge = document.querySelector('.noti-icon-badge');
	if (notificationBadge) {
		notificationBadge.style.display = 'inline-block';
	}

}

//Ejemplo de uso de la función
const notification1 = {
    url: 'http://localhost:8888/?decker_page=task&id=7',
    taskId: 7,
    iconColor: 'primary',
    iconClass: 'ri-message-3-line',
    title: 'Este es un título de notificación muy largo para probar el truncamiento',
    action: 'Tarea Creada',
    time: 'Hace unos segundos'
};

const notification2 = {
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'warning',
    iconClass: 'ri-user-add-line',
    title: 'Título corto',
    action: 'Usuario Asignado',
    time: 'Hace 2 minutos'
};


//Ejemplo de uso de la función
const notification3 = {
    url: 'http://localhost:8888/?decker_page=task&id=7',
    taskId: 7,
    iconColor: 'primary', // primary, warning, success, etc.
    iconClass: 'ri-message-3-line', // Clase del icono de Remix Icon
    title: 'Implementar el nuevo diseño',
    action: 'Tarea Creada',
    time: 'Hace unos segundos'
};

const notification4 = {
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'warning',
    iconClass: 'ri-user-add-line',
    title: 'Revisar la documentación',
    action: 'Usuario Asignado',
    time: 'Hace 2 minutos'
};

const notification5 = {
    url: 'http://localhost:8888/?decker_page=task&id=8',
    taskId: 8,
    iconColor: 'success',
    iconClass: 'ri-checkbox-circle-line',
    title: 'Probar la API:',
    action: 'Tarea Completada',
    time: 'Hace 2 minutos'
};

addNotification(notification1);
addNotification(notification2);
addNotification(notification3);
addNotification(notification4);	
addNotification(notification5);		    

function clearNotifications() {
    const notificationList = document.getElementById('notification-list');
    const notificationBadges = document.querySelectorAll('.noti-icon-badge');

    if (notificationList) {
        notificationList.innerHTML = '';
    }

    notificationBadges.forEach(badge => {
        badge.style.display = 'none';
    });
}


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

const clearAllLink = document.querySelector('.text-dark.text-decoration-underline');
if (clearAllLink) {
  clearAllLink.addEventListener('click', clearNotifications);
}
   // Escuchar el evento 'heartbeat-tick' para recibir datos del servidor
    $(document).on('heartbeat-tick', function(e, data) {
        // alert("tick");
        if (data.decker && data.decker.message) {
            console.log('Mensaje del servidor:', data.decker.message);
            // Aquí puedes manejar la respuesta del servidor según tus necesidades
            alert(data.decker.message);
        }
    });


    // Asegurarse de que wp.heartbeat está disponible
    if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
        return;
    }

    // Escuchar las respuestas del heartbeat
    $(document).on('heartbeat-tick', function(event, data) {
        if (data.decker_notifications) {
            data.decker_notifications.forEach(function(notification) {
                console.log('Nueva notificación Decker:', notification);
                
                // Aquí puedes personalizar cómo mostrar la notificación
                // Por ejemplo:
                const message = `${notification.type}: ${notification.task_title}`;
                console.log(message);
                alert(message);
                // Si el navegador soporta notificaciones nativas
                if ("Notification" in window && Notification.permission === "granted") {
                    new Notification("Decker", { body: message });
                }
            });
        }
    });

    // Solicitar permiso para notificaciones del navegador
    if ("Notification" in window && Notification.permission !== "denied") {
        Notification.requestPermission();
    }
})(jQuery);
