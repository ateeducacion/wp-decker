# Decker_Tasks PR E — Admin List & Chrome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the task admin list table, its filters, and the edit-screen chrome (16 methods, 47 CC measured 2026-07-29) out of `Decker_Tasks` into two collaborators.

**Architecture:** 47 summed CC is three points shy of the threshold — the same arithmetic that split PR C's cluster, so the same ruling applies rather than shipping a one-method-from-warning class. The seam is what the admin is looking at: `Decker_Task_Admin_List` owns the list table and its filters (`add_custom_columns` 1, `render_custom_columns` 2, `make_columns_sortable` 1, `custom_order_by_stack` 5, `filter_tasks_by_status` 5, `filter_tasks_by_taxonomies` 4, `map_taxonomy_filter_to_slug` 5 private, `add_taxonomy_filters` 2, `add_taxonomy_filter` 2 private, `remove_row_actions` 2 = 29); `Decker_Task_Admin_Chrome` owns the edit-screen and menu tweaks (`hide_visibility_options` 2, `disable_menu_order_field` 4, `hide_permalink_and_slug` 4, `change_publish_meta_box_title` 2, `disable_gutenberg` 2, `remove_add_new_link` 4 = 18). The only two internal dependencies both fall intra-group. Both classes are self-contained (no locks/today/order state): **zero permitted rewrites exist** — PR D's tripwire applies verbatim. Each class registers its own hooks (the 14 registrations split 8 list / 6 chrome, verified against `define_hooks` lines 111-132 at d609195).

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- Bodies verbatim, visibilities unchanged, zero permitted rewrites (self-contained cluster) — any needed rewrite = BLOCKED naming it. Pre-extraction FULL caller sweep of all 16 names (file + repo, case-insensitive, callable-array form) — the PR C lesson.
- Extraction script: the canonical PR C/D pattern with the campaign splice() (outward scan from the joint, collapse to exactly one blank line).
- Hook fidelity: every registration moves with its exact priority/arg-count (`use_block_editor_for_post_type` at 10,2; `post_row_actions` at 10,2; `manage_decker_task_posts_custom_column` at 10,2; the rest default). Post-move `define_hooks` retains none of the 14.
- Fresh-count the test retargets (expected file: `DeckerTasksAdminFiltersLockInTest`, but derive it). PHPUnit strictly serial; `rm -rf ~/.pdepend`; keyed diff zero-ADDED, every REMOVED justified (none expected — Decker_Tasks lands ≈156 CC / ≈35 methods / ≈18 public, still over the remaining thresholds). POT → "Refresh POT (entry reorder)". BLOCKED-ON-STEP. Branch `refactor/decker-tasks-admin-list`.

---

### Task 1: Baselines, sweep, extract both classes, retarget (one unit, one commit)

**Files:**
- Create: `includes/custom-post-types/class-decker-task-admin-list.php`, `includes/custom-post-types/class-decker-task-admin-chrome.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php`, `includes/class-decker.php`
- Modify: the fresh-counted test file(s)

**Interfaces:**
- Produces: both classes with no-argument constructors registering their own hooks; the sixteen methods with today's exact signatures/visibilities.
- Consumes: WordPress functions only.

- [ ] **Step 1: Baselines (serial)** — `DeckerTasksAdminFiltersLockInTest` by path (fallback `--filter` with the real class name on the known quirk) and `DeckerTasksTest.php`; record OK lines. PHPMD snapshot → `/tmp/pr-e/phpmd-before.txt` (expect 7 lines).
- [ ] **Step 2: Full caller sweep** of all 16 names. Expected callers: the 14 hook lines, the two intra-group pairs, the lock-in test's references. ANY other → BLOCKED.
- [ ] **Step 3: Extraction** — two classes, established header style, hooks in constructors with fidelity per Global Constraints; remove the 16 methods + 14 hook lines from `Decker_Tasks`; wire `new Decker_Task_Admin_List();` and `new Decker_Task_Admin_Chrome();` beside the other collaborators; loader requires before `class-decker-tasks.php`.
- [ ] **Step 4: Checks** — `php -l` ×4; hunk audit (headers in report; extracted regions + wiring only); stale sweep empty; fresh-cache phpmd: both new classes clean, `Decker_Tasks` alert kinds unchanged.
- [ ] **Step 5: Retarget** the fresh-counted call sites to `( new Decker_Task_Admin_List() )->filter_tasks_by_taxonomies(...)` style (match the file's idiom; docblock owner references too); zero-stale; rerun both baseline suites (identical counts).
- [ ] **Step 6: ONE commit** — "Move the task admin list and edit-screen chrome to their own classes", body noting the 47-CC arithmetic and the PR C precedent for the split.

### Task 2: Verification and PR

- [ ] **Step 1: keyed diff** vs `/tmp/pr-e/phpmd-before.txt` (zero ADDED; REMOVED justified or BLOCKED). **Step 2: PHPCS** (phpcbf mechanical only). **Step 3: translation gate** (POT → "Refresh POT (entry reorder)"). **Step 4: full suite serial once** (expect 903 green).
- [ ] **Step 5: docs** — two rows in `docs/development.md`; spec Status → `PRs A (#296), B (#297), C (#298), D (#299) merged; PR E in review`. Commit.
- [ ] **Step 6: /simplify** via Skill tool (skip loudly if unavailable) → IMMEDIATELY push → `gh pr create` (title `Decompose Decker_Tasks 5/7: admin list and chrome`; body: measured 47-CC split with PR C precedent, zero-rewrites tripwire silent, keyed-diff arithmetic, suite result, spec link) → CI + scan (expect 7) → report + contract. BLOCKED-ON-STEP, no silent idling.

## Self-review notes

- The split-at-47 ruling is stated with its precedent rather than left for a reviewer to question.
- Hook fidelity (priorities/arg counts) is an explicit constraint because this cluster carries 14 registrations — the most hook surface of any PR in the sequence.
