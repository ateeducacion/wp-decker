# Decker_Tasks PR C — Order Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the stack/order machinery of `Decker_Tasks` (14 methods, 62 CC measured 2026-07-29) into `Decker_Task_Order` (engine) and `Decker_Task_Order_Hooks` (WordPress hook adapters), and delete PR B's two transitional route rows by pointing them at the engine.

**Architecture:** The cluster sums to 62 — over the 50 threshold — so the spec's named contingency applies. The measured seam follows the dependency graph: the **engine** owns the REST-facing operations and the primitives they share (`update_task_stack_and_order` 4, `validate_stack_order_request` 8, `apply_stack_transition` 3, `persist_task_menu_order` 3, `reorder_tasks_in_stack` 1, `handle_fix_order` 3, `get_new_task_order` 2 = 24); the **hook adapters** own the reactions to WordPress events (`handle_board_change_reorder` 9, `handle_stack_change_reorder` 9, `modify_task_order_before_save` 4 with its privates `resolve_new_task_board` 4, `resolve_new_task_stack` 4, `apply_calculated_menu_order` 4, and `move_task_to_board_end` 4 = 38). Adapters take the engine in their constructor; `reorder_tasks_in_stack` and `get_new_task_order` become public on the engine for them. The engine takes `Decker_Tasks` (for `get_task_locks()`), PR B's pattern. `Decker_Tasks` gains a lazy `get_order_engine()` accessor, and the two transitional rows in `Decker_Tasks_Rest_Ops` — left as greppable `array( $this->tasks, ... )` data — are updated to `array( $this->tasks->get_order_engine(), ... )`, completing the hand-off PR B staged.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env (PHPUnit 9.6), PHPMD 2.15, PHPCS.

## Global Constraints

- Zero behaviour change. Bodies move verbatim; permitted rewrites ONLY: `$this->get_task_locks()` → `$this->tasks->get_task_locks()` (engine), engine-method calls inside adapters `$this->X(` → `$this->order->X(` for X in {reorder_tasks_in_stack, get_new_task_order}, and visibility `private → public` ONLY for those two engine methods.
- Extraction script: PR B's splice-anchored pattern with the improved joint regex — after each removal, normalize `\n\n+(?=\t)` at the joint to one blank line (the `\n(\t?\n){2,}` form missed close-brace/blank/tab joints in PR B; both fixes are recorded in the campaign memory). NO file-wide collapse. Post-check: `git diff` on `class-decker-tasks.php` has no hunks outside extracted regions + wiring + the accessor.
- Fresh-count rule: derive every retarget count with grep before rewriting; never trust counts written in a plan.
- The two Rest_Ops rows: this PR's ONLY edit to `class-decker-tasks-rest-ops.php` is the two `array( $this->tasks, '…' )` → `array( $this->tasks->get_order_engine(), '…' )` rows. `DeckerTasksRestRoutesLockInTest` must pass UNMODIFIED (table shape is callback-agnostic on paths/methods/args — confirm it still passes, and if it asserts callables, STOP and report).
- PHPUnit strictly serial; `rm -rf ~/.pdepend` before phpmd; zero new alerts, both new classes clean; keyed diff identical-8.
- Hand-off discipline (PR B lesson): before any controller/implementer takeover, check `git ls-remote` + `gh pr list` IMMEDIATELY, not from minutes-old state. Implementers: report BLOCKED-ON-STEP rather than idling.
- POT churn commits as "Refresh POT (entry reorder)". English comments; branch `refactor/decker-tasks-order-engine`.

---

### Task 1: Baselines and fresh counts

**Files:** none modified; scratch under `/tmp/pr-c/`.

**Interfaces:**
- Produces: `/tmp/pr-c/phpmd-before.txt` (8 keyed alerts), `/tmp/pr-c/counts.txt` (fresh retarget worklist), green baselines.

- [ ] **Step 1: Clean tree on `refactor/decker-tasks-order-engine`** (`git status --short` empty; branch confirmed).

