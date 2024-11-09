<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class TaskManager
 *
 * Provides functionalities to manage tasks.
 */
class TaskManager {

    /**
     * Retrieves a task by its ID.
     *
     * @param int $id The ID of the task.
     * @return Task|null The Task object or null if not found.
     */
    public function getTask(int $id): ?Task {
        try {
            return new Task($id);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Retrieves a list of tasks based on the given arguments.
     *
     * @param array $args Query arguments for WP_Query.
     * @return Task[] List of Task objects.
     */
    public function getTasks(array $args = array()): array {
        $default_args = array(
            'post_type' => 'decker_task',
            'post_status' => 'publish',
            'numberposts' => -1
        );
        $query_args = array_merge($default_args, $args);
        $posts = get_posts($query_args);
        $tasks = array();

        foreach ($posts as $post) {
            try {
                $tasks[] = new Task($post);
            } catch (Exception $e) {
                // Log or handle the error if needed
            }
        }

        // echo "<pre>";
        // print_r($args);
        // print_r($tasks);
        // die();


        return $tasks;
    }

    /**
     * Retrieves tasks by their status.
     *
     * @param string $status The status to filter by (e.g., 'publish', 'draft').
     * @return Task[] List of Task objects.
     */
    public function getTasksByStatus(string $status): array {
        return $this->getTasks(array('post_status' => $status));
    }

    /**
     * Retrieves tasks assigned to a specific user.
     *
     * @param int $user_id The user ID to filter tasks by.
     * @return Task[] List of Task objects.
     */
    public function getTasksByUser(int $user_id): array {
        $args = array(
            'meta_query' => array(
                array(
                    'key' => 'assigned_users',
                    'value' => $user_id,
                    'compare' => 'LIKE'
                )
            )
        );

        $tasks = $this->getTasks($args);

        // Additional filtering to ensure only tasks assigned to the user are returned.
        // Filtering serialized data with a LIKE or REGEXP can lead to false positives due to serialization quirks.
        // This extra step ensures we accurately check for the assigned user.
        $filteredTasks = array_filter($tasks, function ($task) use ($user_id) {
            if (is_array($task->assigned_users)) {
                foreach ($task->assigned_users as $assignedUser) {
                    // Compare the user ID directly.
                    if ((int)$assignedUser->ID === $user_id) {
                        return true;
                    }
                }
            }
            return false;
        });

        return $filteredTasks;

    }

    /**
     * Retrieves tasks by stack (custom meta field).
     *
     * @param string $stack The stack to filter tasks by.
     * @return Task[] List of Task objects.
     */
    public function getTasksByStack(string $stack): array {
        $args = array(
            'meta_query' => array(
                array(
                    'key' => 'stack',
                    'value' => $stack,
                    'compare' => '='
                )
            )
        );
        return $this->getTasks($args);
    }



    /**
     * Retrieves tasks by Board (term relation).
     *
     * @param Board $board The board to filter tasks by.
     * @return Task[] List of Task objects.
     */
    public function getTasksByBoard(Board $board): array {
        $args = array(
            'post_type'   => 'decker_task',
            'post_status'    => 'publish',      
            'tax_query'   => array(
                array(
                    'taxonomy' => 'decker_board',
                    'field'    => 'slug',
                    'terms'    => $board->slug,
                ),
            ),      
            'meta_key'    => 'max_priority', // Define field to use in order
            'meta_type' => 'BOOL',
            'orderby'     => array(
                'max_priority' => 'DESC',
                'menu_order'   => 'ASC',
            ),
            'numberposts' => -1,
        );
        return $this->getTasks($args);
    }


    /**
     * Checks if the current user has tasks assigned for today.
     *
     * @return bool True if the user has tasks for today, false otherwise.
     */
    public function hasUserTodayTasks(): bool {
        $user_id = get_current_user_id();
        $args = array(
            'post_type' => 'decker_task',
            'post_status' => 'publish',
            'fields' => 'ids', // Only retrieve IDs for performance optimization
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'assigned_users',
                    'value' => $user_id,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_user_date_relations',
                    'compare' => 'EXISTS' // Only include tasks where the meta key exists
                )
            )
        );

        $tasks = $this->getTasks($args);

        // Further filter tasks to check if any have a user_date relation for today
        foreach ($tasks as $task_id) {
            $task = new Task($task_id);
            if (isset($task->meta['_user_date_relations'][0])) {
                $user_date_relations = maybe_unserialize($task->meta['_user_date_relations'][0]);

                if (is_array($user_date_relations)) {
                    $today = (new DateTime())->format('Y-m-d');
                    foreach ($user_date_relations as $relation) {
                        if (isset($relation['user_id'], $relation['date']) &&
                            $relation['user_id'] == $user_id &&
                            $relation['date'] === $today) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Retrieves tasks assigned to the current user within a specified number of previous days.
     *
     * @param int $days Number of days to look back.
     * @return Task[] List of Task objects within the specified time range.
     */
    public function getUserTasksForPreviousDays(int $days): array {
        $user_id = get_current_user_id();
        $args = array(
            'post_type' => 'decker_task',
            'post_status' => 'publish',
            'fields' => 'ids', // Only retrieve IDs for performance optimization
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'assigned_users',
                    'value' => $user_id,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_user_date_relations',
                    'compare' => 'EXISTS' // Only include tasks where the meta key exists
                )
            )
        );

        $tasks = $this->getTasks($args);

        echo "<pre>";
        print_r($days);
        // print_r($args);
        // print_r($tasks);
        die();        



        return $tasks;



        // Filter tasks to check if they have a user_date relation within the specified number of past days
        $filteredTasks = array_filter($tasks, function ($task) use ($user_id, $days) {
            if (isset($task->meta['_user_date_relations'][0])) {
                $user_date_relations = maybe_unserialize($task->meta['_user_date_relations'][0]);

                if (is_array($user_date_relations)) {
                    $today = new DateTime();
                    foreach ($user_date_relations as $relation) {
                        if (isset($relation['user_id'], $relation['date']) &&
                            $relation['user_id'] == $user_id) {
                            $relation_date = DateTime::createFromFormat('Y-m-d', $relation['date']);
                            if ($relation_date && $relation_date >= (clone $today)->modify("-$days days") && $relation_date < $today) {
                                return true;
                            }
                        }
                    }
                }
            }
            return false;
        });

        return $filteredTasks;
    }

}
