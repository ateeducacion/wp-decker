<?php
/**
 * File event-modal
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<!-- Event Modal -->
<div class="modal fade" id="event-modal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="eventModalLabel"><?php esc_html_e( 'Add New Event', 'decker' ); ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form id="event-form" method="POST">
					<input type="hidden" name="event_id" id="event-id">
					<?php wp_nonce_field( 'decker_event_action', 'decker_event_nonce' ); ?>

					<div class="mb-3">
						<label for="event-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?> <span class="text-danger">*</span></label>
						<input type="text" class="form-control" id="event-title" name="event_title" required>
					</div>

					<div class="mb-3">
						<label for="event-description" class="form-label"><?php esc_html_e( 'Description', 'decker' ); ?></label>
						<textarea class="form-control" id="event-description" name="event_description" rows="3"></textarea>
					</div>

					<div class="mb-3">
						<label class="form-label"><?php esc_html_e( 'Date and Time', 'decker' ); ?></label>
						<div class="row g-2">
							<div class="col-md-6">
								<input type="datetime-local" class="form-control" name="event_start" id="event-start" 
									step="900" 
									pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}"
									required />
								<small class="text-muted"><?php esc_html_e( 'From', 'decker' ); ?></small>
							</div>
							<div class="col-md-6">
								<input type="datetime-local" class="form-control" name="event_end" id="event-end" 
									step="900"
									pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}"
									required />
								<small class="text-muted"><?php esc_html_e( 'To', 'decker' ); ?></small>
							</div>
						</div>
						<small class="form-text text-muted">
							<?php esc_html_e( 'Tip: Clear time input for all-day events. Time picker suggests 15-minute intervals but you can type any time.', 'decker' ); ?>
						</small>
					</div>

					<div class="mb-3">
						<label for="event-location" class="form-label"><?php esc_html_e( 'Location', 'decker' ); ?></label>
						<input type="text" class="form-control" id="event-location" name="event_location">
					</div>

					<div class="mb-3">
						<label for="event-url" class="form-label"><?php esc_html_e( 'URL', 'decker' ); ?></label>
						<input type="url" class="form-control" id="event-url" name="event_url">
					</div>

					<div class="mb-3">
						<label for="event-category" class="form-label"><?php esc_html_e( 'Category', 'decker' ); ?></label>
						<select class="form-select" id="event-category" name="event_category">
							<option value="bg-danger"><?php esc_html_e( 'Danger', 'decker' ); ?></option>
							<option value="bg-success"><?php esc_html_e( 'Success', 'decker' ); ?></option>
							<option value="bg-primary" selected><?php esc_html_e( 'Primary', 'decker' ); ?></option>
							<option value="bg-info"><?php esc_html_e( 'Info', 'decker' ); ?></option>
							<option value="bg-dark"><?php esc_html_e( 'Dark', 'decker' ); ?></option>
							<option value="bg-warning"><?php esc_html_e( 'Warning', 'decker' ); ?></option>
						</select>
					</div>

					<div class="mb-3">
						<label for="event-assigned-users" class="form-label"><?php esc_html_e( 'Assigned Users', 'decker' ); ?></label>
						<select class="form-select" id="event-assigned-users" name="event_assigned_users[]" multiple>
							<?php
							$users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
							foreach ( $users as $user ) {
								printf(
									'<option value="%d">%s</option>',
									esc_attr( $user->ID ),
									esc_html( $user->display_name )
								);
							}
							?>
						</select>
					</div>

					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<?php esc_html_e( 'Close', 'decker' ); ?>
						</button>
						<button type="submit" class="btn btn-primary">
							<i class="ri-save-line me-1"></i><?php esc_html_e( 'Save', 'decker' ); ?>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
