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
				<div id="kb-view-labels" class="mt-3"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<script>
function viewArticle(id, title, content, labels) {
	const modal = jQuery('#kb-view-modal');
	modal.find('#kb-view-modalLabel').text(title);
	modal.find('#kb-view-content').html(content);
	
	const labelsHtml = labels.map(label => 
		`<span class="badge bg-info me-1">${label}</span>`
	).join('');
	
	modal.find('#kb-view-labels').html(labelsHtml);
	modal.modal('show');
}
</script>
