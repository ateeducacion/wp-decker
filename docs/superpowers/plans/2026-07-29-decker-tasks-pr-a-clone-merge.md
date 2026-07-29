# Decker_Tasks PR A — Clone & Merge Engines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the task clone and merge engines (16 static methods, ~80 CC) out of `Decker_Tasks` into `Decker_Task_Clone` and `Decker_Task_Merge`, byte-for-byte, clearing the first slice of the class per the approved spec.

**Architecture:** Two new collaborator classes of static methods, extracted verbatim. The call-graph sweep (2026-07-29) proved the engines share nothing: the four task-meta getters belong to clone only, `normalize_task_user_ids` to merge only, and neither engine uses `$this->`. Clone's single outbound call is `self::create_or_update_task` → becomes `Decker_Tasks::create_or_update_task`. The REST/AJAX handlers (`handle_clone_task`, `handle_merge_task`) stay in `Decker_Tasks` for now (they move in PR B) and call the new classes. No delegators: the only external callers are the two test files, which follow the engines.

**Tech Stack:** PHP 7.4+/WordPress plugin, wp-env test harness (PHPUnit 9.6), PHPMD 2.15, PHPCS (WordPress standard).

## Global Constraints

- Zero behaviour change: method bodies move verbatim; the only edits are the class-name prefix on cross-class calls.
- PHPMD: zero **new** alerts anywhere; both new classes fully clean. This PR clears no class-level alert by itself (the six on `Decker_Tasks` fall at PRs F/G) — the PR description must say so.
- Never run PHPUnit concurrently (shared test DB corrupts; see memory: a single-file run does `Installing…` and drops tables under a running suite). Serial only. If stale failures appear (Calendar/DemoData/Sidebar/TaskManager), run `npx wp-env clean tests` first.
- All comments/docblocks in English; `@param` alignment per AGENTS.md.
- Before opening the PR: `make check-untranslated` exits 0 with no msgid changes (this PR adds no strings), `/simplify` runs on the diff, and the reorganisation table in `docs/development.md` gains this PR's rows.
- Measure PHPMD only after `rm -rf ~/.pdepend` (stale AST cache reports pre-edit metrics).
- Branch: `refactor/decker-tasks-decomposition` (already exists, carries the spec commit).

---

### Task 1: Record baselines

**Files:**
- None modified. Scratch outputs under `/tmp/pr-a/`.

**Interfaces:**
- Produces: `/tmp/pr-a/phpmd-before.txt` (keyed alert list), green baselines for the two engine suites — later tasks compare against these.

- [ ] **Step 1: Confirm clean tree on the right branch**

```bash
cd /Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker
git status --short   # expect: empty
git branch --show-current   # expect: refactor/decker-tasks-decomposition
```

- [ ] **Step 2: Baseline the two engine suites (serial)**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php
```

Expected: `OK (10 tests, 42 assertions)` and `OK (4 tests, 34 assertions)`. If either fails, STOP — do not refactor on a red baseline; check for the stale-environment failure signature and reset with `npx wp-env clean tests`.

- [ ] **Step 3: Baseline PHPMD**

```bash
mkdir -p /tmp/pr-a
rm -rf ~/.pdepend
phpmd . text codesize --exclude tests,vendor,node_modules 2>/dev/null \
  | grep -v "^Deprecated" | grep -v "^$" \
  | sed 's|^/Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker/||' | sort > /tmp/pr-a/phpmd-before.txt
wc -l /tmp/pr-a/phpmd-before.txt   # expect: 8
```

### Task 2: Extract the two engine classes

**Files:**
- Create: `includes/custom-post-types/class-decker-task-clone.php`
- Create: `includes/custom-post-types/class-decker-task-merge.php`
- Modify: `includes/custom-post-types/class-decker-tasks.php` (remove the 16 moved methods; update 2 handler call sites)
- Modify: `includes/class-decker.php` (require the new files before `class-decker-tasks.php`)

**Interfaces:**
- Produces: `Decker_Task_Clone::clone_task( $task_id )` and `Decker_Task_Merge::merge_tasks( $source_task_id, $destination_task_id )` — identical signatures and return types to the current `Decker_Tasks::` statics. All other moved methods are `private static` on their new class.
- Consumes: `Decker_Tasks::create_or_update_task(...)` (unchanged in this PR).

- [ ] **Step 1: Run the extraction script**

The script moves each method with its docblock, verbatim, and rewrites only the cross-class references. Method membership is fixed by the call-graph sweep:

```bash
cd /Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker
python3 - <<'PY'
import re
SRC = "includes/custom-post-types/class-decker-tasks.php"
s = open(SRC).read()

