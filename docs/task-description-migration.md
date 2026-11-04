# Classic task descriptions: migration and recovery

Task descriptions remain HTML in `wp_posts.post_content`. WordPress's classic
editor is the sole description editor. Decker's policy is
`wp_kses_allowed_html( 'post' )` plus `li[data-list]`; only `checked` and
`unchecked` are checklist states. Ordinary lists have no checklist attribute.
Subtasks in `_decker_subtasks` are independent and are never converted.

## Before updating a site

Take a complete database backup. The WordPress WXR export is useful for inspecting
historical HTML, but is not a complete SQL backup, contains no options/revisions,
and does not prove coverage of every database post status.

The plugin never converts a site during a web request. An existing site without
`decker_task_description_content_version=1` stays read-only for description
writes until the operator completes migration. A new empty site starts at version
1. The retired `task_editor_type` value is ignored and removed on the next normal
settings save without resetting other settings.

## Read-only simulation

Use a database account with SELECT access, or an isolated copy with an audited
bootstrap. Skip the normal plugin/theme bootstrap so Decker's existing demo,
calendar and rewrite/version initialization cannot write before the command:

```sh
wp --url=<site> --skip-plugins --skip-themes \
  --require=wp-content/plugins/decker/includes/class-decker-wpcli.php \
  decker migrate-descriptions --dry-run --batch-size=100
```

`--skip-plugins` does not skip mu-plugins or drop-ins. Those must also be audited
or prevented from writing by the connection/isolated environment. The executor's
dry run writes no backups, journal, content, dates, revisions or completion flags.
Do not use `--skip-plugins` for application: normal WordPress/Decker hooks are
required for revision handling and cache invalidation.

## Controlled update window

1. Block all HTTP entry points at the server/proxy, including wp-admin, AJAX,
   REST, email endpoints and HTTP cron. A banner or disabled editor is insufficient.
2. Pause external CLI writers, importers, mail queues and scheduled jobs.
3. Drain in-flight requests/jobs. Install the updated plugin while writers remain
   paused. Keep readers closed if they cannot be separated reliably.
4. Run the command normally with the plugin loaded:

   ```sh
   wp --url=<site> decker migrate-descriptions --writers-paused --batch-size=100
   wp --url=<site> decker verify-descriptions
   ```

5. Require no task errors and an empty `pending` list. Inspect representative
   descriptions with a restricted maintenance session before reopening traffic.
6. Reopen HTTP and resume writers only after verification succeeds.

`--writers-paused` acknowledges the operational window; it does not stop external
processes. Run each multisite site separately with `--url`. State and executor
locks are isolated by site table prefix; there is no network-wide completion flag.

## Storage and recovery protocol

Every task is enumerated directly by post type, without a status or visibility
condition, using bounded `ID > cursor` batches. Archived, private, draft,
auto-draft, future, pending, trash, hidden, classic and empty tasks are included.

Before any conversion, `_decker_task_description_backup_v1_<id>` is created as a
non-autoloaded, unregistered site option. It stores the exact original content in
base64, byte length, SHA-256, site/task IDs and format version. It is verified on
read and never overwritten, including after interruption or rollback. It is not
exposed through task REST/custom fields and survives deletion of the task and
removal of the executor. No automatic uninstall or upgrade cleanup removes it.

The separate `_decker_task_description_migration_v1_<id>` journal records prepared
input/output hashes, planned dates, committed state and final task fingerprint.
An interruption after content commit but before journal advancement is reconciled
against the planned output. A mismatch is a conflict, not permission to overwrite.
Repeated complete runs do not rewrite content, dates or revisions.

The executor has a connection-owned MySQL lock, distinct from task edit locks.
Writes use `wp_update_post()` with slashed content only, retain unrelated fields
and metadata, preserve existing revisions during the operation, and keep normal
cache hooks. The full task writer is deliberately not used. Creation, assignment
and completion notifications are not emitted.

