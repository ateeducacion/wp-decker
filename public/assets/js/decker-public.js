console.log('loading decker-public.js');

// Configurar el manejo de notificaciones via Heartbeat API
(function($) {
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
