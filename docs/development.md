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

## Testing

Tests live under `/tests/` and use factories.  
Run with `make test`.

## Contributing

1. Create a feature branch.
2. Make your changes following the coding standards and translation rules.
3. Run `make check`.
4. Open a Pull Request.
