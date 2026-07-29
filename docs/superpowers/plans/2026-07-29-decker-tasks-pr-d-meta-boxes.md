# Decker_Tasks PR D — Admin Meta Boxes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the task edit-screen meta boxes (9 methods, 21 CC measured 2026-07-29) into `Decker_Task_Meta_Boxes`, the `Decker_Event_Meta_Box` pattern.

**Architecture:** The smallest cluster of the sequence and fully self-contained: the only internal dependency is `display_user_date_meta_box` calling its two private renderers; no method touches locks, today, or order state. One hook (`add_meta_boxes`), one direct test caller (`DeckerTasksMetaBoxLockInTest`). The new class owns the hook in its constructor; `Decker_Tasks` loses the nine methods and the registration line. No alert is expected to clear: keyed diff 7 entries, zero added, zero removed — and per the campaign rule, any REMOVED must be justified, not celebrated blindly.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- Bodies move verbatim; NO permitted rewrites exist for this cluster (it is self-contained) — any needed rewrite means the sweep missed something: STOP, BLOCKED, name it. Exception precedent (PR C): if a fuller pre-extraction sweep finds a coordinator-side caller of a moved method, apply the established accessor pattern only if one exists for this collaborator — there is none planned, so a found caller is a BLOCKED design question, not a silent fix.
- Membership (all 9): `add_meta_boxes`, `display_meta_box`, `display_labels_meta_box`, `display_board_meta_box`, `display_users_meta_box`, `display_user_date_meta_box`, `render_user_date_relations_list` (private), `render_user_date_meta_box_script` (private), `display_attachment_meta_box`. Visibilities unchanged.
- Extraction script: PR C's canonical pattern — including the campaign-memory splice() (scan OUTWARD from the joint on the already-spliced string, collapse to exactly one blank line; the naive one-sided regex double-counts) and the `\n\n+(?=\t)` joint form. Post-check: hunks only in extracted regions + wiring.
- Pre-extraction FULL caller sweep of all 9 names across the whole file and repo (PR C lesson: probe ALL callers, not a named one).
- Fresh-count rule for the test retarget. PHPUnit strictly serial; `rm -rf ~/.pdepend` before phpmd; both the new class and the trimmed file measured. POT churn → "Refresh POT (entry reorder)". BLOCKED-ON-STEP, never silent idling. Branch `refactor/decker-tasks-meta-boxes`.

---

### Task 1: Baselines, sweep, extract, retarget (one unit, one commit)

**Files:**
- Create: `includes/custom-post-types/class-decker-task-meta-boxes.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php`, `includes/class-decker.php`
- Modify: `tests/unit/includes/custom-post-types/DeckerTasksMetaBoxLockInTest.php` (fresh-counted call sites)

**Interfaces:**
- Produces: `Decker_Task_Meta_Boxes::__construct()` (no arguments — the class is self-contained) registering `add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) )`; the nine methods with today's exact signatures and visibilities.
- Consumes: nothing beyond WordPress functions.

- [ ] **Step 1: Baselines (serial)** — `DeckerTasksMetaBoxLockInTest` by path; `DeckerTasksTest.php` by path; record OK lines. PHPMD snapshot to `/tmp/pr-d/phpmd-before.txt` (expect 7 lines).
- [ ] **Step 2: Full caller sweep** of all 9 method names (whole file + repo, case-insensitive, `->`/`::`/callable-array forms). Expected: the `add_meta_boxes` hook line, the internal `display_user_date_meta_box` pair, the one test caller. ANY other caller → BLOCKED.
- [ ] **Step 3: Extraction** per the canonical script pattern: new class header in the established style (`defined( 'ABSPATH' ) || exit;`, one-paragraph docblock: "the edit-screen meta boxes for tasks"), constructor with the hook, nine verbatim bodies; remove the nine + the hook registration line from `Decker_Tasks`; wire `new Decker_Task_Meta_Boxes();` in `Decker_Tasks::__construct` beside the other collaborators; loader require before `class-decker-tasks.php`.
- [ ] **Step 4: Checks** — `php -l` ×3; hunk audit (headers in report; only extracted regions + wiring); stale sweep empty; `rm -rf ~/.pdepend` then phpmd on the new class AND `class-decker-tasks.php` (new class clean; Decker_Tasks alerts unchanged in kind).
- [ ] **Step 5: Retarget** the fresh-counted test call sites to `( new Decker_Task_Meta_Boxes() )->display_user_date_meta_box(...)` (adjust to the file's instance style); zero-stale check; rerun both baseline suites (identical counts).
- [ ] **Step 6: ONE commit** — message: "Move the task edit-screen meta boxes to their own class" + a body noting the cluster is self-contained, the hook moved with its owner, and the Decker_Event_Meta_Box precedent.

### Task 2: Verification and PR

- [ ] **Step 1: Keyed PHPMD diff** vs `/tmp/pr-d/phpmd-before.txt` — zero ADDED; every REMOVED (none expected) justified or BLOCKED.
- [ ] **Step 2: PHPCS** on touched files (phpcbf mechanical only). **Step 3: translation gate**; POT → "Refresh POT (entry reorder)". **Step 4: full suite serial once** (expect 903 green).
- [ ] **Step 5: Docs** — one row in `docs/development.md` (meta boxes → `Decker_Task_Meta_Boxes`); spec Status → `PRs A (#296), B (#297), C (#298) merged; PR D in review`. Commit.
- [ ] **Step 6: /simplify** via Skill tool (skip loudly if unavailable) → IMMEDIATELY push → `gh pr create` (title `Decompose Decker_Tasks 4/7: admin meta boxes`; body: self-contained cluster, no permitted rewrites existed, keyed-diff arithmetic, suite result, spec link) → CI + scan count (expect 7).

## Self-review notes

- Deliberately two tasks, not four: the cluster is a tenth the size of PR B/C and every campaign lesson is baked into Task 1's steps.
- The no-permitted-rewrites rule is this plan's sharpest tripwire: a self-contained cluster that suddenly needs one means the sweep failed — that must surface as BLOCKED, not improvisation.
