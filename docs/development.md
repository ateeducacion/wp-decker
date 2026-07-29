# Development

## Quick start

```bash
git clone https://github.com/ateeducacion/wp-decker.git
cd wp-decker
composer install
make up
```

WordPress: http://localhost:8888  
Credentials: `admin` / `password`

## Useful Make targets

| Command | Description |
|---------|-------------|
| `make up` | Start the development environment |
| `make down` | Stop it |
| `make test` | Run PHPUnit tests |
| `make lint` | PHP_CodeSniffer |
| `make fix` | Auto-fix coding standards |
| `make check` | Full check (lint + tests + plugin-check) |
| `make pot` | Regenerate translation template |
| `make package VERSION=x.y.z` | Build a release ZIP |
| `make help` | List all targets |

## Coding standards

- WordPress Coding Standards
- English for code, comments and identifiers
- Spanish for user-facing strings
- Every new/changed string must update the `.pot` / `.po` / `.mo` in the same commit (`make check-untranslated`)

See `AGENTS.md` and `CONVENTIONS.md` for detailed agent and project conventions.

## Available hooks

### Actions

```php
do_action( 'decker_task_created', $task_id );
do_action( 'decker_task_updated', $task_id );
do_action( 'decker_stack_transition', $task_id, $source_stack, $target_stack );
do_action( 'decker_task_completed', $task_id, $target_stack );
do_action( 'decker_user_assigned', $task_id, $user_id );
```

### Filters

```php
apply_filters( 'decker_save_task_send_response', true );
```

## What counts as a stable surface

The hooks above, plus `Decker_Tasks::create_or_update_task()`, are what integrations
should build on. Everything else — class names, method names, which class registers
which callback — is internal and gets reorganised as classes grow.

Most public methods on these classes are public only because WordPress needs to call
them as hook callbacks. Calling them directly, or unhooking them by name, is not
supported.

### Class reorganisations

Symbols that have moved, so an integration that referenced the old location can find
the new one. None of these changed behaviour; only their owner changed.

| Was | Is now |
| --- | --- |
| `Decker_Boards::add_color_field()` / `edit_color_field()` / `save_color_meta()` | `Decker_Board_Term_Fields` (extends `Decker_Term_Color_Field`) |
| `Decker_Labels::add_color_field()` / `edit_color_field()` / `save_color_meta()` | `Decker_Term_Color_Field` |
| `Decker_Kb::track_last_editor()` / `get_last_editor()` / `get_latest_revision_id()` / `get_revision_admin_url()` | `Decker_Kb_Revisions` |
| `Decker_Events::add_meta_boxes()` / `display_users_meta_box()` / `render_event_details_meta_box()` | `Decker_Event_Meta_Box` |
| `Decker_Events::hide_visibility_options()` / `add_custom_columns()` / `render_custom_columns()` | `Decker_Event_Admin_Screen` |
| `Decker_Admin_Settings::*_render()` (16 field renderers) / section callbacks | `Decker_Admin_Settings_Fields` (single public `render()` dispatcher) |
| `Decker_Admin_Settings` private validators | `Decker_Admin_Settings_Validator::validate()` |
| `Decker_Notification_Handler::add_notification_to_user()` / `remove_notification_from_user()` | `Decker_Notification_Store` |
| `Decker_Notification_Handler::MAX_NOTIFICATIONS` (public constant) | `Decker_Notification_Store::MAX_NOTIFICATIONS` |
| `Decker_Notification_Handler::heartbeat_received()` / `modify_heartbeat_settings()` / `ajax_*()` | `Decker_Notification_Ajax` |
| `Decker_Events` meta-saving pipeline (bodies; `process_and_save_meta()` / `save_event_meta()` stay as public delegators) | `Decker_Event_Meta_Saver` |
| `Decker_Email_To_Post` attachment pipeline (`upload_attachment()` and helpers) | `Decker_Email_Attachment_Uploader` |
| `Decker_Email_To_Post` board directive resolution | `Decker_Email_Board_Resolver` |
| `Decker_Demo_Data` task/comment seeding and randomness helpers | `Decker_Demo_Tasks`, `Decker_Demo_Randomizer` |
| `Decker_Calendar::handle_ical_request()` / `add_ical_endpoint()` and access checks | `Decker_Calendar_Ical_Feed` |
| `Decker_Calendar::get_cached_ical()` / `flush_cache_*()` | `Decker_Calendar_Cache` |
| `Decker_Kb::save_article()` and the write path | `Decker_Kb_Article_Writer` |
| `Decker_Kb::reorder_articles()` and sibling renumbering | `Decker_Kb_Reorder` |
| `TaskManager` "for today" query bodies (public entry points stay as delegators) | `Decker_Task_Today_Query`, `Decker_Task_Date_Relations` |
| `Decker_Tasks::clone_task()` and its private readers | `Decker_Task_Clone` |
| `Decker_Tasks::merge_tasks()` and the merge pipeline | `Decker_Task_Merge` |
| `Decker_Tasks` lock REST routes + `refresh_task_lock_heartbeat()` | `Decker_Tasks_Rest_Locks` |
| `Decker_Tasks` today REST routes (`handle_task_today()`, mark/unmark relations) | `Decker_Tasks_Rest_Today` |
| `Decker_Tasks` field-op REST routes + REST insert guards | `Decker_Tasks_Rest_Ops` |
| `Decker_Tasks::search_tasks()` / clone + merge REST transport | `Decker_Tasks_Rest_Tools` |
| `Decker_Tasks::make_permission_callback()` / `lock_error_response()` | `Decker_Tasks_Rest_Support` (public statics) |
| `Decker_Tasks` order/stack engine (`update_task_stack_and_order()`, `handle_fix_order()`, `reorder_tasks_in_stack()`, `get_new_task_order()`) | `Decker_Task_Order` |
| `Decker_Tasks` order hook reactions (post-data filter, board/stack term changes) | `Decker_Task_Order_Hooks` |
| `Decker_Public::enqueue_scripts()` body | `Decker_Public_Assets` |

Note that unhooking one of these by name fails **silently** rather than fatally:

```php
// No longer matches anything; the field still renders.
remove_action( 'decker_board_add_form_fields', array( $boards, 'add_color_field' ) );
```

To suppress a field, unhook the current owner, or filter the markup it produces.

## Testing

Tests live under `/tests/` and use factories.  
Run with `make test`.

## Contributing

1. Create a feature branch.
2. Make your changes following the coding standards and translation rules.
3. Run `make check`.
4. Open a Pull Request.
