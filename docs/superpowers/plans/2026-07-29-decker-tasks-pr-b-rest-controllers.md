# Decker_Tasks PR B — REST Controllers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the REST transport of `Decker_Tasks` (24 methods, ~100 CC) into four resource controllers plus one shared support class, in WordPress core's controller style, with the registered route table byte-identical.

**Architecture:** Measured this morning (fresh sweep, 2026-07-29): the spec's three controllers become **four**, because the ops cluster alone sums to 56 CC — over the 50 threshold. Split at the natural seam: `Decker_Tasks_Rest_Locks` (27), `Decker_Tasks_Rest_Today` (18), `Decker_Tasks_Rest_Ops` (field ops + REST insert guards, 32), `Decker_Tasks_Rest_Tools` (search/clone/merge transport, 28), plus `Decker_Tasks_Rest_Support` (the shared permission-callback factory and the WP_Error→WP_REST_Response formatter, 11). Every controller takes the `Decker_Tasks` instance in its constructor and reaches locks/today/relations through the existing public accessors (`$this->tasks->get_task_locks()` …), which keeps every moved body verbatim under one mechanical rewrite. Two routes (`update_task_stack_and_order`, `handle_fix_order`) have PR-C-scoped bodies: their routes move to Ops but keep dispatching to the injected `Decker_Tasks` until PR C. The existing `DeckerTasksRestRoutesLockInTest` asserts the table via `rest_get_server()->get_routes('decker/v1')` after `do_action('rest_api_init')` — ownership-agnostic, so it is the move's principal safety net and must pass untouched.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS (WordPress standard).

## Global Constraints

- Zero behaviour change. Handler bodies move verbatim; the ONLY permitted body rewrites are: `$this->get_task_locks()` → `$this->tasks->get_task_locks()`, `$this->get_today_manager()` → `$this->tasks->get_today_manager()`, `$this->add_user_date_relation(` → `$this->tasks->add_user_date_relation(`, `$this->remove_user_date_relation(` → `$this->tasks->remove_user_date_relation(`, `$this->lock_error_response(` → `Decker_Tasks_Rest_Support::error_response(`, `$this->make_permission_callback(` → `Decker_Tasks_Rest_Support::permission_callback(`, `Decker_Task_Clone::` / `Decker_Task_Merge::` calls unchanged, plus intra-controller `$this->` calls that stay `$this->`.
- The extraction script MUST anchor its blank-line cleanup to the splice points only — never a file-wide collapse (PR A lesson, recorded in the campaign memory).
- Do NOT hardcode expected call-site counts copied from this plan: derive each count with `grep -c` BEFORE rewriting, record it, rewrite, then verify zero stale references (PR A lesson: a truncated sweep produced wrong expectations).
- The registered route table must be identical: same paths, methods, args, permission behaviour. `DeckerTasksRestRoutesLockInTest` (2 tests) must pass UNMODIFIED.
- PHPUnit strictly serial; stale-environment failures → `npx wp-env clean tests` once. `rm -rf ~/.pdepend` before any PHPMD measurement. Zero new PHPMD alerts; all five new classes clean.
- POT: if regenerated, the commit message says "entry reorder" (this repo strips line references; see campaign memory).
- English comments; `@param` alignment per AGENTS.md. Branch: `refactor/decker-tasks-rest-controllers`.

---

### Task 1: Baselines and fresh counts

**Files:**
- None modified. Scratch under `/tmp/pr-b/`.

**Interfaces:**
- Produces: `/tmp/pr-b/phpmd-before.txt` (8 keyed alerts), `/tmp/pr-b/counts.txt` (fresh per-file call-site counts), green baselines for the six affected suites.

- [ ] **Step 1: Clean tree, right branch**

```bash
cd /Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker
git status --short          # expect empty
git branch --show-current   # expect refactor/decker-tasks-rest-controllers
```

- [ ] **Step 2: Baseline the affected suites, one at a time**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksRestRoutesLockInTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTaskLockRestTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/integration/DeckerTaskOutOfBandMutationLockTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/integration/DeckerTaskLockSaveProtectionTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/integration/DeckerTasksAssignTodayTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --filter "DeckerHooksTest|DeckerTasksHooksTest" 2>&1 | tail -3
```

Record every `OK (N tests, M assertions)` line in your report. All must be green; on a red baseline STOP (reset the tests env once for the known stale-environment signature, then re-judge).

- [ ] **Step 3: PHPMD snapshot and fresh call-site counts**

```bash
mkdir -p /tmp/pr-b
rm -rf ~/.pdepend
phpmd . text codesize --exclude tests,vendor,node_modules 2>/dev/null \
  | grep -v "^Deprecated" | grep -v "^$" \
  | sed 's|^/Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker/||' | sort > /tmp/pr-b/phpmd-before.txt