def extract(name):
    m = re.search(r"\t(public|private|protected) (static )?function " + name + r"\(", s)
    assert m, name
    doc = s.rfind("\t/**", 0, m.start())
    close = s.find("\n\t}\n", m.start())
    assert close != -1, name
    return s[doc:close + len("\n\t}\n")], doc, close + len("\n\t}\n")

clone_names = ["clone_task","build_clone_title","parse_duedate_meta",
               "get_task_assigned_users","get_task_board_id","get_task_label_ids"]
merge_names = ["merge_tasks","validate_merge_request","merge_assigned_users_meta",
               "merge_relations_meta","move_task_comments","merge_task_attachments",
               "archive_merged_source","normalize_task_user_ids",
               "merge_user_date_relations","build_merged_task_description"]

clone_blocks = {n: extract(n)[0] for n in clone_names}
merge_blocks = {n: extract(n)[0] for n in merge_names}

clone_header = '''<?php
/**
 * Clones a task into a fresh copy.
 *
 * Reads the source task's fields, meta, board and labels, builds the copy's
 * title, and creates the duplicate through the canonical write path
 * (Decker_Tasks::create_or_update_task), so cloning fires the same hooks as
 * any other creation.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Clone
 */
class Decker_Task_Clone {

'''
body = ""
for n in clone_names:
    blk = clone_blocks[n]
    blk = blk.replace("self::create_or_update_task(", "Decker_Tasks::create_or_update_task(")
    body += blk + "\n"
open("includes/custom-post-types/class-decker-task-clone.php", "w").write(clone_header + body.rstrip() + "\n}\n")

merge_header = '''<?php
/**
 * Merges one task into another.
 *
 * Validates the pair, unions the assigned users and their date relations,
 * moves comments and attachments to the destination, appends the source
 * description, and archives the source. Everything here is a static engine;
 * the REST transport lives with the task routes.
 *
 * @package    Decker
 * @subpackage Decker/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Decker_Task_Merge
 */
class Decker_Task_Merge {

'''
mbody = ""
for n in merge_names:
    mbody += merge_blocks[n] + "\n"
open("includes/custom-post-types/class-decker-task-merge.php", "w").write(merge_header + mbody.rstrip() + "\n}\n")

# Trim the originals (reverse order keeps offsets valid).
spans = [(extract(n)[1], extract(n)[2]) for n in clone_names + merge_names]
for a, b in sorted(spans, reverse=True):
    s = s[:a] + s[b:]

# The handlers stay behind and call the new engines.
s = s.replace("$new_task_id = self::clone_task( $task_id );",
              "$new_task_id = Decker_Task_Clone::clone_task( $task_id );")
s = s.replace("$result = self::merge_tasks( $source_task_id, $destination_task_id );",
              "$result = Decker_Task_Merge::merge_tasks( $source_task_id, $destination_task_id );")

s = re.sub(r"\n(\t?\n){3,}", "\n\n", s)
open(SRC, "w").write(s)
print("extraction done")
PY
```

Expected output: `extraction done` (an `AssertionError` naming a method means the anchor drifted — STOP and diff the method list against the file).

- [ ] **Step 2: Syntax-check all three files**

```bash
php -l includes/custom-post-types/class-decker-tasks.php
php -l includes/custom-post-types/class-decker-task-clone.php
php -l includes/custom-post-types/class-decker-task-merge.php
```

Expected: `No syntax errors detected` × 3.

- [ ] **Step 3: Register the new files in the loader**

In `includes/class-decker.php`, inside `load_post_type_dependencies()`, add before the `class-decker-tasks.php` require:

```php
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-task-clone.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-decker-task-merge.php';
```

- [ ] **Step 4: Verify no stale engine references remain (case-insensitive — a `Decker_KB::` casing variant escaped round 5's sweep)**

```bash
grep -rniE "(decker_tasks|self|static)::(clone_task|merge_tasks|build_clone_title|parse_duedate_meta|get_task_assigned_users|get_task_board_id|get_task_label_ids|validate_merge_request|merge_assigned_users_meta|merge_relations_meta|move_task_comments|merge_task_attachments|archive_merged_source|normalize_task_user_ids|merge_user_date_relations|build_merged_task_description)\(" \
  --include="*.php" includes/ admin/ public/ | grep -v "class-decker-task-clone\|class-decker-task-merge"
