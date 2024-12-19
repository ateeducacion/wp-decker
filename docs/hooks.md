# Hooks Reference

Decker provides several action and filter hooks that you can use to extend or modify its functionality. This page documents all available hooks.

## Task Hooks

### Actions

#### `decker_task_created`
Fired after a new task is created.

**Parameters:**
- `$task_id` (int) The ID of the newly created task.

```php
do_action('decker_task_created', $task_id);
```

#### `decker_task_updated`
Fired after a task is updated.

**Parameters:**
- `$task_id` (int) The ID of the updated task.

```php
do_action('decker_task_updated', $task_id);
```

#### `decker_stack_transition`
Fired when a task is moved from one stack to another.

**Parameters:**
- `$task_id` (int) The ID of the task.
- `$source_stack` (string) The original stack.
- `$target_stack` (string) The new stack.

```php
do_action('decker_stack_transition', $task_id, $source_stack, $target_stack);
```

#### `decker_task_completed`
Fired when a task is moved to the "done" stack.

**Parameters:**
- `$task_id` (int) The ID of the task.
- `$target_stack` (string) The target stack (always "done").

```php
do_action('decker_task_completed', $task_id, $target_stack);
```

#### `decker_user_assigned`
Fired when a user is assigned to a task.

**Parameters:**
- `$task_id` (int) The ID of the task.
- `$user_id` (int) The ID of the assigned user.

```php
do_action('decker_user_assigned', $task_id, $user_id);
```

## Example Usage

Here's an example of how to use these hooks in your code:

```php
// Send notification when task is completed
add_action('decker_task_completed', function($task_id, $target_stack) {
    $task = get_post($task_id);
    $assigned_users = get_post_meta($task_id, 'assigned_users', true);
    
    foreach($assigned_users as $user_id) {
        // Send notification to each assigned user
        notify_user($user_id, sprintf(
            'Task "%s" has been completed',
            $task->post_title
        ));
    }
}, 10, 2);

// Log stack transitions
add_action('decker_stack_transition', function($task_id, $source_stack, $target_stack) {
    error_log(sprintf(
        'Task #%d moved from %s to %s',
        $task_id,
        $source_stack,
        $target_stack
    ));
}, 10, 3);
```

## Filters

Currently, Decker provides one filter:

### `decker_save_task_send_response`
Controls whether to send an AJAX response after saving a task.

**Parameters:**
- `$send_response` (bool) Whether to send the response.

**Default:** `true`

```php
apply_filters('decker_save_task_send_response', true);
```

Example usage:

```php
// Disable AJAX response for specific users
add_filter('decker_save_task_send_response', function($send_response) {
    if (current_user_can('administrator')) {
        return false;
    }
    return $send_response;
});
```