wc -l /tmp/pr-b/phpmd-before.txt    # expect 8

for f in tests/unit/includes/HooksTest.php \
         tests/integration/DeckerTaskOutOfBandMutationLockTest.php \
         tests/integration/DeckerTaskLockSaveProtectionTest.php \
         tests/integration/DeckerTasksAssignTodayTest.php; do
  echo "== $f" >> /tmp/pr-b/counts.txt
  grep -nE "(->)(refresh_task_lock_heartbeat|assign_user_to_task|remove_user_from_task|update_task_due_date|mark_user_date_relation|unmark_user_date_relation)\(" "$f" >> /tmp/pr-b/counts.txt
done
cat /tmp/pr-b/counts.txt
```

The recorded lines are your retarget worklist for Task 3 — derived fresh, not copied from this plan.

### Task 2: Extract the five classes and wire the coordinator

**Files:**
- Create: `includes/custom-post-types/class-decker-tasks-rest-support.php`
- Create: `includes/custom-post-types/class-decker-tasks-rest-locks.php`
- Create: `includes/custom-post-types/class-decker-tasks-rest-today.php`
- Create: `includes/custom-post-types/class-decker-tasks-rest-ops.php`
- Create: `includes/custom-post-types/class-decker-tasks-rest-tools.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php`
- Modify: `includes/class-decker.php`

**Interfaces:**
- Produces: four controller classes, each `__construct( Decker_Tasks $tasks )`, each registering its own hooks (`rest_api_init`; Locks also `heartbeat_received`; Ops also `rest_pre_insert_decker_task` + `rest_after_insert_decker_task`). `Decker_Tasks_Rest_Support::permission_callback( ... )` and `::error_response( WP_Error ): WP_REST_Response` as public statics with signatures identical to the current private methods.
- Consumes: `Decker_Tasks` public accessors `get_task_locks()`, `get_today_manager()`, `add_user_date_relation()`, `remove_user_date_relation()`, and (until PR C) public `update_task_stack_and_order` / `handle_fix_order` as route callbacks on the injected instance.

Method membership (fixed by measurement, 2026-07-29 — CC in parentheses):

| Class | Methods |
|---|---|
| Support | `make_permission_callback` (7) → `permission_callback`, `lock_error_response` (3) → `error_response`, both `public static` |
| Locks | `get_task_lock_route_definitions` (1), `validate_task_lock_request` (4), `handle_get_task_lock` (2), `handle_acquire_task_lock` (4), `handle_takeover_task_lock` (3), `handle_release_task_lock` (3), `refresh_task_lock_heartbeat` (7) |
| Today | `get_task_today_route_definitions` (1), `handle_task_today` (6), `today_result_message` (4), `mark_user_date_relation` (2), `unmark_user_date_relation` (2) |
| Ops | `assign_user_to_task` (8), `remove_user_from_task` (7), `update_task_due_date` (6), `guard_rest_task_update` (4), `rotate_generation_after_rest_update` (3), plus the route rows for `update_task_stack_and_order` / `handle_fix_order` dispatching to `$this->tasks` |
| Tools | `search_tasks` (9), `handle_clone_task` (7), `handle_merge_task` (8) |

`register_rest_routes` (3) and `get_rest_route_definitions` (1) dissolve: each controller registers its own definitions with the same `array( 'callback' => <name>, ... )` structure, resolved per-row as `array( $this, $name )` — or `array( $this->tasks, $name )` for the two PR-C rows in Ops. The per-row resolution in Ops is:

```php
$handler = method_exists( $this, $definition['callback'] )
    ? array( $this, $definition['callback'] )
    : array( $this->tasks, $definition['callback'] );
```

This is registrar glue (new, minimal, documented here) — not a body rewrite.

- [ ] **Step 1: Write the extraction script and run it**

Follow PR A's script pattern (`docs/superpowers/plans/2026-07-29-decker-tasks-pr-a-clone-merge.md`, Task 2 Step 1) with these differences, all mandatory:

1. Extract the 24 methods listed above (plus `register_rest_routes` and `get_rest_route_definitions`, which are removed, their content redistributed into the four per-controller `register_routes()` + definition methods).
2. Apply ONLY the permitted body rewrites from Global Constraints.
3. **Anchored cleanup** — instead of a file-wide `re.sub(r"\n(\t?\n){3,}", ...)`, collapse blank runs only at each splice offset:

```python
def splice(s, a, b):
    """Remove s[a:b], then collapse any blank-line run created at the joint to one blank line."""
    s = s[:a] + s[b:]
    run = re.match(r"\n(\t?\n){2,}", s[a - 1:])
    if run:
        s = s[:a - 1] + "\n\n" + s[a - 1 + run.end():]
    return s

