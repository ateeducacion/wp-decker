<?php
/**
 * File kb-view-modal
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<div class="modal fade" id="kb-view-modal" tabindex="-1" aria-labelledby="kb-view-modalLabel" aria-hidden="true">
	<div class="modal-dialog modal-xl">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="kb-view-modalLabel"></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="kb-view-content"></div>
			</div>
			<div class="modal-footer">
				<div id="kb-view-labels" class="me-auto"></div>
				<button type="button" class="btn btn-outline-secondary btn-sm me-2" id="copy-kb-content" title="Copiar texto">
					<i class="ri-file-copy-line"></i>
				</button>
  
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<script>
function viewArticle(id, title, content, labelsJson, boardJson) {
	const modal = jQuery('#kb-view-modal');
	modal.find('#kb-view-modalLabel').text(title);
	modal.find('#kb-view-content').html(content);
	
	// Get labels with their colors
	jQuery.ajax({
		url: wpApiSettings.root + 'wp/v2/labels',
		method: 'GET',
		beforeSend: function(xhr) {
			xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
		},
		success: function(response) {
			const labels = JSON.parse(labelsJson);
			const labelMap = new Map(response.map(l => [l.name, l.meta ? l.meta['term-color'] : '#6c757d']));
			
			const labelsHtml = labels.map(label => 
				`<span class="badge me-1" style="background-color: ${labelMap.get(label)};">${label}</span>`
			).join('');
			
			// Add board badge if available
			if (boardJson) {
				const board = JSON.parse(boardJson);
				if (board && board.name) {
					const boardHtml = `<span class="badge bg-secondary ms-2" style="background-color: ${board.color || '#6c757d'}!important;">${board.name}</span>`;
					modal.find('#kb-view-labels').html(labelsHtml + boardHtml);
				} else {
					modal.find('#kb-view-labels').html(labelsHtml);
				}
			} else {
				modal.find('#kb-view-labels').html(labelsHtml);
			}
		}
	});
	
	modal.modal('show');
}

// Función para copiar el contenido del modal al portapapeles usando Swal
jQuery(document).ready(function($) {
	$('#copy-kb-content').on('click', function() {
		const textToCopy = $('#kb-view-content').text().trim();
		if (!textToCopy) return;

		navigator.clipboard.writeText(textToCopy).then(() => {
			Swal.fire({
				title: "¡Copiado!",
				text: "El texto se ha copiado al portapapeles.",
				icon: "success",
				toast: true,
				position: "top-end",
				showConfirmButton: false,
				timer: 2000
			});
		}).catch(err => {
			Swal.fire({
				title: "Error",
				text: "No se pudo copiar el texto.",
				icon: "error"
			});
			console.error('Error al copiar:', err);
		});
	});
});
</script>