- [ ] **Step 2: Baseline suites, one at a time, recording every OK line**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksRestRoutesLockInTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --filter DeckerTasksOrderLockInTest
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/integration/TaskBoardAssignmentTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/integration/DeckerTaskOutOfBandMutationLockTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --filter HooksTest
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit --filter DeckerHooksTasksTest
```

For the two `--filter` runs: if "No tests executed", open the file, read the real class name from the header, rerun with it, and record what worked (classname quirks are known here).

- [ ] **Step 3: PHPMD snapshot (expect 8 lines) and the fresh worklist**

```bash
mkdir -p /tmp/pr-c
rm -rf ~/.pdepend
phpmd . text codesize --exclude tests,vendor,node_modules 2>/dev/null \
  | grep -v "^Deprecated" | grep -v "^$" \
  | sed 's|^/Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker/||' | sort > /tmp/pr-c/phpmd-before.txt
wc -l /tmp/pr-c/phpmd-before.txt
grep -rnE "(->)(update_task_stack_and_order|handle_fix_order|reorder_tasks_in_stack|get_new_task_order)\(" --include="*.php" tests/ > /tmp/pr-c/counts.txt
cat /tmp/pr-c/counts.txt
```

Also verify one open question the plan's sweep left: does `handle_task_deletion` (staying in the coordinator) call any moved method?

```bash
sed -n '/function handle_task_deletion(/,/^	}/p' includes/custom-post-types/class-decker-tasks.php | grep -E "reorder|order|stack" || echo "no order-cluster calls — stays untouched"
```

If it DOES call a moved method, record it: the extraction adds `$this->get_order_engine()->…` there as one more documented rewrite (report it prominently).

### Task 2: Extract engine + adapters, wire, hand off the transitional rows

**Files:**
- Create: `includes/custom-post-types/class-decker-task-order.php`
- Create: `includes/custom-post-types/class-decker-task-order-hooks.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php` (remove 14 methods; add `get_order_engine()` accessor + property; adapters wiring; remove the three adapter hook registrations from `define_hooks`)
- Modify: `includes/custom-post-types/class-decker-tasks-rest-ops.php` (two rows only)
- Modify: `includes/class-decker.php` (two requires before `class-decker-tasks.php`)

**Interfaces:**
- Produces: `Decker_Task_Order::__construct( Decker_Tasks $tasks )` with public `update_task_stack_and_order`, `handle_fix_order`, `reorder_tasks_in_stack`, `get_new_task_order` (signatures unchanged from today); `Decker_Task_Order_Hooks::__construct( Decker_Task_Order $order )` registering `wp_insert_post_data` (10,4), `set_object_terms` (10,6), `updated_post_meta` (10,4) exactly as `define_hooks` does today; `Decker_Tasks::get_order_engine(): Decker_Task_Order` lazy accessor in the style of `get_task_locks()`.
- Consumes: `Decker_Tasks::get_task_locks()`.

- [ ] **Step 1: Extraction script** — PR B's pattern (plan `2026-07-29-decker-tasks-pr-b-rest-controllers.md` Task 2) with: the 14-method membership above, the permitted rewrites above, the improved `\n\n+(?=\t)` joint normalization, class headers in the established style, `defined( 'ABSPATH' ) || exit;`. Wiring in `Decker_Tasks::__construct`:

```php
		// Ordering reactions to WordPress events own their own hooks.
		new Decker_Task_Order_Hooks( $this->get_order_engine() );
```

   and the accessor + property:

```php
	/**
	 * Stack/order engine, created on first use.
	 *
	 * @var Decker_Task_Order|null
	 */
	private $order_engine = null;

	/**
	 * The stack/order engine for tasks.
	 *
	 * @return Decker_Task_Order
	 */
	public function get_order_engine(): Decker_Task_Order {
		if ( null === $this->order_engine ) {
			$this->order_engine = new Decker_Task_Order( $this );
		}
		return $this->order_engine;
	}
```

   In `class-decker-tasks-rest-ops.php`, exactly two row edits: `array( $this->tasks, 'update_task_stack_and_order' )` → `array( $this->tasks->get_order_engine(), 'update_task_stack_and_order' )` and the same for `handle_fix_order`.

- [ ] **Step 2: `php -l` on all five touched files** (expect clean ×5).

- [ ] **Step 3: Loader** — two requires before `class-decker-tasks.php` in `load_post_type_dependencies()` (order-hooks after order).

- [ ] **Step 4: Route lock-in UNMODIFIED + hunk audit**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksRestRoutesLockInTest.php
git diff includes/custom-post-types/class-decker-tasks.php | grep "^@@"
git diff includes/custom-post-types/class-decker-tasks-rest-ops.php | grep -c "^[+-]" 
```

