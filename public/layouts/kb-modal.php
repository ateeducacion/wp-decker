<?php
/**
 * File kb-modal (creation modal)
 *
 * A simplified modal for creating KB articles. Board, parent, and order are set automatically.
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 */

?>

<div class="modal fade" id="kb-modal" tabindex="-1" aria-labelledby="kb-modalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
	<div class="modal-content">
	  <div class="modal-header">
		<h5 class="modal-title" id="kb-modalLabel"><?php esc_html_e( 'Add New Article', 'decker' ); ?></h5>
		<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
	  </div>
	  <div class="modal-body">
		<form id="article-form" class="needs-validation" novalidate>
		  <input type="hidden" name="article_id" id="article-id" value="">
		  <input type="hidden" name="parent_id" id="article-parent" value="0">
		  <input type="hidden" name="menu_order" id="article-order" value="0">

		  <div class="row mb-3 align-items-end">
			<div class="col-md-4">
			  <label for="article-board" class="form-label"><?php esc_html_e( 'Board', 'decker' ); ?></label>
			  <select class="form-select" id="article-board" name="board" style="min-height:45px;" required>
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
			<div class="col-md-8">
			  <label for="article-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?> *</label>
			  <input type="text" class="form-control" id="article-title" name="title" required style="min-height:45px;">
			  <div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
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
jQuery(function($){
  var labelsSelect = null;
  var editorInited = false;

  function initEditor() {
	if (editorInited) return;
	try {
	  if (window.wp && wp.editor) {
		wp.editor.initialize('article-content', {
		  tinymce: { wpautop: true, menubar: false, toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright' },
		  quicktags: true,
		  mediaButtons: true
		});
		editorInited = true;
	  }
	} catch (e) {}
  }

  function destroyEditor() {
	try { if (window.wp && wp.editor) { wp.editor.remove('article-content'); editorInited = false; } } catch(e) {}
  }

  $('#kb-modal').on('show.bs.modal', function (e) {
	// Prepare form
	var $btn = $(e.relatedTarget);
	var parentId = parseInt($btn.data('parent-id') || '0', 10);
	var boardId = 0;
	if (parentId > 0) {
	  var $pli = $('li.kb-item[data-article-id="' + parentId + '"]');
	  boardId = parseInt($pli.data('board-id') || '0', 10);
	} else {
	  var rb = document.getElementById('kb-root');
	  boardId = rb ? parseInt(rb.getAttribute('data-current-board-id') || '0', 10) : 0;
	}

	$('#article-form')[0].reset();
	$('#article-id').val('');
	$('#article-parent').val(String(parentId));
	// Preselect board and lock if determined by context
	var $board = $('#article-board');
	if (boardId > 0) {
	  $board.val(String(boardId)).prop('disabled', true).removeClass('is-invalid');
	} else {
	  $board.val('').prop('disabled', false).removeClass('is-invalid');
	}
	$('#article-order').val('0');
	if (labelsSelect) { try { labelsSelect.removeActiveItems(); labelsSelect.clearInput(); } catch(e){} }
  });

  $('#kb-modal').on('shown.bs.modal', function(){
	initEditor();
	try { if (window.tinymce && tinymce.get('article-content')) { tinymce.get('article-content').setContent(''); } } catch(e) {}
	$('#article-title').trigger('focus');

	// Init labels choices lazily
	if (!labelsSelect && window.Choices) {
	  try {
		labelsSelect = new Choices('#article-labels', { removeItemButton: true, shouldSort: true });
	  } catch (e) {}
	}
  });

  $('#kb-modal').on('hidden.bs.modal', function(){
	destroyEditor();
  });

  $('#guardar-articulo').on('click', function(){
	var form = $('#article-form')[0];
	if (!form.checkValidity()) { form.classList.add('was-validated'); return; }

	var content = '';
	try {
	  if (window.wp && wp.editor && wp.editor.get('article-content')) content = wp.editor.get('article-content').getContent();
	  if (!content && window.tinymce && tinymce.get('article-content')) content = tinymce.get('article-content').getContent();
	  if (!content) content = ($('#article-content').val() || '');
	} catch(e) { content = ($('#article-content').val() || ''); }

	var $board = $('#article-board');
	var data = {
	  title: ($('#article-title').val() || '').toString().trim(),
	  content: content,
	  labels: ($('#article-labels').val() || []),
	  parent_id: $('#article-parent').val() || 0,
	  menu_order: $('#article-order').val() || 0,
	  board: $board.val() || ''
	};
	if (!data.board) { $board.addClass('is-invalid').trigger('focus'); return; }

	$.ajax({
	  url: wpApiSettings.root + 'decker/v1/kb',
	  method: 'POST',
	  beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce); },
	  data: data
	}).then(function(resp){
	  if (!resp || !resp.success) throw new Error('save failed');
	  window.location.reload();
	}).fail(function(){
	  alert(deckerVars?.strings?.error || 'Error');
	});
  });
  // Remove invalid UI when user selects a board
  $(document).on('change', '#article-board', function(){ $(this).removeClass('is-invalid'); });
});
</script>
