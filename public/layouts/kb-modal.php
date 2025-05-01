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
<div class="modal fade" id="kb-modal" tabindex="-1" aria-labelledby="kb-modalLabel" aria-hidden="true" style="display: none;">
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
	<div class="row mb-3">
		<!-- Title, Parent and Order -->
		<div class="col-md-7">
			<label for="article-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?> *</label>
			<input type="text" class="form-control" id="article-title" name="title" required style="min-height: 45px;">
			<div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
		</div>
		<div class="col-md-4">
			<label for="article-parent" class="form-label"><?php esc_html_e( 'Parent Article', 'decker' ); ?></label>
			<select class="form-select" id="article-parent" name="parent_id">
				<option value="0"><?php esc_html_e( 'No parent (top level)', 'decker' ); ?></option>
				<?php
				$articles = get_posts(
					array(
						'post_type' => 'decker_kb',
						'posts_per_page' => -1,
						'orderby' => 'menu_order title',
						'order' => 'ASC',
						'post_status' => 'publish',
					)
				);
				foreach ( $articles as $article ) {
					echo '<option value="' . esc_attr( $article->ID ) . '">' . esc_html( $article->post_title ) . '</option>';
				}
				?>
			</select>
		</div>
		<div class="col-md-1">
			<label for="article-order" class="form-label"><?php esc_html_e( 'Order', 'decker' ); ?></label>
			<input type="number" class="form-control" id="article-order" name="menu_order" min="0" value="0" style="min-height: 45px;">
		</div>
	</div>
	
	<div class="row mb-3">
		<!-- Board -->
		<div class="col-md-12">
			<label for="article-board" class="form-label"><?php esc_html_e( 'Board', 'decker' ); ?> *</label>
			<select class="form-select" id="article-board" name="board" required>
				<option value=""><?php esc_html_e( 'Select Board', 'decker' ); ?></option>
				<?php
				$boards = BoardManager::get_all_boards();
				foreach ( $boards as $board ) {
					if ( $board->show_in_kb ) {
						echo '<option value="' . esc_attr( $board->id ) . '">' . esc_html( $board->name ) . '</option>';
					}
				}
				?>
			</select>
			<div class="invalid-feedback"><?php esc_html_e( 'Please select a board.', 'decker' ); ?></div>
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
					echo '<option value="' . esc_attr( $label->id ) . '" data-custom-properties=\'{"color": "' . esc_attr( $label->color ) . '"}\'>' . esc_html( $label->name ) . '</option>';
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
		let editorInitPromise;

		function initializeEditor() {
			if (editor && editor.initialized) {
				return Promise.resolve();
			}

			return new Promise((resolve) => {
				const config = {
					tinymce: {
						wpautop: true,
						container: 'kb-modal .modal-body',
						toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright wp_adv',
						toolbar2: 'strikethrough hr forecolor pastetext removeformat charmap outdent indent undo redo wp_help',
						menubar: false,
						setup: function(ed) {
							editor = ed;
							ed.on('init', function() {
								editor.initialized = true;
								resolve();
							});
						}
					},
					quicktags: true,
					mediaButtons: true
				};

				wp.editor.initialize('article-content', config);
			});
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
			
						// // Reset and set labels
						// if (labelsSelect) {
						// 	labelsSelect.destroy();
						// }
						// labelsSelect = new Choices('#article-labels', choicesConfig);
						// if (article.labels && article.labels.length > 0) {

						// 	labelsSelect.removeActiveItems();
						// 	labelsSelect.clearInput();

						// 	labelsSelect.setChoiceByValue(article.labels.map(String));
						// }

						window.labelsSelect.removeActiveItems();
						window.labelsSelect.clearInput();
						window.labelsSelect.setChoiceByValue(article.labels.map(String));


						window.parentSelect.setChoiceByValue(article.parent_id.toString());
						
						// Set board
						if (article.board) {
							$('#article-board').val(article.board);
						} else {
							$('#article-board').val(0);
						}
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

		// Pre-initialize editor when button is clicked
		$('[data-bs-target="#kb-modal"]').on('click', function() {
			initializeEditor();
		});

		$('#kb-modal').on('shown.bs.modal', function(e) {
			
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
				window.labelsSelect.clearInput();

				window.parentSelect.setChoiceByValue('0');
				window.parentSelect.clearInput();
				
				// Check if there's a board parameter in the URL
				const boardSlug = getUrlParameter('board');
				if (boardSlug) {
					// Find the board by slug directly from our available boards
					const boardSelect = document.getElementById('article-board');
					const boardOptions = Array.from(boardSelect.options);
					
					// Get all boards from PHP
					<?php
					$boards_data = array();
					foreach ( $boards as $board ) {
						if ( $board->show_in_kb ) {
							$boards_data[] = array(
								'id' => $board->id,
								'slug' => $board->slug,
								'name' => $board->name,
							);
						}
					}
					?>
					
					// Use the PHP data
					const availableBoards = <?php echo json_encode( $boards_data ); ?>;
					const matchingBoard = availableBoards.find(board => board.slug === boardSlug);
					
					if (matchingBoard) {
						$('#article-board').val(matchingBoard.id);
						// Trigger change event to load parent articles for this board
						$('#article-board').trigger('change');
					} else {
						// Reset board if not found
						$('#article-board').val('');
					}
				} else {
					// Reset board
					$('#article-board').val('');
				}
			}
		});

		$('#kb-modal').on('hidden.bs.modal', function() {
			if (editor && editor.initialized) {
				wp.editor.remove('article-content');
				editor.initialized = false;
			}
			window.labelsSelect.removeActiveItems();
			window.labelsSelect.clearInput();
		});

		let labelsSelect, parentSelect;

		// Initialize Choices.js for labels and parent
		const choicesConfig = {
			removeItemButton: true,
			allowHTML: true,
			searchEnabled: true,
			shouldSort: true,
			placeholderValue: '<?php esc_html_e( 'Select labels...', 'decker' ); ?>',
			noChoicesText: '<?php esc_html_e( 'No more labels available', 'decker' ); ?>',
			callbackOnCreateTemplates: function (strToEl, escapeForTemplate, getClassNames) {
				const defaultTemplates = Choices.defaults.templates;
				
				return {
					...defaultTemplates,
					item: (classNames, data) => {
						// 1. Take the element generated by the default template
						const el = defaultTemplates.item.call(this, classNames, data);

						// 2. Apply background color based on the taxonomy
						el.style.backgroundColor = data.customProperties?.color || '#6c757d';

						// 3. Ensure that, if removeItemButton=true, the element is configured as "deletable"
						if (this.config.removeItemButton) {
							el.setAttribute('data-deletable', '');

							// If the default template hasn't already generated the button, create it here
							if (!el.querySelector('[data-button]')) {
								const button = document.createElement('button');
								button.type = 'button';
								button.className = this.config.classNames.button;
								button.setAttribute('data-button', '');
								button.setAttribute('aria-label', `Remove item: ${data.value}`);
								button.innerHTML = '×';
								el.appendChild(button);
							}
						}

						return el;
					}
				}
			}
		};

		// Initial labels setup
		labelsSelect = new Choices('#article-labels', choicesConfig);
		window.labelsSelect = labelsSelect;

		// Parent select should not use the same template customization as labels
		const parentChoicesConfig = {
			removeItemButton: true,
			allowHTML: true,
			searchEnabled: true,
			shouldSort: true,
			placeholderValue: '<?php esc_html_e( 'Select parent...', 'decker' ); ?>',
			noChoicesText: '<?php esc_html_e( 'No more articles available', 'decker' ); ?>',
			searchPlaceholderValue: '<?php esc_html_e( 'Search for parent article...', 'decker' ); ?>'
		};
		
		parentSelect = new Choices('#article-parent', parentChoicesConfig);
		window.parentSelect = parentSelect;

		// Handle form submission
		$('#guardar-articulo').on('click', function() {
			const form = $('#article-form')[0];
			
			if (!form.checkValidity()) {
				form.classList.add('was-validated');
				return;
			}

			const boardValue = $('#article-board').val();
			if (!boardValue || boardValue === "0" || boardValue === "") {
				$('#article-board').addClass('is-invalid');
				return;
			}

			const data = {
				id: $('#article-id').val(),
				title: $('#article-title').val(),
				content: editor.getContent(),
				labels: window.labelsSelect.getValue().map(choice => choice.value),
				parent_id: $('#article-parent').val(),
				menu_order: $('#article-order').val(),
				board: boardValue
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