Changed content advances modification time to at least one second after its
previous value; local time is derived from GMT. This changes iCal timestamps and
can change modification-based ordering ties, while stored `menu_order` is intact.
Unchanged tasks keep their dates. Committed conversions rotate the existing
generation token, preserving its owner/time, so old full-form saves are rejected
even when the user edits only the title. See [ADR001](adr/001-task-edit-concurrency.md).

## Rollback

Keep all writers paused and coordinate the plugin/content versions:

```sh
wp --url=<site> decker restore-descriptions --task-id=<id> --writers-paused
```

Restoration requires the post/meta/taxonomy fingerprint to match the completed
migration. Any later edit, including title-only, refuses automatic restoration.
There is no force/reset-backup option. Only the original description bytes are
restored; the modification time advances and old forms are invalidated. The
completion version is cleared. Do not reopen classic editing over restored Quill:
either rerun a corrected migration or return to a matching previous plugin version.

Do not repair ambiguous original content by replacing its initial backup. Resolve
the converter with a reproducing fixture, then resume. If the content changed
since backup, reconcile it explicitly from the database backup and journal.

## Permanent legacy support and executor retirement

Recognised Quill UI spans are removed only when empty and belonging to a known
list item. Lists become real HTML nesting; paragraph indentation becomes CSS.
Code lines become `pre/code`, preserving line boundaries, spaces and tabs.
Alignment, direction, known size/font/color classes become standard safe HTML/CSS.
Missing indentation or code boundaries cannot be reconstructed. Unknown,
malformed or ambiguous legacy structures fail visibly and retain their original
backup/content. Empty checked items and existing image-without-source anomalies
are not silently erased by the converter.

Native revision restores normalize the content written to the parent, preserve
the original historical revision row and invalidate open forms. The task-only
HTML policy/normalizer and version guard remain permanent after the executor.

Do not remove the executor until managed sites are verified, errors are resolved,
rollback is rehearsed, and an extracted, versioned CLI tool is actually published
and tested against the then-current plugin. Direct upgrades from pre-migration
releases must remain blocked by the permanent version guard with a documented,
tested route through that tool. Retiring code must never delete backups.

## Verification and historical evidence

Anonymous fixtures under `tests/fixtures/task-descriptions/` retain structural
examples from the private 2026-11-04 WXR, not its original text or URLs. Never add
that WXR or raw task text to the repository, PR or test artifacts.

```sh
php tests/tools/check-task-description-export.php /private/export.xml
make test FILTER=DeckerTaskDescription
npm run test:js
make test-e2e
make lint
make check-untranslated
make check-plugin
```

The read-only corpus tool checks idempotence, every list item's semantic type,
parent, state and inline tree, all document text, exact code text, and link/image
attributes. On the supplied export: 3,854 tasks, 1,151 transformed, 2,703 unchanged,
10,770 list items verified and no failures. Backups/restores use byte equality;
editor round trips use structural/semantic equality with collapsible HTML
whitespace normalized. Code whitespace remains exact.

PHPUnit and the browser site share a test database. Run them sequentially and run
`make setup-tests-env` after PHPUnit before a targeted browser invocation. HTTP
targets continue to depend on `make up`. Use the existing wp-env stack and runner.
Record actual WordPress/PHP/TinyMCE/Chromium versions for each compatibility run;
the browser configuration covers Chromium only.

## Supported HTML and checklist actions

The contract is WordPress post-context safe HTML, not `unfiltered_html`:
`h1`–`h6`, `hr`, paragraphs, quotations, ordinary `ul`/`ol` (including `start`),
links, images, tables, `pre/code` and safe inline formatting survive. WordPress
still restricts protocols, event attributes and CSS. For example:

```html
<h2>Summary</h2><hr>
<p style="text-align: right"><a href="https://example.invalid/?a=1&amp;b=2">Link</a></p>
<ul><li data-list="unchecked"><strong>Pending</strong></li><li data-list="checked"><br></li></ul>
<ol start="5"><li>Ordinary fifth item</li></ol>
<pre><code>  whitespace and line breaks remain meaningful</code></pre>
```

