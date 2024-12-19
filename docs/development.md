# Development Guide

This guide covers everything you need to know to develop with or contribute to Decker.

## Quick Start

1. Clone the repository:
```bash
git clone https://github.com/ateeducacion/wp-decker.git
```

2. Start the development environment:
```bash
make up
```

This will start a Dockerized WordPress instance at http://localhost:8888 with:
- Username: admin
- Password: password

## Hooks Reference

Decker provides several action and filter hooks to extend its functionality.

### Actions

#### `decker_task_created`
Fired after a new task is created.
```php
do_action('decker_task_created', $task_id);
```
- `$task_id` (int) The ID of the newly created task.

#### `decker_task_updated`
Fired after a task is updated.
```php
do_action('decker_task_updated', $task_id);
```
- `$task_id` (int) The ID of the updated task.

#### `decker_stack_transition`
Fired when a task moves between stacks.
```php
do_action('decker_stack_transition', $task_id, $source_stack, $target_stack);
```
- `$task_id` (int) The task ID
- `$source_stack` (string) Original stack
- `$target_stack` (string) New stack

#### `decker_task_completed`
Fired when a task moves to "done".
```php
do_action('decker_task_completed', $task_id, $target_stack);
```
- `$task_id` (int) The task ID
- `$target_stack` (string) Always "done"

#### `decker_user_assigned`
Fired when assigning a user to a task.
```php
do_action('decker_user_assigned', $task_id, $user_id);
```
- `$task_id` (int) The task ID
- `$user_id` (int) The assigned user's ID

### Filters

#### `decker_save_task_send_response`
Controls AJAX response after saving.
```php
apply_filters('decker_save_task_send_response', true);
```
- `$send_response` (bool) Whether to send response
- **Default:** `true`

## Example Hook Usage

```php
// Notify users when task completes
add_action('decker_task_completed', function($task_id, $target_stack) {
    $task = get_post($task_id);
    $users = get_post_meta($task_id, 'assigned_users', true);
    
    foreach($users as $user_id) {
        notify_user($user_id, sprintf(
            'Task "%s" completed',
            $task->post_title
        ));
    }
}, 10, 2);

// Log stack transitions
add_action('decker_stack_transition', function($task_id, $source, $target) {
    error_log(sprintf(
        'Task #%d: %s -> %s',
        $task_id,
        $source,
        $target
    ));
}, 10, 3);
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and ensure coding standards:
```bash
composer test
composer phpcs
```
5. Submit a pull request

## Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- Use meaningful variable and function names
- Add comments for complex logic
- Write unit tests for new features

## Need Help?

- Check existing [GitHub Issues](https://github.com/ateeducacion/wp-decker/issues)
- Open a new issue with detailed information
- Contact the ATE development team