Expected: lock-in identical to baseline; hunks only in extracted regions + wiring + accessor; Rest_Ops diff = 4 changed lines (2 rows × ±1).

- [ ] **Step 5: Stale sweep (case-insensitive, incl. dynamic dispatch) + phpmd**

```bash
grep -rniE "(decker_tasks|self|static)::(get_new_task_order|validate_stack_order_request|apply_stack_transition|persist_task_menu_order|move_task_to_board_end|resolve_new_task_board|resolve_new_task_stack|apply_calculated_menu_order)\(" --include="*.php" includes/ admin/ public/ | grep -v "class-decker-task-order"
rm -rf ~/.pdepend
phpmd includes/custom-post-types/class-decker-task-order.php,includes/custom-post-types/class-decker-task-order-hooks.php text codesize 2>/dev/null | grep -v "^Deprecated"
```

Expected: both empty. A warning on a new class = BLOCKED (membership was measured).

### Task 3: Retarget the direct test callers and run the suites

**Files:** exactly those in `/tmp/pr-c/counts.txt`.

- [ ] **Step 1: Rewrite each recorded call site** to `( new Decker_Tasks() )->get_order_engine()->update_task_stack_and_order( ... )` — or, where the test already holds a `Decker_Tasks` instance, `$tasks->get_order_engine()->…`.

- [ ] **Step 2: Zero-stale check, then rerun every Task 1 suite serially** — counts identical to baseline.

- [ ] **Step 3: ONE commit**

```bash
git add -A
git commit -m "Move the stack and order machinery into an engine and its hook adapters

The cluster measured 62 complexity, over the threshold a single class must
stay under, so the spec's contingency applies along the dependency seam:
Decker_Task_Order owns the REST-facing operations and shared primitives;
Decker_Task_Order_Hooks owns the reactions to WordPress events (post-data
filter, board term changes, stack meta changes) and drives the engine it is
constructed with.

Decker_Tasks gains a lazy get_order_engine() accessor, and the two
transitional route rows PR B left as greppable data in the Ops controller
now dispatch to the engine — completing that hand-off. Bodies moved
verbatim under three documented rewrites; the route table
characterization passes unmodified."
```

### Task 4: Verification and PR

- [ ] **Step 1: PHPMD keyed diff vs `/tmp/pr-c/phpmd-before.txt`** — identical 8, zero added (awk comparison as in prior plans; deviation = BLOCKED).
- [ ] **Step 2: PHPCS on all touched files** (phpcbf for mechanical fixes only).
- [ ] **Step 3: Translation gate**; POT churn → "Refresh POT (entry reorder)".
- [ ] **Step 4: Full suite, serial, once** — expect 903 green.
- [ ] **Step 5: Docs** — `docs/development.md` two rows (`Decker_Tasks` order engine → `Decker_Task_Order`; order hook reactions → `Decker_Task_Order_Hooks`); spec Status line → `PR A (#296) and PR B (#297) merged; PR C in review`. Commit.
- [ ] **Step 6: /simplify via Skill tool** (skip loudly if unavailable) — then IMMEDIATELY push, `gh pr create` (title `Decompose Decker_Tasks 3/7: order engine`; body: measured-seam split, the transitional-row hand-off closing PR B's shim, identical-8 arithmetic, fresh-counted retargets, suite result), CI + scan count (expect 8). BLOCKED-ON-STEP on any unanswerable prompt — no silent idling.

## Self-review notes

- The `handle_task_deletion` open question is resolved empirically in Task 1 with an explicit escalation path, not assumed.
- Both campaign-memory regex lessons are baked into the constraints; the Rest_Ops edit is scoped to exactly two rows with a line-count check.
- Names/signatures consistent across tasks: `get_order_engine()`, `Decker_Task_Order( Decker_Tasks )`, `Decker_Task_Order_Hooks( Decker_Task_Order )`.
