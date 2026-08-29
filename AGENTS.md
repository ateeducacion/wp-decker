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
- **Before every `git push` / opening a PR**: run at least `make lint`, `make test` and **`make check-untranslated`** (see the pre-push gate below).

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

## Translations (mandatory)

- Every time you add, change or remove a user-facing string (PHP `__()`/`_e()`/`_n()`/`_x()`, JavaScript strings localized via `wp_localize_script`, etc.) you MUST update the translation catalogues **in the same change set** — never defer this to a follow-up commit:
  1. Run `make check-untranslated` (or `composer check-untranslated`) to regenerate `languages/decker.pot`, refresh `languages/decker-es_ES.po` and rebuild the `.mo` files.
  2. Translate every new `msgid` into Spanish (project default user-facing language). The `untranslated` step fails the build if any `msgstr ""` is left for `decker-es_ES.po`, so the PR cannot be considered done until `msgattrib --untranslated languages/decker-es_ES.po` outputs nothing.
  3. Commit `languages/decker.pot`, `languages/decker-es_ES.po` and `languages/decker-es_ES.mo` together with the code that introduced the strings.
- Plural strings must use `_n( 'singular', 'plural', $count, 'decker' )` and add an `msgid_plural` block with both `msgstr[0]` and `msgstr[1]` translated.
- Strings exposed to JavaScript must travel through `wp_localize_script()` so they end up inside the `.pot`; do not hard-code English text in JS files.
- **Every i18n call that contains a placeholder (`%s`, `%d`, `%1$s`, …) MUST be preceded by a `translators:` comment** describing each placeholder. PHPCS (`WordPress.WP.I18n.MissingTranslatorsComment`) fails CI without it. Use `/* translators: ... */` (or `// translators: ...`) directly above the call. Example:
  ```php
  /* translators: %d is the number of comments on the task. */
  $title = sprintf( _n( '%d comment', '%d comments', $count, 'decker' ), $count );
  ```
  When the call is inside an HTML attribute, hoist the result into a PHP variable in a regular `<?php ... ?>` block first, then echo the variable in the attribute — splitting the `<?php` block inside an attribute leaks indentation whitespace into the rendered HTML.

### Pre-push gate (agents — mandatory)

**Never push or open a PR without verifying translations.** CI runs `make check-untranslated` (`.github/workflows/ci.yml`) and **fails the job** if any Spanish `msgstr` is empty.

Before `git push` or `gh pr create`:

1. Search the diff for new/changed `__()` / `_e()` / `_n()` / `_x()` strings (including strings passed to `wp_localize_script()`).
2. Update `languages/decker-es_ES.po` in the **same commit** (Spanish `msgstr` filled in — not left blank), together with `languages/decker.pot` and `languages/decker-es_ES.mo`.
3. Run **`make check-untranslated`** and confirm it exits 0.
4. If it fails, fix the empty `msgstr` entries (and re-run) before pushing.

Do not treat “tests passed” as enough for a push: PHPUnit does not catch missing `.po` entries. Untranslated strings are a **CI blocker**, same as lint failures.

## PHP docblock formatting

- Align `@param` blocks so all variable names start at the same column, leaving exactly one space between the longest type name and its `$variable`. Example for a function whose longest type is `DateTime`:
  ```php
  /**
   * @param int      $task_id        Target task post ID.
   * @param int[]    $assigned_users Author candidates.
   * @param DateTime $start_date     Earliest plausible date.
   */
  ```
  Adding extra spaces before `$task_id` triggers `Squiz.Commenting.FunctionComment.SpacingAfterParamType` — PHPCS expects the minimum spacing that keeps every `$variable` aligned with the longest type, not more.

## Skills

Recurring procedures live as skills under three host directories:

- `.agents/skills/` — GitHub Copilot, Codex, Cursor and the other agents that share this path
- `.claude/skills/` — Claude Code
- `.grok/skills/` — Grok

Install and refresh them with the GitHub CLI (`gh skill add` is an alias of
`gh skill install`). Repeat for each host you care about:

```bash
gh skill add WordPress/agent-skills wp-performance --agent github-copilot
gh skill add WordPress/agent-skills wp-performance --agent claude-code
gh skill add WordPress/agent-skills wp-performance --agent grok
gh skill update --all
```

`gh skill` copies the skill into each host directory and injects source
metadata into the `SKILL.md` frontmatter so later updates work. Older Claude
Code entries remain as **symlinks** into `.agents/skills/`; newer ones are
copies. Do not convert one layout into the other by hand, and never duplicate
a skill by copying `SKILL.md` yourself.

| Skill | Read it before | Origin |
| --- | --- | --- |
| `wp-plugin-development` | Touching hooks, activation/uninstall, the Settings API, options, cron or release packaging | [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills), GPL-2.0-or-later |
| `wp-rest-api` | Adding or debugging routes: `register_rest_route`, `permission_callback`, schema/args, `register_meta`, `show_in_rest` (the `Decker_Tasks_Rest_*` classes) | idem |
| `wp-plugin-directory-guidelines` | Editing `readme.txt`, license headers or plugin naming — this is what `make check-plugin` enforces | idem |
| `blueprint` | Editing `blueprint.json` or the Playground preview | idem |
| `wp-performance` | Profiling or improving backend performance (WP-CLI profile/doctor, autoload, object cache, cron, HTTP API) | idem |
| `wp-project-triage` | Inspecting what kind of WordPress repo this is before changing tooling or layout | idem |
| `wp-plugin-security` | Writing or reviewing code that handles input, output, AJAX/REST, capabilities or files | [`fernandotellado/ai-skills`](https://github.com/fernandotellado/ai-skills), GPL-2.0-or-later |
| `security-audit` | Hunting vulnerabilities and validating findings | [`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill) |

All of them are **third party and vendored verbatim**. Do not reformat or edit
them: diverging from upstream makes `gh skill update` harder. Fix the problem
upstream and re-install instead.

`skills-lock.json` is leftover from an earlier installer and only lists
`security-audit`. Provenance for skills fetched with `gh skill` lives in each
`SKILL.md` frontmatter.

Skills, `AGENTS.md` and `CLAUDE.md` are excluded from the release ZIP via
`.gitattributes`.

## Aider-specific usage

- Always load `AGENTS.md` as conventions file: e.g. `/read AGENTS.md` or via config.
- Do not expect Aider to modify `AGENTS.md` or `README.md` contents.
- Use `/ask` mode to plan large changes, then use `/code` or `/architect` to apply.
- Review every diff Aider produces, especially in architect mode before accepting.
- After planning, say “go ahead” to proceed.
- Avoid adding unnecessary files to the chat—add only those being modified.