The checklist toolbar toggles the caret's list at its direct-child level;
descendant lists retain their independent states. A selection across different
lists makes no conversion. Creating a checklist from a paragraph changes only
new items even if TinyMCE merges adjacent lists. The item button and
Ctrl+Shift+Space (Cmd+Shift+Space on macOS) toggle the caret's item, expose pressed
state, and use the normal undo history. Enter splits into a new pending item,
retains the original item's state, and exits an empty item through the native
list behavior. Existing empty checked/pending items are retained when opened or
edited. Unsupported `data-list` values are never rendered as checkboxes.

The parser uses PHP DOM/libxml, available on PHP 8.3, with network loading
disabled. Verify the DOM extension before migration; a missing parser or ambiguous
legacy structure fails visibly. No PHP 8.4 `Dom\HTMLDocument` or WordPress HTML
Processor newer than the supported minimum is required.

## Test matrix

| Risk | Scenario | Level / file | Observable assertion |
| --- | --- | --- | --- |
| Lost historical content | All 16 anonymous fixtures, blank marked items, inline HTML, image, indentation, code lines, Unicode, entities and URLs | Unit: `tests/unit/includes/DeckerTaskDescriptionConverterTest.php` | Each item's parent/type/state/inline tree and all text/resources/code remain equal; output is idempotent. |
| Invented or discarded structure | Unknown formats, malformed lists, orphan indentation, nonempty/unfamiliar UI spans | Same unit suite | Exception; no silently repaired output. |
| Corpus-specific regression | Entire private WXR, never imported | `tests/tools/check-task-description-export.php` | 3,854 descriptions and 10,770 list items correspond individually; no loss, duplication or idempotence errors. |
| Incomplete coverage | Multiple bounded batches, every status, hidden/classic/empty tasks | Integration: `tests/integration/DeckerTaskDescriptionMigrationTest.php` | Exact backup for every ID and no pending task before completion. |
| Defaults or side effects | Missing hidden/assignee meta, subtasks, attachments, comments, metadata and taxonomies | Same integration suite | Unrelated data and its absence remain identical; no Decker domain notifications. |
| Unrecoverable failure | Backup/write/invalidation failure and interruption after backup or content commit | Same integration suite | Immutable backup; visible journal error; resume without a second content/date/revision write. |
| Concurrent executors | Separate database connection owns the site lock | Same integration suite | Second executor fails without touching tasks. |
| False simulation | Raw posts/options/meta snapshots before and after dry run | Same integration suite | Byte-identical database rows; no backup, journal or content-version changes. |
| Unsafe HTML or early filtering | Full AJAX form, native REST, Abilities, clone, merge, HTML email, user without `unfiltered_html` | `DeckerTaskDescriptionPolicyTest.php`, `tests/unit/includes/DeckerEmailToPostTest.php` | Safe structure and individual states survive; scripts/events/protocol/CSS attacks do not. |
| Broken recovery/concurrency | Exact rollback, later title edit, old revisions, disabled revisions, old generation and `expected_modified` | Migration integration suite plus existing lock/revision suites | Exact original bytes; later edit refuses rollback; old forms/ability writes fail; original revisions survive. |
| Site leakage | Two sites in a multisite test run | Migration integration suite (`WP_MULTISITE=1`) | Backups, locks and completion version remain site-local. |
| Checklist recognition and scope | Invalid states, child vs descendant, merged adjacent list, Enter undo snapshot | JS: `tests/js/tinymce-checklist.test.js` | Only valid states toggle; unrelated levels/items remain unchanged. |
| Editor serialization | Real migration, title-only save, Visual→Text and Text→Visual, eight historical fixtures including 189,084 characters | Browser: `tests/e2e/specs/task-description-editor.spec.js` | Specific save result succeeds; persisted HTML and reloaded semantic structure agree. |
| Actual list behavior | Create/toggle, Enter in middle/end, indentation, rich paste, empty-list exit, undo/redo and ordinary lists | Same browser file | Each item's identity, hierarchy and state survive save/reload. |
| Modal lifecycle | Close/reopen, held real init callback, links/media focus/insertion, stale pre-migration form | Same browser file | One live editor, no orphan, real media insertion persists, stale save returns 409. |
| Read-only regression | Archived task, other owner, takeover and stale same-user tab | Same browser file, `task-locking.spec.js`, `task-out-of-band-invalidation.spec.js` | Description/checklist/Text/media cannot mutate; server still rejects stale saves. |
| Other features | Knowledge base, comments, attachments and independent subtasks | Same browser file and existing E2E suites | Each feature keeps its own editor/storage and continues saving. |

