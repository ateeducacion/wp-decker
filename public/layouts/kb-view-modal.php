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
				<ul class="nav nav-tabs nav-bordered mb-3" id="kb-view-tabs" role="tablist">
					<li class="nav-item">
						<button class="nav-link active" id="kb-article-tab" data-bs-toggle="tab" data-bs-target="#kb-article-pane" type="button" role="tab" aria-controls="kb-article-pane" aria-selected="true"><?php esc_html_e( 'View', 'decker' ); ?></button>
					</li>
					<li class="nav-item">
						<button class="nav-link" id="kb-comments-tab" data-bs-toggle="tab" data-bs-target="#kb-comments-pane" type="button" role="tab" aria-controls="kb-comments-pane" aria-selected="false"><?php esc_html_e( 'Comments', 'decker' ); ?> <span class="badge bg-light text-dark" id="kb-comment-count">0</span></button>
					</li>
				</ul>
				<div class="tab-content">
					<div class="tab-pane fade show active" id="kb-article-pane" role="tabpanel" aria-labelledby="kb-article-tab">
						<div id="kb-view-content"></div>
					</div>
					<div class="tab-pane fade" id="kb-comments-pane" role="tabpanel" aria-labelledby="kb-comments-tab">
						<div id="kb-comments-list"></div>
						<div class="border rounded mt-4">
							<div class="comment-area-box">
								<textarea rows="3" class="form-control border-0 resize-none" placeholder="<?php esc_attr_e( 'Write your comment...', 'decker' ); ?>" id="kb-comment-text" name="kb-comment-text"></textarea>
								<div class="invalid-feedback"><?php esc_html_e( 'Please enter a comment.', 'decker' ); ?></div>
								<div class="p-2 bg-light d-flex justify-content-between align-items-center">
									<button type="button" class="btn btn-sm btn-success" id="kb-submit-comment" disabled><i class="ri-chat-1-line me-1"></i> <?php esc_html_e( 'Comment', 'decker' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				</div>
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
function renderKbComments(comments) {
	const commentsList = jQuery('#kb-comments-list');
	commentsList.empty();

	if (!Array.isArray(comments) || comments.length === 0) {
		commentsList.append(
			jQuery('<p>', { text: '<?php echo esc_js( __( 'No comments yet.', 'decker' ) ); ?>' })
		);
		return;
	}

	const commentsByParent = new Map();
	comments.forEach(comment => {
		const parentId = Number(comment.parent || 0);
		if (!commentsByParent.has(parentId)) {
			commentsByParent.set(parentId, []);
		}
		commentsByParent.get(parentId).push(comment);
	});

	const renderThread = function(parentId, indent) {
		(commentsByParent.get(Number(parentId)) || []).forEach(comment => {
				const $wrapper = jQuery('<div>', {
					class: 'd-flex align-items-start mb-2',
					'data-comment-id': comment.id
				});

				if (indent > 0) {
					$wrapper.css('margin-left', indent + 'px');
				}

				const $avatar = jQuery('<img>', {
					class: 'me-2 rounded-circle',
					src: comment.author_avatar_url || '',
					alt: 'Avatar',
					height: 32
				});
				const $content = jQuery('<div>', { class: 'w-100' });
				const $title = jQuery('<h5>', { class: 'mt-0' }).text(comment.author_name || '');
				const $date = jQuery('<small>', { class: 'text-muted float-end' }).text(comment.date || '');
				$title.append(' ').append($date);
				$content.append($title);
				$content.append(jQuery('<div>').html(comment.content_rendered || ''));

				if (comment.can_delete) {
					const $deleteButton = jQuery('<button>', {
						type: 'button',
						class: 'btn btn-link text-muted d-inline-block mt-2 p-0 kb-comment-delete',
						'data-comment-id': comment.id
					});
					$deleteButton.append(jQuery('<i>', { class: 'ri-delete-bin-line' }));
					$deleteButton.append(document.createTextNode(' <?php echo esc_js( __( 'Delete', 'decker' ) ); ?>'));
					$content.append($deleteButton);
				}

				$wrapper.append($avatar).append($content);
				commentsList.append($wrapper);
				renderThread(comment.id, indent + 20);
			});
	};

	renderThread(0, 0);
}

function setKbCommentsCount(count) {
	jQuery('#kb-comment-count').text(Number(count || 0));
}

function showKbTab(tabSelector) {
	const trigger = document.querySelector(tabSelector);
	if (trigger && window.bootstrap) {
		bootstrap.Tab.getOrCreateInstance(trigger).show();
	}
}

function performKbCommentRequest(url, options) {
	return fetch(url, options).then(response => response.json());
}

function getKbCommentsRestUrl(commentId) {
	return `${wpApiSettings.root}wp/v2/comments${commentId ? '/' + commentId : ''}`;
}

function refreshKbArticleView(initialTab) {
	const modal = jQuery('#kb-view-modal');
	const articleId = Number(modal.data('article-id') || 0);
	const fallbackTitle = modal.data('article-title') || '';
	const fallbackContent = modal.data('article-content') || '';
	const boardJson = modal.data('article-board');

	if (!articleId) {
		return;
	}

	loadKbArticle(articleId, fallbackTitle, fallbackContent, boardJson, initialTab);
}

function loadKbArticle(id, title, content, boardJson, initialTab) {
	const modal = jQuery('#kb-view-modal');
	const includeComments = initialTab === 'comments';
	initialTab = initialTab !== undefined ? initialTab : 'article';
	modal.data('article-id', id);
	modal.data('article-title', title || '');
	modal.data('article-content', content || '');
	modal.data('article-board', boardJson || null);

	// Fetch latest version from server to ensure freshness
	jQuery.ajax({
		url: wpApiSettings.root + 'decker/v1/kb',
		method: 'GET',
		data: { id: id, include_comments: includeComments ? 1 : 0 },
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
			const comments = Array.isArray(art.comments) ? art.comments : [];
			const metaParts = [];
			if (authorName) metaParts.push('<?php echo esc_js( __( 'Author', 'decker' ) ); ?>: ' + authorName);
			if (lastEditorName) metaParts.push('<?php echo esc_js( __( 'Last editor', 'decker' ) ); ?>: ' + lastEditorName);
			metaParts.push('<?php echo esc_js( __( 'Comments', 'decker' ) ); ?>: ' + commentCount);
			metaParts.push('<?php echo esc_js( __( 'History', 'decker' ) ); ?>: ' + revisionCount);
			modal.find('#kb-view-meta').text(metaParts.join(' • '));
			modal.find('#kb-comment-text').val('');
			modal.data('comments-loaded', includeComments);
			if (includeComments) {
				renderKbComments(comments);
			} else {
				modal.find('#kb-comments-list').empty();
			}
			setKbCommentsCount(commentCount);

			const links = art.links || {};
			const $adminLinks = modal.find('#kb-view-admin-links');
			$adminLinks.empty();
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
			showKbTab('#kb-' + initialTab + '-tab');
		}
	});
}

