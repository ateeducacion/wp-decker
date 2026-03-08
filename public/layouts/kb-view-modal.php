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
	<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="kb-view-modalLabel"></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="kb-view-meta" class="small text-muted mb-3"></div>
				<div id="kb-view-content"></div>
			</div>
			<div class="modal-footer">
				<div class="me-auto d-flex flex-column gap-2">
					<div id="kb-view-labels"></div>
					<div id="kb-view-admin-links" class="d-flex flex-wrap gap-2"></div>
				</div>
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

	// Fetch latest version from server to ensure freshness
	jQuery.ajax({
		url: wpApiSettings.root + 'decker/v1/kb',
		method: 'GET',
		data: { id: id },
		beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
		success: function(resp) {
			if (!resp || !resp.success) return;
			const art = resp.article || {};
			modal.find('#kb-view-modalLabel').text(art.title || title || '');
			modal.find('#kb-view-content').html(art.content || content || '');
			const authorName = art.author && art.author.name ? art.author.name : '';
			const lastEditorName = art.last_editor && art.last_editor.name ? art.last_editor.name : '';
			const commentCount = Number(art.comment_count || 0);
			const revisionCount = Number(art.revision_count || 0);
			const metaParts = [];
			if (authorName) metaParts.push('<?php echo esc_js( __( 'Author', 'decker' ) ); ?>: ' + authorName);
			if (lastEditorName) metaParts.push('<?php echo esc_js( __( 'Last editor', 'decker' ) ); ?>: ' + lastEditorName);
			metaParts.push('<?php echo esc_js( __( 'Comments', 'decker' ) ); ?>: ' + commentCount);
			metaParts.push('<?php echo esc_js( __( 'History', 'decker' ) ); ?>: ' + revisionCount);
			modal.find('#kb-view-meta').text(metaParts.join(' • '));

			const links = art.links || {};
			const $adminLinks = modal.find('#kb-view-admin-links');
			$adminLinks.empty();
			if (links.comments) {
				const $commentsLink = jQuery('<a>', {
					class: 'btn btn-outline-warning btn-sm',
					target: '_blank',
					rel: 'noopener noreferrer',
					href: links.comments
				});
				$commentsLink.append(jQuery('<i>', { class: 'ri-chat-1-line me-1' }));
				$commentsLink.append(document.createTextNode('<?php echo esc_js( __( 'Comments', 'decker' ) ); ?>'));
				$adminLinks.append($commentsLink);
			}
			if (links.history) {
				const $historyLink = jQuery('<a>', {
					class: 'btn btn-outline-dark btn-sm',
					target: '_blank',
					rel: 'noopener noreferrer',
					href: links.history
				});
				$historyLink.append(jQuery('<i>', { class: 'ri-history-line me-1' }));
				$historyLink.append(document.createTextNode('<?php echo esc_js( __( 'History', 'decker' ) ); ?>'));
				$adminLinks.append($historyLink);
			}
			if (links.edit) {
				const $editLink = jQuery('<a>', {
					class: 'btn btn-outline-info btn-sm',
					target: '_blank',
					rel: 'noopener noreferrer',
					href: links.edit
				});
				$editLink.append(jQuery('<i>', { class: 'ri-external-link-line me-1' }));
				$editLink.append(document.createTextNode('<?php echo esc_js( __( 'Edit', 'decker' ) ); ?>'));
				$adminLinks.append($editLink);
			}

			// Resolve labels by ID to their names/colors
			jQuery.ajax({
				url: wpApiSettings.root + 'wp/v2/labels?per_page=100&_fields=id,name,meta',
				method: 'GET',
				beforeSend: function(xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
				success: function(allLabels) {
					const mapById = new Map();
					if (Array.isArray(allLabels)) {
						allLabels.forEach(l => mapById.set(Number(l.id), { name: l.name, color: l.meta ? l.meta['term-color'] : '#6c757d' }));
					}
					const selIds = Array.isArray(art.labels) ? art.labels.map(n => Number(n)) : [];
					const sel = selIds.map(lid => ({ id: lid, ...(mapById.get(lid) || { name: '', color: '#6c757d' }) }));
					const labelsHtml = sel.map(l => `<span class="badge me-1" style="background-color: ${l.color || '#6c757d'};">${l.name}</span>`).join('');

					// Use provided board JSON (contains name/color) if present
					let board = null;
					try {
						if (boardJson && typeof boardJson === 'string') board = JSON.parse(boardJson);
						else if (boardJson && typeof boardJson === 'object') board = boardJson;
					} catch (e) { board = null; }

					let finalHtml = '';
					if (board && board.name) {
						const boardHtml = `<span class="badge bg-secondary me-2" style="background-color: ${board.color || '#6c757d'}!important;">${board.name}</span>`;
						finalHtml = boardHtml + labelsHtml;
					} else {
						finalHtml = labelsHtml;
					}
					modal.find('#kb-view-labels').html(finalHtml);
				}
			});

			modal.modal('show');
		}
	});
}

// Function to copy the modal content to the clipboard using Swal
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
