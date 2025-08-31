<?php
/**
 * Modal for viewing journal entries.
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */
?>

<div class="modal fade" id="journal-view-modal" tabindex="-1" aria-labelledby="journalViewModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-xl">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="journalViewModalLabel"></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="journal-view-content">
					<!-- Content will be populated by JavaScript -->
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>
