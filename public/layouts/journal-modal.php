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
						<label for="journal-users" class="form-label"><?php esc_html_e( 'Users', 'decker' ); ?></label>
						<select class="form-control" id="journal-users" name="assigned_users[]" multiple>
							<?php
							$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
							foreach ( $users as $user ) {
								echo '<option value="' . esc_attr( $user->ID ) . '">' . esc_html( $user->display_name ) . '</option>';
							}
							?>
						</select>
					</div>

					<div class="mb-3">
						<label for="journal-labels" class="form-label"><?php esc_html_e( 'Labels', 'decker' ); ?></label>
						<select class="form-control" id="journal-labels" name="decker_labels[]" multiple>
							<?php
							$labels = get_terms( array( 'taxonomy' => 'decker_label', 'hide_empty' => false ) );
							foreach ( $labels as $label ) {
								echo '<option value="' . esc_attr( $label->term_id ) . '">' . esc_html( $label->name ) . '</option>';
							}
							?>
						</select>
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

<script>
	document.addEventListener('DOMContentLoaded', function () {
		if (document.getElementById('journal-users')) {
			new Choices('#journal-users', {
				removeItemButton: true,
			});
		}
		if (document.getElementById('journal-labels')) {
			new Choices('#journal-labels', {
				removeItemButton: true,
			});
		}
		if (document.getElementById('journal-description-editor')) {
			new Quill('#journal-description-editor', {
				theme: 'snow',
				modules: {
					toolbar: [
						[{ 'header': [1, 2, 3, false] }],
						['bold', 'italic', 'underline'],
						[{ 'list': 'ordered'}, { 'list': 'bullet' }],
						['link', 'blockquote'],
						['clean']
					]
				}
			});
		}
	});
</script>