```

Expected: empty (production code has no remaining reference to the old owners).

- [ ] **Step 5: PHPMD on the touched files**

```bash
rm -rf ~/.pdepend
phpmd includes/custom-post-types/class-decker-task-clone.php,includes/custom-post-types/class-decker-task-merge.php text codesize 2>/dev/null | grep -v "^Deprecated"
```

Expected: no output — both new classes clean (clone ≈ 25 CC, merge ≈ 55… **note**: if `Decker_Task_Merge` reports `ExcessiveClassComplexity`, the measured sum exceeded 50; split `move_task_comments` + `merge_task_attachments` (~12 CC) into `Decker_Task_Merge_Media` following the same script pattern, and re-measure. Do not proceed with a warning on a new class.)

### Task 3: Point the engine tests at the new owners

**Files:**
- Modify: `tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php` (9 call sites)
- Modify: `tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php` (2 call sites)

**Interfaces:**
- Consumes: `Decker_Task_Clone::clone_task`, `Decker_Task_Merge::merge_tasks` from Task 2.

- [ ] **Step 1: Rewrite the call sites**

```bash
cd /Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker
perl -pi -e 's/Decker_Tasks::clone_task\(/Decker_Task_Clone::clone_task(/g' tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php
perl -pi -e 's/Decker_Tasks::merge_tasks\(/Decker_Task_Merge::merge_tasks(/g' tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php
grep -c "Decker_Task_Clone::" tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php   # expect: 9
grep -c "Decker_Task_Merge::" tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php   # expect: 2
```

- [ ] **Step 2: Run both suites (serial)**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php
```

Expected: `OK (10 tests, 42 assertions)` and `OK (4 tests, 34 assertions)` — identical to Task 1 baselines.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "Move the task clone and merge engines to their own classes

The call-graph sweep showed the two engines share nothing: the four
task-meta getters serve only cloning, normalize_task_user_ids serves only
merging, and neither uses instance state. Bodies moved verbatim; clone's
one outbound call now names Decker_Tasks::create_or_update_task, and the
REST/AJAX handlers (which move with the routes in the next PR) call the
new engines. The engine tests follow their subjects, as in every previous
round. Decker_Tasks drops roughly 80 points of complexity and 16 methods;
its class-level warnings remain by design until the sequence completes."
```

### Task 4: Full verification and PR

**Files:**
- Modify: `docs/development.md` (reorganisation table, two rows)

**Interfaces:**
- Consumes: `/tmp/pr-a/phpmd-before.txt` from Task 1.

- [ ] **Step 1: PHPMD keyed diff — zero new alerts**

```bash
rm -rf ~/.pdepend
phpmd . text codesize --exclude tests,vendor,node_modules 2>/dev/null \
  | grep -v "^Deprecated" | grep -v "^$" \
  | sed 's|^/Users/ernesto/Dropbox/Trabajo/ate/deck-tools/wp-decker/||' | sort > /tmp/pr-a/phpmd-after.txt
awk '
  function key() { split($1,a,":"); return a[1] " [" $2 "]" }
  NR==FNR { b[key()]++; next }
  { c[key()]++ }
  END {
    for (k in b) if (!(k in c)) printf "REMOVED  %s\n", k
    for (k in c) if (!(k in b)) printf "ADDED    %s\n", k
  }
