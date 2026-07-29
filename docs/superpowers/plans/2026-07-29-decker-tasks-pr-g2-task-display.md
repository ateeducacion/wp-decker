# Decker_Tasks PR G2 — Task Model Display Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the `Task` model's display layer (20 methods, 77 summed CCN measured 2026-07-29 with PHPMD reportLevel-1) into three collaborators and suppress `TooManyFields` with justification — clearing the campaign's last two alerts. Scan 2 → **0**.

**Architecture:** Membership fixed by the measured call graph and CCN arithmetic (Task retains 43/50 — margin 7; every new class is well clear of 50). *Amended 2026-07-29 pre-execution: the originally planned statics-only `Decker_Task_Format` was dissolved after the controller's purity pre-check found `get_relative_time`/`get_formatted_date` read `$this->duedate` (only `pastelize_color` is parameter-pure) — all three fold into the card renderer instead, per this plan's own renegotiation clause:*
- `Decker_Task_Card_Renderer` (ctor `Task $task`): `render_task_card` 4 (public) + `get_card_background_style` 6, `get_due_css_class` 4, `render_card_board_name` 4, `render_card_comments_counter` 2, `render_card_labels_counter` 3 (private) + `render_people_avatars` 6 (public — also called from `public/app-tasks.php`) + `get_relative_time` 6, `get_formatted_date` 2 (public instance — they read `$this->task->duedate`; also called from `public/app-tasks.php` / `public/layouts/task-card.php`) + `pastelize_color` 2 (public STATIC — parameter-pure; also called directly by the model tests) = **39**.
- `Decker_Task_Menu_Renderer` (ctor `Task $task`): `render_task_menu` 3 (public) + `get_menu_task_url` 3, `get_admin_menu_items` 2, `get_owner_menu_items` 4, `get_archive_menu_item` 2, `get_assignment_menu_items` 6, `print_menu_dropdown` 2 (private) = **22**.
- `Decker_Task_People_View` (ctor `Task $task`): `get_people_users` 7, `get_people_names` 2, `get_user_history_with_objects` 7 (public) = **16**.
- Known cross-class edges (from the measured graph, to wire exactly): card → menu (`render_task_card` embeds the menu: Card instantiates `new Decker_Task_Menu_Renderer( $this->task )` at the call site), card → its own `render_people_avatars` which delegates people resolution to `new Decker_Task_People_View( $this->task )` (or People_View methods called where the bodies need them — follow the bodies), card's `get_card_background_style` → `self::pastelize_color(...)` (intra-class), menu → `$this->task->is_marked_for_today_for_current_user()` (stays on Task, public).
- Controller pre-verified (2026-07-29, ahead of Task 1's tripwires): all 16 `Task` fields are declared `public` (typed), so rewrite class 1 is unobstructed; the purity check outcome is the amendment above — Task 1 re-confirms both rather than re-discovering them.
- `Task` keeps the model core (constructor/loaders/accessors/state checks, 43 CCN) and gets `@SuppressWarnings(PHPMD.TooManyFields)` on the class docblock with the justification: the 16 fields mirror the task's persisted attributes one-to-one; splitting them into value objects would trade cohesion for indirection (approved spec decision).
- **No delegators** (policy, and arithmetically forced: 9 delegators at CCN 1 would land Task at 52 > 50).

**Shared-reader question (owned by PR G, ruled here):** `Decker_Task_Clone::get_task_assigned_users()` duplicates `Decker_Notification_Handler::get_assigned_users()` — both private, both tiny, pre-existing. Ruling: DECLINED dedupe (YAGNI: no alert depends on it; a shared helper would couple the clone and notification concerns for two 5-line readers). Recorded in the PR body; no code change.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- Bodies move verbatim EXCEPT these enumerated rewrite classes — anything else = BLOCKED naming it:
  1. Field reads in moved bodies: `$this-><field>` → `$this->task-><field>` (the display methods read: ID, assigned_users, board, duedate, hidden, labels, max_priority, meta, order, responsable, status, title — Task 1 verifies ALL are declared public on Task; any private/protected one → BLOCKED).
  2. Calls to methods STAYING on Task: `$this-><name>(` → `$this->task-><name>(` (known: `is_marked_for_today_for_current_user`).
  3. Cross-new-class calls per the fixed edge map above (card→menu instantiation, people-view access; `pastelize_color` intra-card `self::` qualification).
  4. Intra-class calls stay `$this->`; private helpers keep private, public keep public.
  5. `pastelize_color`: instance → `public static` on the card renderer (parameter-pure, per the amendment); `get_relative_time`/`get_formatted_date` stay public instance methods (their `$this->duedate` reads become `$this->task->duedate` under rewrite class 1).
- Pre-extraction FULL caller sweep of all 20 names (repo-wide, `->`/`::`/callable-array). Known external callers to retarget (fresh-count the exact sites): `public/app-kanban.php`, `public/app-upcoming.php`, `public/app-kanban-my.php` (render_task_card), `public/app-tasks.php` (render_task_menu, get_relative_time, render_people_avatars, get_people_names), `public/layouts/task-card.php` (render_task_menu, get_user_history_with_objects, get_formatted_date), `tests/unit/includes/models/TaskTest.php`, `tests/unit/includes/models/TaskEdgeCasesTest.php`. Retarget idiom: `( new Decker_Task_Card_Renderer( $task ) )->render_task_card( … )` / `Decker_Task_Card_Renderer::pastelize_color( … )` — match each file's variable names.
- Extraction script: campaign pattern (outward splice(), `\n\n+(?=\t)` joints). New-class style: ctor-taking classes match `class-decker-task-meta-saver.php`; Loader requires in `includes/class-decker.php` beside the model (before first template use; match how `class-task.php` itself is loaded).
- No hooks exist on any of the 20 methods (verify in the sweep).
- Keyed diff: zero ADDED (new classes ≈39/22/16 CCN — clean); REMOVED exactly the two `class-task.php` entries (`ExcessiveClassComplexity` 120 → 43, `TooManyFields` via the annotated suppression), both justified. Post state: EMPTY codesize snapshot. Scan expectation on the PR merge ref: **0**.
- Docs debt this PR settles (Step 5): G1 final-review NIT-2 (spec ~:143 "see below" → "see above") and NIT-4b (spec :26 and :145 "all 46 call sites" → the measured 20); spec Status line; `docs/development.md` reorg rows for the three new classes (including that `pastelize_color` became a public static on the card renderer).
- PHPUnit strictly serial; `rm -rf ~/.pdepend` before every phpmd run. POT churn → "Refresh POT (entry reorder)". BLOCKED-ON-STEP, no silent idling. Branch `refactor/task-model-display`.

---

### Task 1: Baselines, sweep, extract three classes, retarget (one unit, one commit)

**Files:**
- Create: `includes/custom-post-types/class-decker-task-card-renderer.php`, `class-decker-task-menu-renderer.php`, `class-decker-task-people-view.php` (or beside `includes/models/` if that matches the loader layout better — decide from where `class-task.php` lives and keep the three together)
- Modify: `includes/models/class-task.php`, `includes/class-decker.php`, `public/app-kanban.php`, `public/app-upcoming.php`, `public/app-kanban-my.php`, `public/app-tasks.php`, `public/layouts/task-card.php`
- Modify: `tests/unit/includes/models/TaskTest.php`, `tests/unit/includes/models/TaskEdgeCasesTest.php` (fresh-counted sites)

**Interfaces:**
- Produces: the three classes per the Architecture bullet; method names and per-method visibilities unchanged from today.
- Consumes: `Task`'s public fields and its staying public methods.

- [ ] **Step 1: Baselines (serial)** — `TaskTest` and `TaskEdgeCasesTest` by path; record OK lines. PHPMD snapshot → `/tmp/pr-g2/phpmd-before.txt` (expect 2 lines). Per-method CCN (reportLevel-1 ruleset) of all 20 methods — expect the 39/22/16 sums; any new-class projection within 3 of 50 → BLOCKED.
- [ ] **Step 2: Full caller sweep + rewrite map** — all 20 names repo-wide; re-confirm the pre-verified field visibilities and the purity outcome recorded in the Architecture bullet; build the per-site retarget table AND the per-method rewrite inventory (which lines get which rewrite class) in the report BEFORE editing. ANY surprise → BLOCKED.
- [ ] **Step 3: Extraction** — three classes; remove the 20 methods from `Task`; add the `@SuppressWarnings(PHPMD.TooManyFields)` docblock annotation with the justification sentence; loader requires.
- [ ] **Step 4: Checks** — `php -l` ×6+; hunk audit (extracted regions + the docblock annotation + wiring only); stale sweep empty; fresh-cache phpmd: three new classes clean, snapshot down to zero entries.
- [ ] **Step 5: Retarget** the fresh-counted template/test sites per the table; zero-stale; rerun both baseline suites (identical counts).
- [ ] **Step 6: ONE commit** — "Split the task model's display layer into renderer collaborators", body noting the 77-CCN arithmetic, the three-way membership by call graph (and the dissolved Format class per the amendment), the forced no-delegator arithmetic, and the suppression justification.

### Task 2: Verification and PR

- [ ] **Step 1: keyed diff** vs `/tmp/pr-g2/phpmd-before.txt` (zero ADDED; exactly the two REMOVED; snapshot EMPTY). **Step 2: PHPCS** (phpcbf mechanical only). **Step 3: translation gate** (POT → "Refresh POT (entry reorder)"). **Step 4: full suite serial once** (expect 903 green).
- [ ] **Step 5: docs commit** — development.md rows; spec NIT-2/NIT-4b fixes; spec Status → `PRs A (#296), B (#297), C (#298), D (#299), E (#300), F (#301), G1 (#302) merged; PR G2 in review`.
- [ ] **Step 6: /simplify** via Skill tool (skip loudly if unavailable; angles route to the controller; if all clean push WITHOUT waiting) → push → `gh pr create` (title `Decompose Decker_Tasks 8/8: task model display split`; body: the three-way split arithmetic + call-graph membership, the suppression + justification, the shared-reader DECLINE ruling, keyed diff 2→0, suite line, spec link) → CI + scan (**expect 0**) → report + contract. BLOCKED-ON-STEP, no silent idling.

## Self-review notes

- This is the campaign's only extraction whose bodies CANNOT be byte-verbatim (field/method access must reroute through `$this->task`); the risk control is the enumerated rewrite classes + the per-method rewrite inventory built before editing, which the reviewer replays.
- The Format-purity assumption was checked by the controller BEFORE execution and the membership renegotiated in place — Task 1 re-confirms rather than re-discovers, so the tripwire cannot fire as a surprise.
- Landing state is scan **0**: the PR body should say so plainly and note the one remaining CodeQL JS alert (task-modal.js:33) is explicitly out of campaign scope.
