<!-- AGENTS.md -->

# Agents Coding Conventions for Plugin “Decker”

These are natural-language guidelines for agents to follow when developing the Decker WordPress plugin.

## Project conventions

- Follow **WordPress Coding Standards**:
  - PHP code: 4 spaces indentation, PSR‑12 style where compatible, proper escaping, sanitization, use WP APIs.
  - Use English for source code (identifiers, comments, docblocks).
  - Use Spanish for user‑facing translations/strings and test assertions to check no untranslated strings remain.

## Testing and development workflow

- Use **TDD** (Test‑Driven Development) with factories to create test fixtures.
- Tests live under `/tests/` and use factory classes.
- Use `make lint` (PHP lint) and `make fix` (beautifier) to enforce standards.
- Use `make test` to run all unit tests.
- Use `make check-untranslated` to detect any untranslated Spanish strings.

## Environment and tools

- Develop plugin within `@wordpress/env` environment.
- Use Alpine‑based Docker containers if setting up with Docker.
- For Linux commands: assume **Ubuntu Server**.
- On macOS desktop (when relevant): use **Homebrew** to install tools.
- Use `vim` as terminal editor, not `nano`.

## Frontend technologies

- In admin or public UI, use **Bootstrap 5** and **jQuery** consistently.
- Keep frontend assets minimal: enqueue properly via WP APIs, use minified versions.

## Code style and structure

- All PHP functions and methods must have English docblock comments immediately before declaration.
- Prefer simplicity and clarity: avoid overly complex abstractions.
- Load translation strings properly (`__()`, `_e()`), text domain declared in main plugin file.
- Keep plugin bootstrap file small (`decker.php`), modularize into separate files/classes with specific responsibility.

## Aider-specific usage

- Always load `AGENTS.md` as conventions file: e.g. `/read AGENTS.md` or via config.
- Do not expect Aider to modify `AGENTS.md` or `README.md` contents.
- Use `/ask` mode to plan large changes, then use `/code` or `/architect` to apply.
- Review every diff Aider produces, especially in architect mode before accepting.
- After planning, say “go ahead” to proceed.
- Avoid adding unnecessary files to the chat—add only those being modified.

