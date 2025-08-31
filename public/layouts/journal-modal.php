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
						<label for="journal-board" class="form-label"><?php esc_html_e( 'Board', 'decker' ); ?></label>
						<select class="form-select" id="journal-board" name="decker_board" required>
							<option value=""><?php esc_html_e( 'Select a Board', 'decker' ); ?></option>
							<?php
							$boards = BoardManager::get_all_boards();
							foreach ( $boards as $board ) {
								echo '<option value="' . esc_attr( $board->term_id ) . '">' . esc_html( $board->name ) . '</option>';
							}
							?>
						</select>
					</div>

					<div class="mb-3">
						<label for="journal-date" class="form-label"><?php esc_html_e( 'Date', 'decker' ); ?></label>
						<input type="date" class="form-control" id="journal-date" name="journal_date" required>
					</div>

					<div class="mb-3">
						<label for="journal-topic" class="form-label"><?php esc_html_e( 'Topic', 'decker' ); ?></label>
						<input type="text" class="form-control" id="journal-topic" name="topic">
					</div>

					<div class="mb-3">
						<label class="form-label"><?php esc_html_e( 'Users', 'decker' ); ?></label>
						<div id="journal-users-container">
							<!-- User checkboxes will be populated here -->
						</div>
					</div>

					<div class="mb-3">
						<label class="form-label"><?php esc_html_e( 'Labels', 'decker' ); ?></label>
						<div id="journal-labels-container">
							<!-- Label checkboxes will be populated here -->
						</div>
					</div>

					<div class="mb-3">
						<label for="journal-description" class="form-label"><?php esc_html_e( 'Description', 'decker' ); ?></label>
						<div id="journal-description-editor" style="height: 200px;"></div>
						<input type="hidden" id="journal-description" name="description">
					</div>

					<div class="mb-3">
						<label for="journal-agreements" class="form-label"><?php esc_html_e( 'Agreements', 'decker' ); ?></label>
						<textarea class="form-control" id="journal-agreements" name="agreements" rows="3"></textarea>
					</div>

				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php esc_html_e( 'Close', 'decker' ); ?></button>
				<button type="button" class="btn btn-primary" id="save-journal-btn"><?php esc_html_e( 'Save', 'decker' ); ?></button>
			</div>
		</div>
	</div>
</div>