function viewArticle(id, title, content, labelsJson, boardJson, initialTab) {
	loadKbArticle(id, title, content, boardJson, initialTab);
}

// Function to copy the modal content to the clipboard using Swal
jQuery(document).ready(function($) {
	$('#kb-comment-text').on('input', function() {
		$('#kb-submit-comment').prop('disabled', '' === $(this).val().trim());
	});

	$('#kb-submit-comment').on('click', function() {
		const $modal = $('#kb-view-modal');
		const $button = $(this);
		const articleId = Number($modal.data('article-id') || 0);
		const commentText = ($('#kb-comment-text').val() || '').trim();

		if (!articleId || !commentText) {
			return;
		}

		$button.prop('disabled', true);

		performKbCommentRequest(getKbCommentsRestUrl(), {
			method: 'POST',
			headers: {
				'X-WP-Nonce': wpApiSettings.nonce,
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({
				post: articleId,
				content: commentText,
				parent: 0
			}),
			credentials: 'same-origin'
		}).then(data => {
			if (data.code) {
				alert(data.message || '<?php echo esc_js( __( 'Error', 'decker' ) ); ?>');
				return;
			}

			$('#kb-comment-text').val('');
			refreshKbArticleView('comments');
		})
		.catch(error => {
			console.error('Error:', error);
			alert('<?php echo esc_js( __( 'Error', 'decker' ) ); ?>');
		})
		.finally(() => {
			$button.prop('disabled', '' === ($('#kb-comment-text').val() || '').trim());
		});
	});

	$(document).on('click', '.kb-comment-delete', function() {
		const commentId = Number($(this).data('comment-id') || 0);
		const articleId = Number($('#kb-view-modal').data('article-id') || 0);

		if (!commentId || !confirm('<?php echo esc_js( __( 'Are you sure you want to delete this comment?', 'decker' ) ); ?>')) {
			return;
		}

		performKbCommentRequest(getKbCommentsRestUrl(commentId), {
			method: 'DELETE',
			headers: {
				'X-WP-Nonce': wpApiSettings.nonce,
				'Content-Type': 'application/json'
			},
			credentials: 'same-origin'
		}).then(data => {
			if (!(data.status === 'trash' || data.deleted)) {
				alert('<?php echo esc_js( __( 'Failed to delete comment.', 'decker' ) ); ?>');
				return;
			}

			refreshKbArticleView('comments');
		})
		.catch(error => {
			console.error('Error:', error);
			alert('<?php echo esc_js( __( 'Error deleting comment.', 'decker' ) ); ?>');
		});
	});

	$('#kb-comments-tab').on('shown.bs.tab', function() {
		if (!$('#kb-view-modal').data('comments-loaded')) {
			refreshKbArticleView('comments');
		}
	});

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
