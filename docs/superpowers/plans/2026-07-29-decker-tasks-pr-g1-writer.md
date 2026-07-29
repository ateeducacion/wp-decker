# Decker_Tasks PR G1 — Task Writer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the task write core (7 static methods, 34 summed CCN measured 2026-07-29 with PHPMD itself) into `Decker_Task_Writer`, migrating `create_or_update_task` from 15 positional parameters to a single `$args` array — clearing `ExcessiveParameterList` and `ExcessiveClassComplexity` (82 → 48) from `Decker_Tasks`. Scan 4 → 2.

**Architecture:** `Decker_Task_Writer` is a stateless static class (the `$postarr`/`wp_parse_args` WordPress idiom, per the approved spec decision — single `$args`, NO positional wrapper, NO delegator left behind per the revised policy). It receives `create_or_update_task` (public static) plus the five private statics (`validate_task_fields` 6, `build_task_tax_input` 2, `build_task_post_data` 4, `resolve_assigned_users_and_new` 7, `update_existing_task` 6, `insert_new_task` 1). The migration is minimal-rewrite: the new public method's HEAD is `public static function create_or_update_task( array $args )` + a `$defaults` map + `wp_parse_args` + fifteen extraction lines (`$id = (int) $args['id'];` …), after which the ENTIRE original body continues byte-verbatim (it already works in terms of those fifteen local names); the five helpers keep their positional signatures untouched. Scalar/array keys get casts matching the old signature types (`(int)`, `(string)`, `(bool)`, `(array)`); `duedate`/`creation_date` pass through as `DateTime|null` uncast, exactly as documented today.

**Array keys = the current parameter names, defaults = the effective current ones:** `id` 0, `title` '', `description` '', `stack` '', `board` 0, `max_priority` false, `duedate` null, `author` 0, `responsable` 0, `hidden` false, `assigned_users` array(), `labels` array(), `creation_date` null, `archived` false, `id_nextcloud_card` 0. (Required-ness is unchanged: `validate_task_fields` still rejects empty `title`/`stack`/`board` — the guarantees the campaign gotchas depend on, e.g. labels merge-not-replace and the nextcloud-card handling, live in the verbatim body and helpers.)

**Margin note for the record (goes in the PR body):** `Decker_Tasks` lands at 48/50 WMC — under threshold, 2-point margin. The named relief valve if future growth ever re-fires it: the `get_stack_label`/`get_stack_icon_classes`/`get_stack_icon_html` presentation trio (10 CCN) — deliberately NOT moved now (no alert, YAGNI).

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- The five helpers move BYTE-VERBATIM (bodies + docblocks + signatures). `create_or_update_task` moves body-verbatim below the new head; its docblock is rewritten for the `$args` form (keep the per-key descriptions, one `@param array $args` with a documented key list, same `@return`). Intra-cluster `self::` calls stay `self::` inside Writer. Any other rewrite = BLOCKED naming it.
- Pre-extraction FULL caller sweep of all 7 names (file + repo, case-insensitive, `->`/`::`/callable-array). Expected: `create_or_update_task` at ~46 sites in 13 files (6 production: class-decker-task-ajax-save.php ×1, class-decker-task-clone.php ×2, class-decker-ability-task-store.php ×2, class-decker-email-to-post.php ×1, class-decker-demo-tasks.php ×1 — plus the definition; 7 test files incl. the task FACTORY `tests/includes/class-wp-unittest-factory-for-decker-task.php` ×5, which nearly every suite routes through); helpers expected internal-only. Fresh-count everything; ANY unexpected caller → BLOCKED.
- Call-site migration pattern: each positional call becomes `Decker_Task_Writer::create_or_update_task( array( 'id' => …, 'title' => …, … ) )` carrying EXACTLY the values it passes today, position-mapped to keys (trailing defaults a site omitted stay omitted). No behavioral edits, no value "fixes" while migrating.
- Extraction script: campaign pattern (outward-scanning splice(), `\n\n+(?=\t)` joints). New-class file style: match `class-decker-task-request-reader.php` (no hooks, no constructor needed — statics only; `defined( 'ABSPATH' ) || exit;`). Loader require before `class-decker-tasks.php`.
- No hooks move (the write core is not hooked — verify in the sweep that `define_hooks` is untouched).
- Keyed diff: zero ADDED (Writer lands ≈34 WMC, 7 methods, 1 public — clean); REMOVED exactly `ExcessiveClassComplexity` (82 → 48) and `ExcessiveParameterList` from class-decker-tasks.php, both justified. Remaining snapshot: the Task model's 2 entries. Scan expectation on the PR merge ref: 2.
- Docs debt this PR settles (Step 5): PR F's LOW-1 (three stale test headers: DeckerTaskLockSaveProtectionTest:5, DeckerTasksSaveAjaxLockInTest:3, DeckerTasksSaveMetaLockInTest:3 — retitle to the current owner classes and drop the spent "before … refactored into helpers" clauses), LOW-2/LOW-3/LOW-4 (spec: record the deleted pair in the amendment; strike the pair from "Remaining in the coordinator" at :77; add "superseded for F and G by the 2026-07-29 amendment" pointers at the Compatibility-policy lines :156-158 and the PR G delegator bullet :138-141).
- PHPUnit strictly serial; `rm -rf ~/.pdepend` before every phpmd run. POT churn → "Refresh POT (entry reorder)". BLOCKED-ON-STEP, no silent idling. Branch `refactor/decker-tasks-writer`.

