<?php
/**
 * Modal for adding/editing journal entries.
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */
?>

<div class="modal fade" id="journal-modal" tabindex="-1" aria-labelledby="journalModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="journalModalLabel"><?php esc_html_e( 'New Journal Entry', 'decker' ); ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form id="journal-form">
					<input type="hidden" id="journal-id" name="journal_id">
					<div class="mb-3">
						<label for="journal-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?></label>
						<input type="text" class="form-control" id="journal-title" name="title" required>
					</div>
					<div class="mb-3">
						<label for="journal-date" class="form-label"><?php esc_html_e( 'Date', 'decker' ); ?></label>
						<input type="date" class="form-control" id="journal-date" name="journal_date" required>
					</div>
					<!-- More fields will be added here -->
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
				<button type="button" class="btn btn-primary" id="save-journal-btn"><?php esc_html_e( 'Save', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>
