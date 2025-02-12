<?php
/**
 * File kb-modal
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

?>
<div class="modal fade" id="kb-modal" tabindex="-1" aria-labelledby="kb-modalLabel" aria-hidden="true">
	<div class="modal-dialog modal-xl">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="kb-modalLabel"><?php esc_html_e( 'Add New Article', 'decker' ); ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">

<!-- Article -->
<form id="article-form" class="needs-validation" novalidate>
	<input type="hidden" name="article_id" id="article-id" value="">
	<div class="row">
		<!-- Title and Order -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="article-title" name="title" placeholder="<?php esc_attr_e( 'Article title', 'decker' ); ?>" required>
				<label for="article-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
			</div>
		</div>
		<div class="col-md-3 mb-3">
			<div class="form-floating">
				<input type="number" class="form-control" id="article-order" name="menu_order" min="0" value="0">
				<label for="article-order" class="form-label"><?php esc_html_e( 'Order', 'decker' ); ?></label>
			</div>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12 mb-3">
			<label for="article-parent" class="form-label"><?php esc_html_e( 'Parent Article', 'decker' ); ?></label>
			<select class="form-select" id="article-parent" name="parent_id">
				<option value="0"><?php esc_html_e( 'No parent (top level)', 'decker' ); ?></option>
				<?php
				$articles = get_posts(array(
					'post_type' => 'decker_kb',
					'posts_per_page' => -1,
					'orderby' => 'menu_order title',
					'order' => 'ASC',
					'post_status' => 'publish'
				));
				foreach ($articles as $article) {
					echo '<option value="' . esc_attr($article->ID) . '">' . esc_html($article->post_title) . '</option>';
				}
				?>
			</select>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12 mb-3">
			<textarea name="content" id="article-content" rows="12" class="form-control"></textarea>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12 mb-3">
			<label for="article-labels" class="form-label"><?php esc_html_e( 'Labels', 'decker' ); ?></label>
			<select class="form-select" id="article-labels" name="labels[]" multiple>
				<?php
				$labels = LabelManager::get_all_labels();
				foreach ( $labels as $label ) {
					echo '<option value="' . esc_attr( $label->id ) . '" data-choice-custom-properties=\'{"color": "' . esc_attr( $label->color ) . '"}\'>' . esc_html( $label->name ) . '</option>';
				}
				?>
			</select>
		</div>
	</div>
</form>

			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
				<button type="button" class="btn btn-primary" id="guardar-articulo"><?php esc_html_e( 'Save Article', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<script type="text/javascript">
	jQuery(document).ready(function($) {
		let editor;

		function initializeEditor() {
			const config = {
				tinymce: {
					wpautop: true,
					container: 'kb-modal .modal-body',
					toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link wp_adv',
					toolbar2: 'strikethrough hr forecolor pastetext removeformat charmap outdent indent undo redo wp_help',
					menubar: false,
					setup: function(ed) {
						editor = ed;
					}
				},
				quicktags: true,
				mediaButtons: true
			};

			wp.editor.initialize('article-content', config);
		}

		function loadArticle(id) {
			$.ajax({
				url: wpApiSettings.root + 'decker/v1/kb',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
				},
				data: { id: id },
				success: function(response) {
					if (response.success) {
						const article = response.article;
						$('#article-id').val(article.id);
						$('#article-title').val(article.title);
						$('#article-order').val(article.menu_order);
						editor.setContent(article.content);
						window.labelsSelect.setChoiceByValue(article.labels);
						window.parentSelect.setChoiceByValue(article.parent_id.toString());
					} else {
						Swal.fire({
							title: '<?php esc_html_e( 'Error', 'decker' ); ?>',
							text: response.message,
							icon: 'error'
						});
					}
				},
				error: function() {
					Swal.fire({
						title: '<?php esc_html_e( 'Error', 'decker' ); ?>',
						text: '<?php esc_html_e( 'Could not load article', 'decker' ); ?>',
						icon: 'error'
					});
				}
			});
		}

		$('#kb-modal').on('shown.bs.modal', function(e) {
			initializeEditor();
			
			const button = $(e.relatedTarget);
			const articleId = button.data('article-id');
			
			if (articleId) {
				$('#kb-modalLabel').text('<?php esc_html_e( 'Edit Article', 'decker' ); ?>');
				loadArticle(articleId);
			} else {
				// New article
				$('#kb-modalLabel').text('<?php esc_html_e( 'Add New Article', 'decker' ); ?>');
				$('#article-form')[0].reset();
				$('#article-id').val('');
				editor.setContent('');
				window.labelsSelect.removeActiveItems();
				window.parentSelect.setChoiceByValue('0');
			}
		});

		$('#kb-modal').on('hidden.bs.modal', function() {
			if (editor) {
				wp.editor.remove('article-content');
			}
		});

		// Initialize Choices.js for labels and parent
		const choicesConfig = {
			removeItemButton: true,
			allowHTML: true,
			searchEnabled: true,
			shouldSort: true
		};

		if (!window.labelsSelect) {
			window.labelsSelect = new Choices('#article-labels', choicesConfig);
		}

		if (!window.parentSelect) {
			window.parentSelect = new Choices('#article-parent', {
				...choicesConfig,
				searchPlaceholderValue: '<?php esc_html_e( 'Search for parent article...', 'decker' ); ?>'
			});
		}

		// Handle form submission
		$('#guardar-articulo').on('click', function() {
			const form = $('#article-form')[0];
			
			if (!form.checkValidity()) {
				form.classList.add('was-validated');
				return;
			}

			const data = {
				id: $('#article-id').val(),
				title: $('#article-title').val(),
				content: editor.getContent(),
				labels: window.labelsSelect.getValue().map(choice => choice.value),
				parent_id: $('#article-parent').val(),
				menu_order: $('#article-order').val()
			};

			$.ajax({
				url: wpApiSettings.root + 'decker/v1/kb',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
				},
				data: data,
				success: function(response) {
					if (response.success) {
						Swal.fire({
							title: '<?php esc_html_e( 'Success', 'decker' ); ?>',
							text: response.message,
							icon: 'success'
						}).then(() => {
							window.location.reload();
						});
					} else {
						Swal.fire({
							title: '<?php esc_html_e( 'Error', 'decker' ); ?>',
							text: response.message,
							icon: 'error'
						});
					}
				},
				error: function() {
					Swal.fire({
						title: '<?php esc_html_e( 'Error', 'decker' ); ?>',
						text: '<?php esc_html_e( 'Could not save article', 'decker' ); ?>',
						icon: 'error'
					});
				}
			});
		});
	});
</script>
