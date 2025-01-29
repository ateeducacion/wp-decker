<?php
/**
 * File event-card
 *
 * @package    Decker
 * @subpackage Decker/public/layouts
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

/**
 * Function to find and include wp-load.php dynamically.
 *
 * @param int $max_levels Maximum number of directory levels to traverse upward.
 * @return bool Returns true if wp-load.php is found and included, otherwise false.
 */
function include_wp_load( $max_levels = 10 ) {
    $dir = __DIR__;
    for ( $i = 0; $i < $max_levels; $i++ ) {
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            require_once $dir . '/wp-load.php';
            return true;
        }
        $parent_dir = dirname( $dir );
        if ( $parent_dir === $dir ) {
            break;
        }
        $dir = $parent_dir;
    }
    return false;
}

// Attempt to include wp-load.php when loading the event card in a modal
if ( ! defined( 'ABSPATH' ) ) {
    if ( ! include_wp_load() ) {
        exit( 'Error: Unauthorized access.' );
    }
}

// Sanitize and verify the nonce.
$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
if ( ! wp_verify_nonce( $nonce, 'decker_event_card' ) ) {
	exit( 'Unauthorized request.' );
}


// Initialize variables
$event_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;

// Verify this is actually an event post type before creating Event object
$post_type = get_post_type($event_id);
if ($event_id && $post_type !== 'decker_event') {
    wp_die(__('Invalid post type. This form is only for events.', 'decker'));
}

$event = $event_id ? new Event( $event_id ) : null;

?>

<form class="needs-validation" name="event-form" id="form-event" novalidate>
    <input type="hidden" name="event_id" id="event-id" value="<?php echo esc_attr( $event_id ); ?>">
    <?php wp_nonce_field( 'decker_event_action', 'decker_event_nonce' ); ?>

    <div class="mb-3">
        <label for="event-title" class="form-label"><?php esc_html_e( 'Title', 'decker' ); ?> <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="event-title" name="event_title" value="<?php echo $event ? esc_attr( $event->get_title() ) : ''; ?>" required>
    </div>

    <div class="mb-3">
        <label for="event-description" class="form-label"><?php esc_html_e( 'Description', 'decker' ); ?></label>
        <textarea class="form-control" id="event-description" name="event_description" rows="3"><?php echo $event ? esc_textarea( $event->get_description() ) : ''; ?></textarea>
    </div>

    <div class="mb-3">
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="event-allday" name="event_allday">
            <label class="form-check-label" for="event-allday">
                <?php esc_html_e('All Day Event', 'decker'); ?>
            </label>
        </div>
        
        <div class="row g-3">
            <div class="col-md-6">
                <label for="event-start" class="form-label"><?php esc_html_e('Start', 'decker'); ?></label>
                <input type="text" class="form-control flatpickr" id="event-start" name="event_start" 
                       value="<?php echo $event ? esc_attr($event->get_start_date()?->format('Y-m-d H:i')) : ''; ?>" required />
            </div>
            <div class="col-md-6">
                <label for="event-end" class="form-label"><?php esc_html_e('End', 'decker'); ?></label>
                <input type="text" class="form-control flatpickr" id="event-end" name="event_end" 
                       value="<?php echo $event ? esc_attr($event->get_end_date()?->format('Y-m-d H:i')) : ''; ?>" required />
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Default flatpickr config
            const flatpickrConfig = {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                time_24hr: true,
                minuteIncrement: 15
            };

            // Initialize flatpickr instances
            const startPicker = flatpickr("#event-start", flatpickrConfig);
            const endPicker = flatpickr("#event-end", {
                ...flatpickrConfig,
                minDate: startPicker.selectedDates[0] || "today"
            });

            // Update end date min when start date changes
            startPicker.config.onChange = function(selectedDates) {
                endPicker.set('minDate', selectedDates[0] || "today");
            };

            // Handle all day toggle
            const allDayCheckbox = document.getElementById('event-allday');
            allDayCheckbox.addEventListener('change', function() {
                const config = {
                    enableTime: !this.checked,
                    dateFormat: this.checked ? "Y-m-d" : "Y-m-d H:i"
                };
                
                startPicker.set('enableTime', !this.checked);
                startPicker.set('dateFormat', config.dateFormat);
                
                endPicker.set('enableTime', !this.checked);
                endPicker.set('dateFormat', config.dateFormat);
                
                if (this.checked) {
                    // If switching to all day, set times to start/end of day
                    if (startPicker.selectedDates[0]) {
                        startPicker.selectedDates[0].setHours(0, 0, 0, 0);
                        startPicker.setDate(startPicker.selectedDates[0]);
                    }
                    if (endPicker.selectedDates[0]) {
                        endPicker.selectedDates[0].setHours(23, 59, 0, 0);
                        endPicker.setDate(endPicker.selectedDates[0]);
                    }
                }
            });
        });
    </script>

    <div class="mb-3">
        <label for="event-category" class="form-label"><?php esc_html_e( 'Category', 'decker' ); ?></label>
        <select class="form-select" id="event-category" name="event_category">
            <option value="bg-success" <?php echo $event && $event->get_category() === 'bg-success' ? 'selected' : ''; ?>>
                <span class="badge bg-success me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Meeting', 'decker' ); ?>
            </option>
            <option value="bg-info" <?php echo $event && $event->get_category() === 'bg-info' ? 'selected' : ''; ?>>
                <span class="badge bg-info me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Holidays', 'decker' ); ?>
            </option>
            <option value="bg-warning" <?php echo $event && $event->get_category() === 'bg-warning' ? 'selected' : ''; ?>>
                <span class="badge bg-warning me-2" style="width: 20px;">&nbsp;</span><?php esc_html_e( 'Warning', 'decker' ); ?>
            </option>
            <option value="bg-danger" <?php echo $event && $event->get_category() === 'bg-danger' ? 'selected' : ''; ?>>
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
            $assigned_users = $event ? $event->get_assigned_users() : array();
            foreach ( $users as $user ) {
                $selected = in_array( $user->ID, array_column( $assigned_users, 'ID' ) );
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr( $user->ID ),
                    selected( $selected, true, false ),
                    esc_html( $user->display_name )
                );
            }
            ?>
        </select>
    </div>

    <div class="modal-footer">
        <div class="d-flex justify-content-between w-100">
            <?php if ( $event_id ) : ?>
                <button type="button" class="btn btn-danger delete-event" data-id="<?php echo esc_attr( $event_id ); ?>">
                    <i class="ri-delete-bin-line me-1"></i><?php esc_html_e( 'Delete', 'decker' ); ?>
                </button>
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            <div>
                <?php if ( isset( $_GET['modal'] ) ) : ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <?php esc_html_e( 'Close', 'decker' ); ?>
                    </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <i class="ri-save-line me-1"></i><?php esc_html_e( 'Save', 'decker' ); ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php