Browser assertions read the exact save's application result, then the stored
content and a reload. The modal's real HTTP save is proxied only to capture its
body before Decker's immediate board navigation discards it; no save response is
fabricated. Fixtures are seeded through controlled raw writes and verified before
the real CLI migrator runs, avoiding premature factory sanitization.

WordPress's paragraph conversion (`wpautop`) is disabled only for task editors:
the stored value is already HTML. The extended TinyMCE rule uses `#li[...]` to
retain its native empty-item padding rule. Omitting that flag can delete empty
list items on a subsequent save, including checked items. The synthetic empty
fixtures and repeated-save browser assertions cover both regressions.

The generation-token rejection described here applies to Decker's save paths.
Native wp-admin retains ADR001's explicit residual concurrency limitation. Keep
native task edit screens closed throughout the controlled window and require a
freshly loaded form before reopening native editing; rotating a Decker token is
not a claim that WordPress core enforces it.

For reproducible policy checking without bootstrapping a site or touching its
database, the corpus tool optionally takes a local WordPress core source root:

```sh
php tests/tools/check-task-description-export.php /private/export.xml /local/wordpress-core
```

It loads only KSES/function definitions, records actual core/PHP versions, and
uses the executor's preservation check. Harmless CSS declaration separator
whitespace and URL-edge whitespace are compared semantically; URLs with changed
protocols, removed CSS declarations or discarded nodes still require review.

During compatibility runs, disable automatic core updates and HTTP cron in the
**temporary test override** (`WP_AUTO_UPDATE_CORE=false`,
`AUTOMATIC_UPDATER_DISABLED=true`, `DISABLE_WP_CRON=true`). Otherwise an old core
can update while Chromium runs. Stop the existing stack before switching core,
restart it with `--update`, and verify both the CLI and web container versions.
`DECKER_E2E_WORDPRESS_VERSION=6.1` (or `6.9.5`) also checks the core asset version
served over HTTP at every task editor initialization. Restore the original
configuration afterwards; do not commit a second environment or raise minimums.

Restoration also journals its expected before/after fingerprints before writing.
It clears readiness before touching content. If interrupted after commit, rerun
the same restore command: it verifies the expected result and finishes session
invalidation without writing content/dates/revisions again. A later title/meta/
taxonomy change refuses that resume. Finish a pending restoration before starting
a conversion run. Repeating an already completed restoration is a verified no-op.

DOM is already a runtime dependency through the existing `sabre/dav` and
`sabre/xml` Composer dependencies; this PR introduces no Composer/npm package or
new PHP/WordPress minimum. `pre_wp_unique_post_slug` (available since WordPress
5.1) is temporarily scoped to content writes so core cannot fill a historically
empty slug after the data filter has run.

## Local validation recorded on 2026-11-04

The implementation branch is `feature/classic-task-descriptions`, based on
`3aac34c0348bd4654e958a3ef4ea4f28ea365965`. No production migration, deployment,
GitHub comment or pull request was performed. The private WXR was read only;
its SHA-256 remained
`b9c1e6df66c80e28ec1edf96b7066a15631c029b9009853ddac9afe73393ef28`.

