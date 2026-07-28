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