# usage, replacing PR A's removal loop AND its file-wide re.sub (which must NOT appear):
for a, b in sorted(spans, reverse=True):
    s = splice(s, a, b)
```

   Verify afterwards: `git diff` on `class-decker-tasks.php` must contain NO hunks outside the extracted regions and the constructor wiring — that check is what proves the anchoring worked.
4. Constructor wiring in `Decker_Tasks::__construct` (or `define_hooks`), replacing the removed hook lines:

```php
		// The REST transport owns its own hooks, one controller per resource.
		new Decker_Tasks_Rest_Locks( $this );
		new Decker_Tasks_Rest_Today( $this );
		new Decker_Tasks_Rest_Ops( $this );
		new Decker_Tasks_Rest_Tools( $this );
```

   and removing from `define_hooks`: the `rest_api_init` registration, the `heartbeat_received` filter, and the `rest_pre_insert_decker_task` / `rest_after_insert_decker_task` lines (each controller now registers its own).
5. Class headers: one-paragraph docblock each, in the established style (see `class-decker-task-clone.php`), stating the resource owned; `defined( 'ABSPATH' ) || exit;` in every file; `@package Decker`, `@subpackage Decker/includes`.

- [ ] **Step 2: Syntax-check all seven touched files**

```bash
for f in includes/custom-post-types/class-decker-tasks.php \
         includes/custom-post-types/class-decker-tasks-rest-support.php \
         includes/custom-post-types/class-decker-tasks-rest-locks.php \
         includes/custom-post-types/class-decker-tasks-rest-today.php \
         includes/custom-post-types/class-decker-tasks-rest-ops.php \
         includes/custom-post-types/class-decker-tasks-rest-tools.php \
         includes/class-decker.php; do php -l "$f"; done
```

Expected: seven × `No syntax errors detected`.

- [ ] **Step 3: Loader**

In `includes/class-decker.php`, `load_post_type_dependencies()`, before the `class-decker-tasks.php` require:

```php
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks-rest-support.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks-rest-locks.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks-rest-today.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks-rest-ops.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-tasks-rest-tools.php';
```

- [ ] **Step 4: The route table is byte-identical — run the lock-in UNMODIFIED**

```bash
git diff --stat tests/   # expect: empty — no test file touched yet
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksRestRoutesLockInTest.php
```

Expected: identical `OK` counts to Task 1. This single check is the move's core proof; if it fails, the table drifted — STOP and diff `rest_get_server()->get_routes('decker/v1')` output before/after rather than patching the test.

- [ ] **Step 5: Stale-reference sweep (case-insensitive) and PHPMD on the new classes**

```bash
grep -rniE "(decker_tasks|self|static)::(make_permission_callback|lock_error_response|get_rest_route_definitions|get_task_today_route_definitions|get_task_lock_route_definitions|validate_task_lock_request|register_rest_routes)\(" \
  --include="*.php" includes/ admin/ public/ | grep -v "class-decker-tasks-rest"
# expect: empty
rm -rf ~/.pdepend
phpmd includes/custom-post-types/class-decker-tasks-rest-support.php,includes/custom-post-types/class-decker-tasks-rest-locks.php,includes/custom-post-types/class-decker-tasks-rest-today.php,includes/custom-post-types/class-decker-tasks-rest-ops.php,includes/custom-post-types/class-decker-tasks-rest-tools.php text codesize 2>/dev/null | grep -v "^Deprecated"
# expect: no output (all five clean). If any class reports ExcessiveClassComplexity, STOP and report BLOCKED with the numbers — membership was measured, so a warning means drift.
```

### Task 3: Retarget the direct test callers

**Files:**
- Modify: exactly the files and lines recorded in `/tmp/pr-b/counts.txt` (Task 1), expected to be: `tests/unit/includes/HooksTest.php`, `tests/integration/DeckerTaskOutOfBandMutationLockTest.php`, `tests/integration/DeckerTaskLockSaveProtectionTest.php`, `tests/integration/DeckerTasksAssignTodayTest.php`.

**Interfaces:**
- Consumes: the controllers from Task 2. Construction in tests: `new Decker_Tasks_Rest_Locks( new Decker_Tasks() )` etc. — or reuse the test's existing `Decker_Tasks` instance where one exists.

- [ ] **Step 1: Rewrite each recorded call site to the owning controller**

Mapping: `refresh_task_lock_heartbeat` → Locks; `assign_user_to_task`, `remove_user_from_task`, `update_task_due_date` → Ops; `mark_user_date_relation`, `unmark_user_date_relation` → Today. Rewrite mechanically, e.g.:

```php
// before
$resp = $tasks->refresh_task_lock_heartbeat( array(), $payload );
// after
$resp = ( new Decker_Tasks_Rest_Locks( $tasks ) )->refresh_task_lock_heartbeat( array(), $payload );
```

Where the test used `( new Decker_Tasks() )->method(...)` inline, wrap the same way: `( new Decker_Tasks_Rest_Ops( new Decker_Tasks() ) )->method(...)`.

- [ ] **Step 2: Verify zero stale calls, then run the six suites serially**

```bash
grep -rnE "(->)(refresh_task_lock_heartbeat|assign_user_to_task|remove_user_from_task|update_task_due_date|mark_user_date_relation|unmark_user_date_relation)\(" tests/ \
  | grep -v "Rest_Locks\|Rest_Ops\|Rest_Today"
