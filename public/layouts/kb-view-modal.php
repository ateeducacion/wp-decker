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
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>

<script>
function viewArticle(id, title, content, labelsJson) {
	const modal = jQuery('#kb-view-modal');
	modal.find('#kb-view-modalLabel').text(title);
	modal.find('#kb-view-content').html(content);
	
	// Get labels with their colors
	jQuery.ajax({
		url: wpApiSettings.root + 'wp/v2/decker_label',
		method: 'GET',
		beforeSend: function(xhr) {
			xhr.setRequestHeader('X-WP-Nonce', wpApiSettings.nonce);
		},
		success: function(response) {
			const labels = JSON.parse(labelsJson);
			const labelMap = new Map(response.map(l => [l.name, l.meta['term-color']]));
			
			const labelsHtml = labels.map(label => 
				`<span class="badge me-1" style="background-color: ${labelMap.get(label)};">${label}</span>`
			).join('');
			
			modal.find('#kb-view-labels').html(labelsHtml);
		}
	});
	
	modal.find('#kb-view-labels').html(labelsHtml);
	modal.modal('show');
}
</script>