/**
 * Render an event row for the events list
 *
 * @param Event $event The event to render.
 */
function render_event_row( $event ) {
    ?>
    <tr>
        <td class="event-title">
            <?php echo esc_html( $event->get_title() ); ?>
        </td>
        <td class="event-start">
            <?php echo esc_html( $event->get_start_date()->format( 'Y-m-d H:i' ) ); ?>
        </td>
        <td class="event-end">
            <?php echo esc_html( $event->get_end_date()->format( 'Y-m-d H:i' ) ); ?>
        </td>
        <td class="event-category">
            <span class="badge <?php echo esc_attr( $event->get_category() ); ?>">
                <?php echo esc_html( str_replace( 'bg-', '', $event->get_category() ) ); ?>
            </span>
        </td>
        <td>
            <a href="#" class="btn btn-sm btn-info me-2" 
               data-bs-toggle="modal" 
               data-bs-target="#event-modal"
               data-event-id="<?php echo esc_attr( $event->get_id() ); ?>">
                <i class="ri-pencil-line"></i>
            </a>
            <a href="#" class="btn btn-sm btn-danger delete-event" 
               data-id="<?php echo esc_attr( $event->get_id() ); ?>">
                <i class="ri-delete-bin-line"></i>
            </a>
            <span class="event-description d-none">
                <?php echo esc_html( $event->get_description() ); ?>
            </span>
            <span class="event-assigned-users d-none">
                <?php echo esc_attr( json_encode( $event->get_assigned_users() ) ); ?>
            </span>
        </td>
    </tr>
    <?php
}
