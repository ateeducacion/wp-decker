# Decker_Tasks PR F — Save Paths Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move both save paths (15 methods, ~85 summed CC measured 2026-07-29) out of `Decker_Tasks` into three collaborators, delete the two pure today-delegators, and move `handle_save_decker_task` without a public delegator per the revised delegator policy.

**Architecture:** Three classes, not the spec's two: the AJAX handler plus its `$_POST` readers sum to ≈50 CC — exactly at `ExcessiveClassComplexity`'s threshold — so the PR C/E margin rule splits the readers out rather than shipping a threshold-edge class. `Decker_Task_Meta_Saver` owns the admin `save_post` path (`save_meta` public + `can_save_task_meta`, `save_task_detail_fields`, `save_task_taxonomies`, `save_task_assigned_users`, `save_task_user_date_relations` private, ≈35 CC, fully self-contained). `Decker_Task_Ajax_Save` owns the `wp_ajax` handler and its lock guards (`handle_save_decker_task` public + `guard_existing_task_save`, `build_lock_error_data`, `fail_save`, `rotate_lock_generation` private, ≈26 CC); its constructor takes `Decker_Tasks $tasks` — the `Rest_Locks`/`Rest_Ops` precedent — because the group uses the shared lock and today services. `Decker_Task_Request_Reader` owns the `$_POST` parsers (`read_task_core_fields`, `read_task_option_fields`, `parse_task_due_date`, `read_id_list_field`, ≈24 CC), no hooks, instantiated by `Ajax_Save`.

**Delegator-policy revision (user decision 2026-07-29, from PR E final review L1):** no public delegator for `handle_save_decker_task` — every caller is in-repo (6 test files + the two hook lines), all retargeted. `add_user_date_relation` / `remove_user_date_relation` are pure delegations to `Decker_Task_Today_Manager::mark_for_today()` / `unmark_for_today()` and are **deleted**, their callers retargeted to the manager. `Decker_Tasks` lands ≈750 LOC / 18 methods / 10 public / ≈69 CC.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- Bodies verbatim EXCEPT the exhaustive permitted-rewrites list below — any other needed rewrite = BLOCKED naming it. Pre-extraction FULL caller sweep of all 17 names (15 cluster methods + the deleted pair; file + repo, case-insensitive, `->`/`::`/callable-array forms).
- **Permitted rewrites (complete list):**
  1. `self::create_or_update_task(` → `Decker_Tasks::create_or_update_task(` (1 site, inside `handle_save_decker_task`; the method stays on `Decker_Tasks` until PR G).
  2. `$this->get_task_locks()` → `$this->tasks->get_task_locks()` (3 sites, all in the Ajax_Save group).
  3. Lines 646/648: `$this->add_user_date_relation( $result, get_current_user_id() )` → `$this->tasks->get_today_manager()->mark_for_today( $result, get_current_user_id() )`; `remove_…` → `…->unmark_for_today( … )`.
  4. Cross-class reader calls in `handle_save_decker_task`: `$this->read_task_core_fields(` → `$this->reader->read_task_core_fields(` (likewise `read_task_option_fields`; calls BETWEEN reader methods stay `$this->` — the sweep maps which of the `read_id_list_field`/`parse_task_due_date` calls are intra-reader).
  5. The 4 reader methods change visibility private → public on `Decker_Task_Request_Reader` (docblocks/bodies otherwise verbatim).
