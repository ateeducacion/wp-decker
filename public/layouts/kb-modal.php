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
<form id="article-form" class="needs-validation" target="_self" novalidate>
	<input type="hidden" name="action" value="save_decker_article">
	<input type="hidden" name="article_id" value="<?php echo esc_attr( $article_id ); ?>">
	<div class="row">


		<!-- Title -->
		<div class="col-md-9 mb-3">
			<div class="form-floating">
				<input type="text" class="form-control" id="article-title" value="" placeholder="<?php esc_attr_e( 'Article title', 'decker' ); ?>" required <?php disabled( $disabled ); ?>>
				<label for="article-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?></label>
				<div class="invalid-feedback"><?php esc_html_e( 'Please provide a title.', 'decker' ); ?></div>
			</div>
		</div>

</div>

		<div class="row">
				<textarea name="my-wp-editor" id="my-wp-editor" rows="12" class="myprefix-wpeditor">The value from database here :-)</textarea>
		   </div>
	<div class="row">

		<div class="mb-3">
			<label for="article-labels" class="form-label"><?php esc_html_e( 'Labels', 'decker' ); ?></label>
			<select class="form-select" id="article-labels" multiple>
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


		$('#kb-modal').on('shown.bs.modal', function () {

			config = {
			  tinymce: {
				wpautop: true,
				container: 'kb-modal .modal-body', // Container for TinyMCE popups
				toolbar1: 'formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link wp_adv',
				toolbar2: 'strikethrough hr forecolor pastetext removeformat charmap outdent indent undo redo wp_help',
				menubar: false
			  },
			  quicktags: true,
			  mediaButtons: true
			};

			wp.editor.initialize('my-wp-editor', config);

		});

		$('#kb-modal').on('hidden.bs.modal', function () {
			// Destruye el editor al cerrar el modal (opcional, pero puede ser útil limpiar recursos)
			$('#my-wp-editor').empty();
		});


		// Inicializar Choices.js
		if (!window.labelsSelect) {
			window.labelsSelect = new Choices(document.querySelector('#article-labels'), { 
				removeItemButton: true, 
				allowHTML: true,
				searchEnabled: true,
				shouldSort: true,
			});
		}

	});
</script>