' /tmp/pr-a/phpmd-before.txt /tmp/pr-a/phpmd-after.txt | sort
```

Expected: **no `ADDED` lines**. `REMOVED` lines are not expected either (the six `Decker_Tasks` alerts persist until F/G) — both lists should be identical at 8 entries.

- [ ] **Step 2: PHPCS on every touched file**

```bash
npx wp-env run cli phpcs --standard=wp-content/plugins/decker/.phpcs.xml.dist \
  wp-content/plugins/decker/includes/custom-post-types/class-decker-tasks.php \
  wp-content/plugins/decker/includes/custom-post-types/class-decker-task-clone.php \
  wp-content/plugins/decker/includes/custom-post-types/class-decker-task-merge.php \
  wp-content/plugins/decker/includes/class-decker.php \
  wp-content/plugins/decker/tests/unit/includes/custom-post-types/DeckerTasksCloneTest.php \
  wp-content/plugins/decker/tests/unit/includes/custom-post-types/DeckerTasksMergeTest.php
```

Expected: no errors. A "closing brace must go on the next line" error after trimming is known — fix with `phpcbf` on the flagged file and re-run.

- [ ] **Step 3: Translation gate**

```bash
composer check-untranslated
git status --short admin/vendor/   # expect empty: check-untranslated once deleted admin/vendor/mime-mail-parser — restore via git checkout if it recurs
git diff languages/decker.pot | grep -E "^[+-]msgid \"" | grep -v '^\[+-\]msgid ""'
```

Expected: exit 0; no msgid additions or removals (line-reference churn only). Commit the POT refresh if it changed:

```bash
git add languages/ && git commit -m "Refresh POT line references" || true
```

- [ ] **Step 4: Full suite, serial**

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/decker ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (90x tests, ...)` — zero failures (the suite has been fully green since the round-3 environment reset). On Calendar/DemoData/Sidebar/TaskManager failures: `npx wp-env clean tests`, rerun once.

- [ ] **Step 5: Record the moves in the docs table**

In `docs/development.md`, add to the class-reorganisations table (before the `Decker_Public` row):

```markdown
| `Decker_Tasks::clone_task()` and its private readers | `Decker_Task_Clone` |
| `Decker_Tasks::merge_tasks()` and the merge pipeline | `Decker_Task_Merge` |
```

```bash
git add docs/development.md && git commit -m "Record the clone and merge moves in the development docs"
```

- [ ] **Step 6: Run /simplify on the diff, then push and open the PR**

Run the `/simplify` skill over the branch diff; apply what it finds (expected: little — the bodies are verbatim moves — but the commitment is per-spec). Then:

```bash
git push -u origin refactor/decker-tasks-decomposition
gh pr create --title "Decompose Decker_Tasks 1/7: clone and merge engines" --base main --body "$(cat <<'EOF'
First PR of the sequence approved in docs/superpowers/specs/2026-07-29-decker-tasks-decomposition-design.md (which rides in this branch).

Moves the clone and merge engines (16 static methods, ~80 of Decker_Tasks' 445 complexity points) to `Decker_Task_Clone` and `Decker_Task_Merge`, verbatim. The call-graph sweep showed the engines share nothing, so there is no shared-reader class: the four task-meta getters belong to clone alone, `normalize_task_user_ids` to merge alone. Clone's single outbound call names `Decker_Tasks::create_or_update_task` explicitly; the REST/AJAX handlers stay behind and move with the routes in PR B.

**This PR clears no alert by itself** — the six class-level warnings on Decker_Tasks fall when the sequence completes (F/G). Verified instead by the keyed PHPMD diff: identical 8-entry alert list before and after, zero added, both new classes clean.

Engine tests follow their subjects (11 call-site retargets); full suite green; no string changes.
EOF
)"
```

- [ ] **Step 7: Confirm CI and the scan count**

```bash
gh pr checks --watch
gh api "/repos/ateeducacion/wp-decker/code-scanning/analyses?ref=refs/pull/<NUMBER>/merge" -q '.[0].results_count'
```

Expected: all checks pass; PHPMD results_count = **8** (unchanged, per the alert arithmetic above).

## Self-review notes

- Spec coverage: PR A scope only, per the sequence; the spec's "resolved in the plan by call-graph" clause is resolved — no shared readers exist (measured 2026-07-29).
- The contingency for `Decker_Task_Merge` landing over 50 is written into Task 2 Step 5 rather than left to discovery.
- Type consistency: all moved signatures are unchanged; the only new names are the two class names used consistently across Tasks 2–4.
