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
			<div class="modal-header py-3 px-4 border-bottom-0">
				<h5 class="modal-title" id="modal-title"><?php esc_html_e( 'Event', 'decker' ); ?></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body px-4 pb-4 pt-0">
				<form class="needs-validation" name="event-form" id="form-event" novalidate>
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
									pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}"
									required />
								<small class="text-muted"><?php esc_html_e( 'From', 'decker' ); ?></small>
							</div>
							<div class="col-md-6">
								<input type="datetime-local" class="form-control" name="event_end" id="event-end" 
									pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}"
									required />
								<small class="text-muted"><?php esc_html_e( 'To', 'decker' ); ?></small>
							</div>
						</div>
						<small class="form-text text-muted">
							<?php esc_html_e( 'Tip: Clear time input for all-day events.', 'decker' ); ?>
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
							<option value="bg-success" class="d-flex align-items-center">
								<span class="badge bg-success me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Meeting', 'decker' ); ?>
							</option>
							<option value="bg-info" class="d-flex align-items-center">
								<span class="badge bg-info me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Holidays', 'decker' ); ?>
							</option>
							<option value="bg-warning" class="d-flex align-items-center">
								<span class="badge bg-warning me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Warning', 'decker' ); ?>
							</option>
							<option value="bg-danger" class="d-flex align-items-center">
								<span class="badge bg-danger me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Alert', 'decker' ); ?>
							</option>
						</select>
						<small class="form-text text-muted mt-1">
							<?php esc_html_e( 'The category determines the color of the event in the calendar.', 'decker' ); ?>
						</small>
					</div>

					<div class="mb-3">
						<label for="event-assigned-users" class="form-label"><?php esc_html_e( 'Assigned Users', 'decker' ); ?></label>
						<select class="form-select choices-select" id="event-assigned-users" name="event_assigned_users[]" multiple>
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