| Command / scope | Observed result |
| --- | --- |
| `make lint` | Passed using the existing PHPCS rules. |
| `make test` on the restored original environment | 1,274 tests, 12,977 assertions, no failures; one multisite-only test skipped in single-site mode. |
| `npm run test:js` | 371 passed, nine opt-in external CAS tests skipped; coverage remained enabled (93.77% lines for instrumented modules). |
| `DECKER_E2E_WORDPRESS_VERSION=7.1.4 make test-e2e` | All 48 Chromium tests passed, including existing comments/subtasks/lock regressions. |
| Final targeted description browser run | All 20 passed after the final checklist attribute-preservation change, including Quicktags and actual checklist paste. |
| `make check-untranslated` | Passed; POT, Spanish PO and compiled catalogs updated. Host PHP 8.5 reports existing vendor/gettext deprecations. |
| `make check-plugin` | Actual Plugin Check output: `Success: Checks complete. No errors found.` Existing project exclusions remain unchanged. |
| `php tests/tools/check-task-description-export.php /private/export.xml` | 3,854 tasks: 1,151 transformed, 2,703 unchanged; 10,770 individual list items verified; zero errors. |
| Same corpus tool with local WordPress 6.1 / 6.9.5 core definitions | Zero preservation/KSES errors. Respectively 73 / 50 harmless serialization normalizations. This offline check used host PHP 8.5.12 and never bootstrapped a database. |
| Isolated real CLI `migrate-descriptions --dry-run --batch-size=2` | All eight inspected WordPress tables unchanged, including options/meta/revisions; before/after snapshot SHA-256 `d247511324a086087e61a9e4aee821980ec9b7c9ace963e2a5b10bac42dd90cf`. |

Compatibility runs used the existing stack, PHP **8.3.36**, Playwright **1.62.0**
and Chromium **151.0.7922.34** on macOS. The original environment serves WordPress
**7.1.4**. Targeted, pinned runs recorded:

| WordPress | Description PHP tests | Separate multisite migration run | Real description browser tests |
| --- | --- | --- | --- |
| 6.1 | 41 tests, 425 assertions, one multisite-only skip | 23 tests, 219 assertions, all passed | 20 passed; later focused Quicktags/paste/stale-form rerun: three passed |
| 6.9.5 | 40 tests, 419 assertions, one multisite-only skip | 23 tests, 219 assertions, all passed | 20 passed |

The 6.9.5 run preceded the last new-site guard assertion and conservative parser
checks; those were subsequently exercised on 6.1 and the original environment.
TinyMCE was **4.9.11**: build `49110-20201110` on 6.1 and `49110-20250317` on
6.9.5/7.1.4. PHP commands for targeted runs were `make test
FILTER=DeckerTaskDescription` and `npx wp-env run tests-cli
--env-cwd=wp-content/plugins/decker env WP_MULTISITE=1 ./vendor/bin/phpunit
--filter DeckerTaskDescriptionMigrationTest --testdox`. Browser commands used
`npm run test:e2e -- tests/e2e/specs/task-description-editor.spec.js` after
`make setup-tests-env`, setting the expected WordPress version for each run.

Initial compatibility attempts were discarded after detecting automatic core
updates over HTTP; the reported runs were repeated with updates disabled and
CLI/web version checks. A sandboxed JavaScript run failed because its existing
local HTTP server could not bind (`EPERM`); the authorized rerun passed. Test
output also contains expected failed DNS requests for anonymized
`example.invalid` fixture resources and harmless Node color-environment warnings.
Temporary compatibility overrides were removed and the original environment was
restored. Dependency manifests, lockfiles, CI and minimum versions are unchanged.

Not run: external live CAS, other browser engines, production migration or a full
PHP regression suite on each old WordPress version. These results do not replace
the controlled maintenance window or an operator's complete database backup.