# expect: empty (commented-out lines excepted)
```

Then rerun every suite from Task 1 Step 2, one at a time. Expected: counts identical to the recorded baselines.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "Move the task REST transport into four resource controllers

WordPress core organizes REST endpoints as one controller class per
resource; the task routes now follow. Locks (with the heartbeat refresh),
today, field ops (with the REST insert guards) and tools (search, clone
and merge transport) each own their routes and hooks, share the permission
factory and error formatter through a small static support class, and
reach locks and relations through the injected Decker_Tasks accessors.

The spec sketched three controllers; the measured ops cluster summed to 56
complexity, over the threshold, so it split at its natural seam into field
ops and tools. Two route rows keep dispatching to Decker_Tasks until PR C
moves their bodies with the order engine.

Handler bodies moved verbatim under the documented accessor rewrites. The
route-table characterization test passes unmodified, which is the point:
the registered table is identical, only its registrar changed."
```

### Task 4: Verification and PR

**Files:**
- Modify: `docs/development.md` (five reorganisation rows), `docs/superpowers/specs/2026-07-29-decker-tasks-decomposition-design.md` (Status line)

**Interfaces:**
- Consumes: `/tmp/pr-b/phpmd-before.txt` from Task 1.

- [ ] **Step 1: PHPMD keyed diff — identical 8, zero added, zero removed**

Same awk comparison as PR A's plan (Task 4 Step 1), against `/tmp/pr-b/phpmd-before.txt`. Any ADDED line: STOP, report BLOCKED.

- [ ] **Step 2: PHPCS on all touched files; phpcbf only for flagged mechanical issues**

- [ ] **Step 3: Translation gate** — `composer check-untranslated` exit 0, `admin/vendor/` untouched, msgid set unchanged; commit any POT churn as `"Refresh POT (entry reorder)"`.

- [ ] **Step 4: Full suite, serial, once** — expect fully green (903+); stale-environment signature → reset once, rerun once.

- [ ] **Step 5: Docs**

`docs/development.md` reorganisation table, five rows before the `Decker_Public` row:

```markdown
| `Decker_Tasks` lock REST routes + `refresh_task_lock_heartbeat()` | `Decker_Tasks_Rest_Locks` |
| `Decker_Tasks` today REST routes (`handle_task_today()`, mark/unmark relations) | `Decker_Tasks_Rest_Today` |
| `Decker_Tasks` field-op REST routes + REST insert guards | `Decker_Tasks_Rest_Ops` |
| `Decker_Tasks::search_tasks()` / clone + merge REST transport | `Decker_Tasks_Rest_Tools` |
| `Decker_Tasks::make_permission_callback()` / `lock_error_response()` | `Decker_Tasks_Rest_Support` (public statics) |
```

Spec Status line becomes: `Status: approved design — PR A merged (#296); PR B in review`. Commit both.

- [ ] **Step 6: /simplify on the branch diff** (via the Skill tool; if unavailable, skip and say so prominently), then push and open the PR:

Title: `Decompose Decker_Tasks 2/7: REST controllers`. Body: state the four-not-three deviation with the measured numbers; the route-table proof (lock-in unmodified); the PR-C delegation rows; the arithmetic note (this PR clears no alert; identical 8); test retargets counted fresh; link the spec.

- [ ] **Step 7: CI + scan count** — all checks green; `code-scanning` PHPMD results_count = 8.

## Self-review notes

- Spec deviation (4 controllers + support vs. 3 + helper) is measured, stated in the architecture note, the commit message and the PR body — not silent.
- The two PR-C route rows keep the table identical without moving order-engine bodies; the dispatch glue is documented as registrar code, not a body rewrite.
- All forward actions from the campaign memory are embedded: anchored cleanup, fresh counts, POT wording, spec Status update.
- Type consistency: `Decker_Tasks_Rest_Support::permission_callback` / `::error_response` names used identically in Global Constraints, membership table, and docs rows.
