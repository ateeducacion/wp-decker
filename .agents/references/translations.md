# Decker translation changes

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
