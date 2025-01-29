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
$post = $event_id ? get_post($event_id) : null;

// Verify this is actually an event post type
if ($event_id && $post->post_type !== 'decker_event') {
    wp_die(__('Invalid post type. This form is only for events.', 'decker'));
}

$title = $post ? esc_attr($post->post_title) : '';
$description = $post ? esc_textarea($post->post_content) : '';

// Get metadata
$meta = $event_id ? get_post_meta($event_id) : array();

// Process metas
$allday = isset($meta['event_allday'][0]) ? $meta['event_allday'][0] : '';
$start_date = isset($meta['event_start'][0]) ? date('Y-m-d H:i', strtotime($meta['event_start'][0])) : '';
$end_date = isset($meta['event_end'][0]) ? date('Y-m-d H:i', strtotime($meta['event_end'][0])) : '';
$assigned_users = isset($meta['event_assigned_users'][0]) ? maybe_unserialize($meta['event_assigned_users'][0]) : array();
$location = isset($meta['event_location'][0]) ? $meta['event_location'][0] : '';
$url = isset($meta['event_url'][0]) ? $meta['event_url'][0] : '';
$category = isset($meta['event_category'][0]) ? $meta['event_category'][0] : '';

?>

<form class="needs-validation" name="event-form" id="form-event" novalidate>
    <input type="hidden" name="event_id" id="event-id" value="<?php echo esc_attr( $event_id ); ?>">
    <?php wp_nonce_field( 'decker_event_action', 'decker_event_nonce' ); ?>

    <!-- Título -->
    <div class="form-floating mb-3">
        <input type="text" class="form-control" id="event-title" name="event_title" 
               value="<?php echo esc_attr($title); ?>" required placeholder="<?php esc_attr_e( 'Title', 'decker' ); ?>">
        <label for="event-title"><?php esc_html_e( 'Title', 'decker' ); ?> <span class="text-danger">*</span></label>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please enter a title for the event.', 'decker' ); ?>
        </div>
    </div>

    <!-- Fechas -->
    <div class="mb-3">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="event-allday" name="event_allday" 
                   <?php echo $allday ? 'checked' : ''; ?>>
            <label class="form-check-label" for="event-allday">
                <?php esc_html_e('All Day Event', 'decker'); ?>
            </label>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="form-floating">
                    <input type="text" class="form-control flatpickr" id="event-start" name="event_start" 
                           value="<?php echo esc_attr($start_date); ?>" required 
                           placeholder="<?php esc_attr_e( 'Start Date and Time', 'decker' ); ?>">
                    <label for="event-start"><?php esc_html_e( 'Start Date and Time', 'decker' ); ?> <span class="text-danger">*</span></label>
                    <div class="invalid-feedback">
                        <?php esc_html_e( 'Please select a start date and time.', 'decker' ); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-floating">
                    <input type="text" class="form-control flatpickr" id="event-end" name="event_end" 
                           value="<?php echo esc_attr($end_date); ?>" required 
                           placeholder="<?php esc_attr_e( 'End Date and Time', 'decker' ); ?>">
                    <label for="event-end"><?php esc_html_e( 'End Date and Time', 'decker' ); ?> <span class="text-danger">*</span></label>
                    <div class="invalid-feedback">
                        <?php esc_html_e( 'Please select an end date and time.', 'decker' ); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Personas Asignadas -->
    <div class="form-group mb-3">
        <label for="event-assigned-users"><?php esc_html_e( 'Assigned Users', 'decker' ); ?> (<a id="event-assigned-users-select-all" href="#">select all</a>)</label>

        <select class="form-select choices-select" id="event-assigned-users" name="event_assigned_users[]" multiple 
                aria-label="<?php esc_attr_e( 'Assigned Users', 'decker' ); ?>">
            <?php
            $users = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
            foreach ( $users as $user ) {
                $selected = is_array($assigned_users) && in_array($user->ID, $assigned_users);
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr( $user->ID ),
                    selected( $selected, true, false ),
                    esc_html( $user->display_name )
                );
            }
            ?>
        </select>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please select at least one user.', 'decker' ); ?>
        </div>
    </div>

    <!-- Ubicación -->
    <div class="form-floating mb-3 d-none">
        <input type="text" class="form-control" id="event-location" name="event_location" 
               value="<?php echo esc_attr($location); ?>"  
               placeholder="<?php esc_attr_e( 'Location', 'decker' ); ?>">
        <label for="event-location"><?php esc_html_e( 'Location', 'decker' ); ?> <span class="text-danger">*</span></label>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please enter the event location.', 'decker' ); ?>
        </div>
    </div>

    <!-- URL -->
    <div class="form-floating mb-3 d-none">
        <input type="url" class="form-control" id="event-url" name="event_url" 
               value="<?php echo esc_attr($url); ?>" 
               placeholder="<?php esc_attr_e( 'Event URL', 'decker' ); ?>">
        <label for="event-url"><?php esc_html_e( 'Event URL', 'decker' ); ?></label>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please enter a valid URL.', 'decker' ); ?>
        </div>
    </div>

    <!-- Descripción -->
    <div class="form-floating mb-3">
        <textarea class="form-control" id="event-description" name="event_description" rows="3" 
                  placeholder="<?php esc_attr_e( 'Description', 'decker' ); ?>"><?php echo esc_textarea($description); ?></textarea>
        <label for="event-description"><?php esc_html_e( 'Description', 'decker' ); ?></label>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please enter a description for the event.', 'decker' ); ?>
        </div>
    </div>

    <!-- Categoría -->
    <div class="form-floating mb-4">
        <select class="form-select" id="event-category" name="event_category" required>
            <option value="" disabled selected><?php esc_html_e( 'Select Category', 'decker' ); ?></option>
            <option value="bg-success" <?php echo $category === 'bg-success' ? 'selected' : ''; ?>>
                <?php esc_html_e( 'Meeting', 'decker' ); ?>
            </option>
            <option value="bg-info" <?php echo $category === 'bg-info' ? 'selected' : ''; ?>>
                <?php esc_html_e( 'Holidays', 'decker' ); ?>
            </option>
            <option value="bg-warning" <?php echo $category === 'bg-warning' ? 'selected' : ''; ?>>
                <?php esc_html_e( 'Warning', 'decker' ); ?>
            </option>
            <option value="bg-danger" <?php echo $category === 'bg-danger' ? 'selected' : ''; ?>>
                <?php esc_html_e( 'Alert', 'decker' ); ?>
            </option>
        </select>
        <label for="event-category"><?php esc_html_e( 'Category', 'decker' ); ?> <span class="text-danger">*</span></label>
        <div class="invalid-feedback">
            <?php esc_html_e( 'Please select a category for the event.', 'decker' ); ?>
        </div>
        <small class="form-text text-muted mt-1">
            <?php esc_html_e( 'The category determines the color of the event in the calendar.', 'decker' ); ?>
        </small>
    </div>

    <!-- Botones -->
    <div class="modal-footer d-flex justify-content-between w-100">
        <?php if ( $event_id ) : ?>
            <button type="button" class="btn btn-danger delete-event" data-id="<?php echo esc_attr( $event_id ); ?>">
                <i class="ri-delete-bin-line me-1"></i><?php esc_html_e( 'Delete', 'decker' ); ?>
            </button>
        <?php else: ?>
            <div></div>
        <?php endif; ?>
        <div>
            <?php if ( isset( $_GET['modal'] ) ) : ?>
                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                    <?php esc_html_e( 'Close', 'decker' ); ?>
                </button>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <i class="ri-save-line me-1"></i><?php esc_html_e( 'Save', 'decker' ); ?>
            </button>
        </div>
    </div>
</form>


