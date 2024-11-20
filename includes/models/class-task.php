<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Task
 *
 * Represents a custom post type `decker_task`.
 */
class Task {
    public int $ID = 0;
    public string $title = '';
    public string $description = '';
    public string $status;
    public ?string $stack = 'to-do';
    public bool $max_priority = false;
    public ?DateTime $duedate = null;
    public array $assigned_users = [];
    public int $author;
    public int $order = 0;
    public ?Board $board = null;
    public array $labels = [];
    public array $attachments = [];
    public array $meta = [];

    /**
     * Task constructor.
     *
     * @param int|WP_Post $input The ID of the task or a WP_Post object.
     */
    public function __construct($input = null) {

        if ($input instanceof WP_Post) {
            $post = $input;
        } elseif (is_int($input) && $input > 0) {
            $post = get_post($input); 
        } else {
            $this->author = get_current_user_id(); // Default author
            $post = false;
        }

        if ($post) {

            if ('decker_task' !== $post->post_type) {
                throw new Exception(__('Invalid post type.', 'decker'));
            }

            $this->ID = $post->ID;
            $this->title = (string)$post->post_title;
            $this->description = (string)$post->post_content;
            $this->status = (string)$post->post_status;
            $this->author = $post->post_author;
            $this->order = (int)$post->menu_order;

            // Load all metadata once
            $meta = get_post_meta($this->ID);

            // Use the meta array directly
            $this->stack = isset($meta['stack'][0]) ? (string)$meta['stack'][0] : null;
            $this->max_priority = isset($meta['max_priority'][0]) && $meta['max_priority'][0] === '1';
            
            // Convert duedate to a DateTime object if set
            $this->duedate = isset($meta['duedate'][0]) ? new DateTime($meta['duedate'][0]) : null;

            $this->attachments = isset($meta['attachments']) ? (array)$meta['attachments'] : [];
            $this->meta = $meta; // Store all meta in case you need it later

            $this->assigned_users = $this->get_users($meta);

            // Load taxonomies
            $this->board = $this->get_board();
            $this->labels = $this->get_labels();
            
        }
    }

    /**
     * Retrieves the term associated with the `decker_board` taxonomy.
     *
     * @return Board|null The Board or null if no term is assigned.
     */
    private function get_board(): ?Board {
        $terms = wp_get_post_terms($this->ID, 'decker_board');

        if (!empty($terms) && !is_wp_error($terms)) {
            return new Board($terms[0]);
        }
        return null;
    }

