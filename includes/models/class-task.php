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
                throw new Exception('Invalid post type.');
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
     * Saves the changes made to the task.
     */
    // public function save(): void {
    //     wp_update_post(array(
    //         'ID' => $this->ID,
    //         'post_title' => $this->title,
    //         'post_content' => $this->content,
    //         'post_status' => $this->status,
    //     ));

    //     update_post_meta($this->ID, 'stack', $this->stack);
    //     update_post_meta($this->ID, 'max_priority', $this->max_priority ? '1' : '0');
    //     update_post_meta($this->ID, 'duedate', $this->duedate);
    //     update_post_meta($this->ID, 'assigned_users', $this->assigned_users);
    //     update_post_meta($this->ID, 'attachments', $this->attachments);

    //     // Save labels and board (taxonomies)
    //     if (!empty($this->board)) {
    //         $term = get_term_by('name', $this->board, 'decker_board');
    //         if ($term && !is_wp_error($term)) {
    //             wp_set_post_terms($this->ID, array($term->term_id), 'decker_board');
    //         }
    //     }
    //     if (!empty($this->labels)) {
    //         $term_ids = array();
    //         foreach ($this->labels as $label_name) {
    //             $term = get_term_by('name', $label_name, 'decker_label');
    //             if ($term && !is_wp_error($term)) {
    //                 $term_ids[] = $term->term_id;
    //             }
    //         }
    //         wp_set_post_terms($this->ID, $term_ids, 'decker_label');
    //     }
    // }

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
    public function renderTaskCard() {
        $taskUrl = add_query_arg(
            ['decker_page' => 'task', 'id' => esc_attr($this->ID)],
            home_url('/')
        );
        $priorityBadgeClass = $this->max_priority ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary';
        $priorityLabel = $this->max_priority ? '🔥' : 'Normal';
        $formatted_duedate = $this->getDuedateAsString();
        $relative_time = '<span class="badge bg-danger"><i class="ri-error-warning-line"></i> Undefined date</span>';

        if (!empty($this->duedate)) {
            $relative_time = esc_html($this->getRelativeTime());
        }

        ?>
        <div class="card mb-0" data-task-id="<?php echo esc_attr($this->ID); ?>">
            <div class="card-body p-3">
                <span class="float-end badge <?php echo $priorityBadgeClass; ?>">
                    <span class="label-to-hide"><?php echo $priorityLabel; ?></span>
                    <span class="menu-order label-to-show" style="display: none;">Order: <?php echo esc_html($this->order); ?></span>
                </span>

                <small class="text-muted relative-time-badge" title="<?php echo $formatted_duedate; ?>">
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
                        <?php echo esc_html(get_post_meta($this->ID, 'project', true)); ?>
                    </span>
                    <span class="text-nowrap mb-2 d-inline-block">
                        <i class="ri-discuss-line text-muted"></i>
                        <b><?php echo esc_html(get_comments_number($this->ID)); ?></b> Comments
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
     private function renderTaskMenu(): string {
        $menuItems = [];

        // Add 'Edit' menu item
        $menuItems[] = sprintf(
            '<a href="%s" data-bs-toggle="modal" data-bs-target="#task-modal" data-task-id="%d" class="dropdown-item"><i class="ri-edit-box-line me-1"></i>Edit</a>',
            esc_url(add_query_arg(
                ['decker_page' => 'task', 'id' => esc_attr($this->ID)],
                home_url('/')
            )),
            esc_attr($this->ID)
        );

        if ( current_user_can( 'manage_options' ) ) { 
            // Add 'Edit in WordPress' menu item
            $menuItems[] = sprintf(
                '<a href="%s" class="dropdown-item" target="_blank"><i class="ri-wordpress-line me-1"></i>Edit in WordPress</a>',
                esc_url(get_edit_post_link($this->ID))
            );
        }

        // Add 'Archive' menu item
        $menuItems[] = sprintf(
            '<a href="javascript:void(0);" class="dropdown-item archive-task" data-task-id="%d"><i class="ri-archive-line me-1"></i>Archive</a>',
            esc_attr($this->ID)
        );

        // Add 'Assign to me' and 'Leave' menu items based on assigned users
        $isAssigned = in_array(get_current_user_id(), array_column($this->assigned_users, 'ID'));
        $menuItems[] = sprintf(
            '<a href="javascript:void(0);" class="dropdown-item assign-to-me" data-task-id="%d" style="%s"><i class="ri-user-add-line me-1"></i>Assign to me</a>',
            esc_attr($this->ID),
            $isAssigned ? 'display: none;' : ''
        );

        // Add 'Leave' menu item
        $menuItems[] = sprintf(
            '<a href="javascript:void(0);" class="dropdown-item leave-task" data-task-id="%d" style="%s"><i class="ri-logout-circle-line me-1"></i>Leave</a>',
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
                '<a href="javascript:void(0);" class="dropdown-item mark-for-today" data-task-id="%d" style="%s"><i class="ri-calendar-check-line me-1"></i>Mark for today</a>',
                esc_attr($this->ID),
                $isMarkedForToday ? 'display: none;' : ''
            );

            $menuItems[] = sprintf(
                '<a href="javascript:void(0);" class="dropdown-item unmark-for-today" data-task-id="%d" style="%s"><i class="ri-calendar-close-line me-1"></i>Unmark for today</a>',
                esc_attr($this->ID),
                !$isMarkedForToday ? 'display: none;' : ''
            );
        }

        // Generate dropdown HTML
        return sprintf(
            '<div class="dropdown float-end mt-2">
                <a href="#" class="dropdown-toggle text-muted arrow-none" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="ri-more-2-fill fs-18"></i>
                </a>
                <div class="dropdown-menu dropdown-menu-end">%s</div>
            </div>',
            implode('', $menuItems)
        );
    }

}