---

### Task 1: Baselines, sweep, extract Writer + migrate all call sites (one unit, one commit)

**Files:**
- Create: `includes/custom-post-types/class-decker-task-writer.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php`, `includes/class-decker.php`, `includes/custom-post-types/class-decker-task-ajax-save.php`, `includes/custom-post-types/class-decker-task-clone.php`, `includes/class-decker-ability-task-store.php`, `includes/class-decker-email-to-post.php`, `includes/class-decker-demo-tasks.php`
- Modify: the fresh-counted test files (expected: DeckerTasksCreateUpdateLockInTest, HooksTest, HooksTasksTest, class-wp-unittest-factory-for-decker-task.php, DeckerTasksSaveAjaxLockInTest, DeckerTasksIntegrationTest, DeckerTaskRevisionsTest — derive the real list)

**Interfaces:**
- Produces: `Decker_Task_Writer::create_or_update_task( array $args ): int|WP_Error` (public static; keys/defaults per the header) + the five private statics unchanged.
- Consumes: WordPress functions; whatever the sweep maps (expected: none of Decker_Tasks's instance services — the cluster is static).

- [ ] **Step 1: Baselines (serial)** — run by path: `DeckerTasksCreateUpdateLockInTest`, `HooksTasksTest`, `DeckerTasksIntegrationTest`, `DeckerTasksTest`; record OK lines. PHPMD snapshot → `/tmp/pr-g1/phpmd-before.txt` (expect 4 lines). Per-method CCN of the 7 methods via a reportLevel-1 CyclomaticComplexity ruleset (expect sum 34; if the Writer projection lands within 3 of 50 → BLOCKED).
- [ ] **Step 2: Full caller sweep** of all 7 names per Global Constraints; build the per-site positional→array migration table (site, file:line, keys carried) in the report BEFORE editing.
- [ ] **Step 3: Extraction + head migration** — create Writer (statics-only class), new `$args` head + fifteen cast lines + verbatim body, five helpers verbatim; remove the 7 from `Decker_Tasks`; loader require. `php -l` both.
- [ ] **Step 4: Call-site migration** — apply the Step 2 table exactly; zero stale `Decker_Tasks::create_or_update_task`/`self::create_or_update_task` references repo-wide afterwards (docs/superpowers excluded).
- [ ] **Step 5: Checks** — `php -l` all touched; hunk audit; fresh-cache phpmd (Writer clean; keyed diff exactly the two justified REMOVED); rerun the four baseline suites (identical counts).
- [ ] **Step 6: ONE commit** — "Move the task write core to Decker_Task_Writer with an args array", body noting the 15-param → `$args` migration decision (approved spec), the verbatim-body head-swap technique, and the 48/50 margin + relief valve.

### Task 2: Verification and PR

- [ ] **Step 1: keyed diff** vs `/tmp/pr-g1/phpmd-before.txt` (zero ADDED; exactly the two REMOVED). **Step 2: PHPCS** (phpcbf mechanical only). **Step 3: translation gate** (POT → "Refresh POT (entry reorder)"). **Step 4: full suite serial once** (expect 903 green).
- [ ] **Step 5: docs commit** — `docs/development.md`: reorg row (`Decker_Tasks::create_or_update_task()` + the write pipeline → `Decker_Task_Writer::create_or_update_task( array $args )` — signature changed, no delegator) and repoint the "stable surface" paragraph to the new owner/signature; `docs/agent-interfaces.md`: update the create_or_update_task signature + examples to the `$args` form; the three LOW-1 test-header retitles; the spec LOW-2/3/4 fixes + Status → `PRs A (#296), B (#297), C (#298), D (#299), E (#300), F (#301) merged; PR G1 in review`.
- [ ] **Step 6: /simplify** via Skill tool (skip loudly if unavailable) → IMMEDIATELY push → `gh pr create` (title `Decompose Decker_Tasks 7/8: task writer and the args migration`; body: the $args decision + provenance, head-swap technique, migration table summary (~46 sites/13 files), keyed diff 4→2, 48/50 margin + relief valve, suite result, spec link) → CI + scan (expect 2) → report + contract. BLOCKED-ON-STEP, no silent idling.

## Self-review notes

- The head-swap technique is this plan's core risk-control: everything below the fifteen extraction lines diffs byte-identical, so reviewers verify a head, not 120 lines of re-typed logic.
- The migration table is built BEFORE any edit and lands in the report — the reviewer replays it site by site.
- PR numbering shifts to "7/8": G was split into G1 (write core) + G2 (Task model display split) once measured CCN showed the combined PR would carry ~130 retarget sites and three new classes on the model side alone.
