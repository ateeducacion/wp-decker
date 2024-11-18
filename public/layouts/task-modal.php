<!--  Add new task modal -->
<div class="modal fade task-modal-content" id="task-modal" tabindex="-1" role="dialog" aria-labelledby="NewTaskModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h4 class="modal-title" id="NewTaskModalLabel"><?php _e('Task', 'decker'); ?></h4>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="task-modal-content">
					<!-- Aquí se cargará el contenido dinámico desde modal.php -->
				</div>
			</div>
		</div><!-- /.modal-content -->
	</div><!-- /.modal-dialog -->
</div><!-- /.modal -->


<script type="text/javascript">
	

// Espera a que el documento esté listo
document.addEventListener('DOMContentLoaded', function () {
	// Evento que se dispara cuando el modal se va a mostrar
	jQuery('#task-modal').on('show.bs.modal', function (e) {
		var modal = jQuery(this);
		// Limpiar el contenido previo del modal para evitar contenido duplicado
		modal.find('.modal-body').html('');

		var taskId = jQuery(e.relatedTarget).data('task-id'); // Obtener el ID de la tarea si se proporciona
		var url = '<?php echo plugins_url( 'layouts/task-card.php', __DIR__ ); ?>';

		if (taskId) {
			url += '?id=' + taskId; // Añadir el ID de la tarea a la URL si existe

			url += '&nocache=' + new Date().getTime();

		} else {

			// Obtener los parámetros de la URL actual
			const params = new URLSearchParams(window.location.search);

			// Obtener el valor del parámetro 'slug'
			const boardSlug = params.get('slug');
			if (boardSlug) {
				url += '?slug=' + boardSlug; // Añadir el slug de la tarea a la URL si existe
			}

		}




		// Realizar una solicitud AJAX para obtener el contenido desde task-card.php
		jQuery.ajax({
			url: url,
			type: 'GET',
			success: function(data) {
				// Insertar el contenido cargado dentro del cuerpo del modal
				modal.find('.modal-body').html(data);

				initializeTaskPage(); // Call the function when the modal opens

			},
			error: function() {
				// Mostrar un mensaje de error si algo falla
				modal.find('.modal-body').html('<p><?php _e('Error loading content. Please try again.', 'decker'); ?></p>');
			}
		});
	});
});


</script>