- Extraction script: the canonical campaign pattern with the outward-scanning splice() and `\n\n+(?=\t)` joints.
- Hook fidelity: `save_post` at `10, 3` → Meta_Saver's constructor; `wp_ajax_save_decker_task` + `wp_ajax_nopriv_save_decker_task` (both default) → Ajax_Save's constructor. Post-move `define_hooks` retains none of the 3 lines.
- Fresh-count every retarget inventory (expected surfaces, to be re-derived: ~34 `handle_save_decker_task` call sites across 6 test files; pair callers in `class-decker-tasks-rest-today.php` ×2, `public/app-priority.php` ×1, `DeckerTasksAssignTodayTest` ~5; any direct `save_meta`/`can_save_task_meta` callers the sweep finds).
- Keyed diff: zero ADDED; REMOVED justified — expected: `ExcessiveClassLength` (1253 → ≈750) and `TooManyMethods` (35 → 18) clear; `TooManyPublicMethods` lands **exactly 10** — the threshold edge — so it may clear (>) or persist (>=): either outcome is acceptable, record which (it informs PR G's expectation). `ExcessiveClassComplexity` (≈69) and `ExcessiveParameterList` remain until G.
- PHPUnit strictly serial; `rm -rf ~/.pdepend` before every phpmd run. POT churn → "Refresh POT (entry reorder)". BLOCKED-ON-STEP, no silent idling. Branch `refactor/decker-tasks-save-paths`.

---

### Task 1: Baselines, sweep, extract three classes + deletions, retarget (one unit, one commit)

**Files:**
- Create: `includes/custom-post-types/class-decker-task-meta-saver.php`, `class-decker-task-ajax-save.php`, `class-decker-task-request-reader.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php`, `includes/class-decker.php`, `includes/custom-post-types/class-decker-tasks-rest-today.php`, `public/app-priority.php`
- Modify: the fresh-counted test files (expected: `DeckerTasksSaveAjaxLockInTest`, `DeckerTaskLockSaveProtectionTest`, `DeckerTaskOutOfBandMutationLockTest`, `DeckerTaskArchivedSaveProtectionTest`, `DeckerTasksAuthorTest`, `DeckerTasksAssignTodayTest` — derive the real list)

**Interfaces:**
- Produces: `Decker_Task_Meta_Saver::__construct()` (no args, registers `save_post`); `Decker_Task_Ajax_Save::__construct( Decker_Tasks $tasks )` (registers both `wp_ajax` hooks, creates its `Decker_Task_Request_Reader`); `Decker_Task_Request_Reader` (no args, no hooks, 4 public readers).
- Consumes: `Decker_Tasks::get_task_locks()`, `Decker_Tasks::get_today_manager()`, `Decker_Tasks::create_or_update_task()` (static, until PR G).

- [ ] **Step 1: Baselines (serial)** — run by path: `DeckerTasksSaveAjaxLockInTest`, `DeckerTaskLockSaveProtectionTest`, `DeckerTasksAssignTodayTest`, `DeckerTasksTest`; record OK lines. PHPMD snapshot → `/tmp/pr-f/phpmd-before.txt` (expect 7 lines). Per-method CC of the 15 cluster methods recorded (confirms the 3-class arithmetic; if any planned class's measured sum lands within 3 of 50 → BLOCKED naming it).
- [ ] **Step 2: Full caller sweep** of all 17 names. Expected: the 3 hook lines, the intra-cluster calls per the dependency map, the retarget inventories above. ANY other caller → BLOCKED.
- [ ] **Step 3: Extraction** — three classes, established header style, hooks per Global Constraints; apply ONLY the 5 permitted rewrites; remove the 15 methods + the pair + the 3 hook lines from `Decker_Tasks`; wire `new Decker_Task_Meta_Saver();` and `new Decker_Task_Ajax_Save( $this );` beside the other collaborators; loader requires before `class-decker-tasks.php`.
- [ ] **Step 4: Checks** — `php -l` ×5; hunk audit (extracted regions + wiring only); stale sweep empty; fresh-cache phpmd: all three new classes clean, `Decker_Tasks` per the keyed-diff expectations.
- [ ] **Step 5: Retarget** the fresh-counted call sites — handler: `( new Decker_Task_Ajax_Save( new Decker_Tasks() ) )->handle_save_decker_task()` (match each file's idiom); pair: `…->get_today_manager()->mark_for_today( … )` / `->unmark_for_today( … )` (in `Rest_Today` via `$this->tasks->`, in `app-priority.php` via `$decker_tasks->`); zero-stale; rerun the four baseline suites (identical counts).
- [ ] **Step 6: ONE commit** — "Move the task save paths to their own classes", body noting the 3-way split arithmetic (readers split at the 50-CC edge), the delegator-policy revision with its provenance, and the deleted pure-delegator pair.

### Task 2: Verification and PR

- [ ] **Step 1: keyed diff** vs `/tmp/pr-f/phpmd-before.txt` (zero ADDED; REMOVED per the Global Constraints expectations — record the `TooManyPublicMethods` edge outcome). **Step 2: PHPCS** (phpcbf mechanical only). **Step 3: translation gate** (POT → "Refresh POT (entry reorder)"). **Step 4: full suite serial once** (expect 903 green).
- [ ] **Step 5: docs** — `docs/development.md`: rows for the three new classes and the deleted pair (`Decker_Tasks::add_user_date_relation()` / `remove_user_date_relation()` → `Decker_Task_Today_Manager::mark_for_today()` / `unmark_for_today()`; `Decker_Tasks::handle_save_decker_task()` → `Decker_Task_Ajax_Save` — no delegator); fold the two PR E nits: append ", row actions" to the `Decker_Task_Admin_List` row, and gitignore `tests/junit.xml`. Spec: dated amendment paragraph in the PR F section (delegator policy revised per user decision 2026-07-29; F is three classes; G will move `create_or_update_task` without a delegator and repoint the stable surface) + Status → `PRs A (#296), B (#297), C (#298), D (#299), E (#300) merged; PR F in review`. Commit.
- [ ] **Step 6: /simplify** via Skill tool (skip loudly if unavailable) → IMMEDIATELY push → `gh pr create` (title `Decompose Decker_Tasks 6/7: save paths`; body: 3-way split arithmetic, delegator-policy revision + provenance, keyed-diff outcome including the public-count edge, suite result, spec link) → CI + scan (expect 7 minus the cleared: likely 5, possibly 4 if the public-count edge clears) → report + contract. BLOCKED-ON-STEP, no silent idling.

## Self-review notes

- This is the first PR of the sequence that deletes public API (the pair + no handler delegator) — the docs rows and the spec amendment carry the provenance so a reviewer doesn't flag it as an unrecorded break.
- The permitted-rewrites list is exhaustive and enumerated per site; the sweep's only open mapping is which reader-to-reader calls stay `$this->` — bounded and named in rewrite 4.
- The 50-CC edge for handler+readers is stated with its measured arithmetic so the 3-way split can't be mistaken for scope creep.