    /**
     * Retrieves terms associated with the `decker_label` taxonomy.
     *
     * @return Label[] List of Label objects.
     */
    private function get_labels(): array {
        $terms = wp_get_post_terms($this->ID, 'decker_label');
        $labels = [];
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $labels[] = new Label($term);
            }
        }
        return $labels;
    }

    /**
     * Converts an array of user IDs from meta into WP_User objects and adds a `today` property.
     *
     * @param array $meta Meta data array containing user IDs.
     * @return array Array of WP_User objects with an added `today` property.
     */
    private function get_users(array $meta): array {
        $users = [];
        if (isset($meta['assigned_users'][0])) {
            $user_ids = maybe_unserialize($meta['assigned_users'][0]);

            foreach ($user_ids as $user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    // Add custom `today` property
                    $user->today = $this->is_today_assigned($user_id, $meta);
                    $users[] = $user;
                }
            }
        }
        return $users;
    }

    public function is_current_user_assigned_to_task() {

        $current_user_id = get_current_user_id();

        foreach($this->assigned_users as $user) {

            if ( $current_user_id == $user->ID )
                return true;

        }

        return false;
    }

    public function is_current_user_today_assigned() {
        return $this->is_today_assigned(get_current_user_id(), $this->meta);   
    }

    /**
     * Determines if the user should have the `today` flag set to true based on `_user_date_relations` meta.
     *
     * @param int $user_id The user ID.
     * @param array $meta Meta data array containing `_user_date_relations`.
     * @return bool True if the user is assigned for today, false otherwise.
     */
    private function is_today_assigned(int $user_id, array $meta): bool {

        if (isset($meta['_user_date_relations'][0])) {

            $user_date_relations = maybe_unserialize($meta['_user_date_relations'][0]);

            if ($user_date_relations && is_array($user_date_relations)) {

                $today = (new DateTime())->format('Y-m-d'); // Get today's date in 'Y-m-d' format

                foreach ($user_date_relations as $relation) {
                
                    if (isset($relation['user_id'], $relation['date']) &&
                        $relation['user_id'] == $user_id && 
                        $relation['date'] === $today) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get a "pastelized" version of a color, making it softer for background usage.
     *
     * @param string $color An HTML hex color (e.g., '#ff0000').
     * 
     * @return string HTML value of the pastelized color in hex format (e.g., '#ffcccc').
     */
    public function pastelizeColor(string $color): string {
        // Remove '#' if present
        $color = ltrim($color, '#');

        // Ensure it's a valid 6-character hex color
        if (strlen($color) !== 6) {
            return '#cccccc'; // Default fallback to light gray if input is invalid
        }

        // Convert hex color to RGB values
        $r = hexdec(substr($color, 0, 2));
        $g = hexdec(substr($color, 2, 2));
        $b = hexdec(substr($color, 4, 2));

        // Pastelize by averaging with white (255, 255, 255)
        $r = round(($r + 255) / 2);
        $g = round(($g + 255) / 2);
        $b = round(($b + 255) / 2);

        // Convert back to hex
        $pastelColor = sprintf('#%02x%02x%02x', $r, $g, $b);

        return $pastelColor;
    }


    /**
     * Retrieves a historical record of users and their assigned dates as user objects.
     *
     * @return array An array of historical records with user objects and dates.
     */
    public function get_user_history_with_objects(): array {
        $history = [];

        if (isset($this->meta['_user_date_relations'][0])) {
            $user_date_relations = maybe_unserialize($this->meta['_user_date_relations'][0]);

            if ($user_date_relations && is_array($user_date_relations)) {
                foreach ($user_date_relations as $relation) {
                    if (isset($relation['user_id'], $relation['date'])) {
                        // Retrieve WordPress user object
                        $user = get_userdata($relation['user_id']);
                        if ($user) {
                            $history[] = [
                                'user' => $user,
                                'date' => $relation['date']
                            ];
                        }
                    }
                }
            }
        }

        return $history;
    }

    /**
     * Assigns a user to the task.
     *
     * @param int $user_id The user ID.
     */
    public function assign_user(int $user_id): void {
        if (!in_array($user_id, $this->assigned_users)) {
            $this->assigned_users[] = $user_id;
            update_post_meta($this->ID, 'assigned_users', $this->assigned_users);
        }
    }

    /**
     * Unassigns a user from the task.
     *
     * @param int $user_id The user ID.
     */
    public function unassign_user(int $user_id): void {
        if (($key = array_search($user_id, $this->assigned_users)) !== false) {
            unset($this->assigned_users[$key]);
            update_post_meta($this->ID, 'assigned_users', $this->assigned_users);
        }
    }

    public function getRelativeTime(): string {
        return Decker_Utility_Functions::getRelativeTime($this->duedate);
    }

    public function getDuedateAsString(): string {

        // Initialize $duedate to an empty string
        $duedate = '';

        // Check if 'duedate' property exists and is a DateTime object
        if ( isset( $this->duedate ) && $this->duedate instanceof DateTime ) {
            // Format the DateTime object to 'Y-m-d'
            $duedate = $this->duedate->format( 'Y-m-d' );
        } elseif ( isset( $this->duedate ) && is_string( $this->duedate ) ) {
            // If 'duedate' is a string, attempt to parse it to 'Y-m-d'
            $date = date_create( $this->duedate );
            if ( $date ) {
                $duedate = $date->format( 'Y-m-d' );
            }
        }

        return $duedate;

    }


    /**
     * Render the current task card for Kanban.
     */
    public function renderTaskCard( bool $draw_background_color = false ) {
        $taskUrl = add_query_arg(
            ['decker_page' => 'task', 'id' => esc_attr($this->ID)],
            home_url('/')
        );
        $priorityBadgeClass = $this->max_priority ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary';
        $priorityLabel = $this->max_priority ? __('🔥', 'decker') : __('Normal', 'decker');
        $formatted_duedate = $this->getDuedateAsString();
        $relative_time = '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> ' . __('Undefined date', 'decker') . '</span>';

        if (!empty($this->duedate)) {
            $relative_time = esc_html($this->getRelativeTime());
        }

        $card_background_color = "";
        if ($draw_background_color) {
            $board_color = $this->pastelizeColor($this->board->color);
            $card_background_color = 'style="background-color: ' . esc_attr($board_color) . ';"';        
        }

        ?>
        <div class="card mb-0" data-task-id="<?php echo esc_attr($this->ID); ?>" <?php echo $card_background_color; ?>>
            <div class="card-body p-3">
                <span class="float-end badge <?php echo esc_attr($priorityBadgeClass); ?>">
                    <span class="label-to-hide"><?php echo esc_html($priorityLabel); ?></span>
                    <span class="menu-order label-to-show" style="display: none;"><?php _e('Order:', 'decker'); ?> <?php echo esc_html($this->order); ?></span>
                </span>

                <small class="text-muted relative-time-badge" title="<?php echo esc_attr($formatted_duedate); ?>">
                    <span class="task-id label-to-hide"><?php echo $relative_time; ?></span>
                    <span class="task-id label-to-show" style="display: none;">#<?php echo esc_html($this->ID); ?></span>
                </small>

                <h5 class="my-2 fs-16" id="task-<?php echo esc_attr($this->ID); ?>">
                    <a href="<?php echo esc_url($taskUrl); ?>" data-bs-toggle="modal" data-bs-target="#task-modal" class="text-body" data-task-id="<?php echo esc_attr($this->ID); ?>">
                        <?php echo esc_html($this->title); ?>
                    </a>
                </h5>

                <p class="mb-0">
                    <span class="pe-2 text-nowrap mb-2 d-inline-block">
                        <i class="ri-briefcase-2-line text-muted"></i>                       
                        <?php 

                        if ($draw_background_color) {
                            echo esc_html($this->board->name); 
                        }
                        ?>
                    </span>
                    <span class="text-nowrap mb-2 d-inline-block">
                        <i class="ri-discuss-line text-muted"></i>
                        <b><?php echo esc_html(get_comments_number($this->ID)); ?></b> <?php _e('Comments', 'decker'); ?>
                    </span>
                </p>

                <?php echo $this->renderTaskMenu(); ?>

                <div class="avatar-group mt-2">
                    <?php foreach ($this->assigned_users as $user_info): ?>
                        <a href="javascript:void(0);" class="avatar-group-item <?php echo $user_info->today ? ' today' : ''; ?>"
                           data-bs-toggle="tooltip" data-bs-placement="top"
                           title="<?php echo esc_attr($user_info->display_name); ?>">
                            <img src="<?php echo esc_url(get_avatar_url($user_info->ID)); ?>" alt=""
                                 class="rounded-circle avatar-xs">
                        </a>
                    <?php endforeach; ?>
                </div>
            </div> <!-- end card-body -->
        </div>
        <?php
    }


    /**
     * Render the task card contextual menu.
     *
     */
     public function renderTaskMenu(bool $card=false): string {
        $menuItems = [];

        // Add 'Share URL' menu item at the top
        $menuItems[] = sprintf(
            '<a href="javascript:void(0);" class="dropdown-item" onclick="copyTaskUrl();"><i class="ri-share-line me-1"></i>' . __('Share URL', 'decker') . '</a>'
        );

        // Add divider after Share URL
        $menuItems[] = '<div class="dropdown-divider"></div>';

        if (!$card) {
            // Add 'Edit' menu item
            $menuItems[] = sprintf(
                '<a href="%s" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="%d" class="dropdown-item"><i class="ri-edit-box-line me-1"></i>' . __('Edit', 'decker') . '</a>',
                esc_url(add_query_arg(
                    ['decker_page' => 'task', 'id' => esc_attr($this->ID)],
                    home_url('/')
                )),
                esc_attr($this->ID)
            );
        }

        if ( current_user_can( 'manage_options' ) ) { 
            // Add 'Edit in WordPress' menu item
            $menuItems[] = sprintf(
                '<a href="%s" class="dropdown-item" target="_blank"><i class="ri-wordpress-line me-1"></i>' . __('Edit in WordPress', 'decker') . '</a>',
                esc_url(get_edit_post_link($this->ID))
            );
        }

        // Add 'Archive' menu item
        $menuItems[] = sprintf(
            '<a href="javascript:void(0);" class="dropdown-item archive-task" data-task-id="%d"><i class="ri-archive-line me-1"></i>' . __('Archive', 'decker') . '</a>',
            esc_attr($this->ID)
        );

        if (!$card) {

            // Add 'Assign to me' and 'Leave' menu items based on assigned users
            $isAssigned = in_array(get_current_user_id(), array_column($this->assigned_users, 'ID'));
            $menuItems[] = sprintf(
                '<a href="javascript:void(0);" class="dropdown-item assign-to-me" data-task-id="%d" style="%s"><i class="ri-user-add-line me-1"></i>' . __('Assign to me', 'decker') . '</a>',
                esc_attr($this->ID),
                $isAssigned ? 'display: none;' : ''
            );

            // Add 'Leave' menu item
            $menuItems[] = sprintf(
                '<a href="javascript:void(0);" class="dropdown-item leave-task" data-task-id="%d" style="%s"><i class="ri-logout-circle-line me-1"></i>' . __('Leave', 'decker') . '</a>',
                esc_attr($this->ID),
                !$isAssigned ? 'display: none;' : ''
            );

            // Add 'Mark for today' / 'Unmark for today' menu items for assigned users with 'today' flag
            if ($isAssigned) {
                $isMarkedForToday = false;
                foreach ($this->assigned_users as $user) {
                    if ($user->ID == get_current_user_id() && !empty($user->today)) {
                        $isMarkedForToday = true;
                        break;
                    }
                }

                $menuItems[] = sprintf(
                    '<a href="javascript:void(0);" class="dropdown-item mark-for-today" data-task-id="%d" style="%s"><i class="ri-calendar-check-line me-1"></i>' . __('Mark for today', 'decker') . '</a>',
                    esc_attr($this->ID),
                    $isMarkedForToday ? 'display: none;' : ''
                );

                $menuItems[] = sprintf(
                    '<a href="javascript:void(0);" class="dropdown-item unmark-for-today" data-task-id="%d" style="%s"><i class="ri-calendar-close-line me-1"></i>' . __('Unmark for today', 'decker') . '</a>',
                    esc_attr($this->ID),
                    !$isMarkedForToday ? 'display: none;' : ''
                );
            }
        }

        if (!$card) {
            // Generate dropdown HTML for card
            return sprintf(
                '<div class="dropdown float-end mt-2">
                    <a href="#" class="dropdown-toggle text-muted arrow-none" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="ri-more-2-fill fs-18"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">%s</div>
                </div>',
                implode('', $menuItems)
            );

        } else {

            return sprintf(
                '<div class="dropdown float-end mt-2">
                    
                    <div class="dropdown-menu dropdown-menu-end">%s</div>
                </div>',
                implode('', $menuItems)
            );

        }
    }

}

