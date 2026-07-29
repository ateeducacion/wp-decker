# Decker_Tasks and Task decomposition — design

Date: 2026-07-29
Status: approved design — PRs A (#296), B (#297), C (#298) merged; PR D in review
Predecessors: PRs #290, #291, #292, #294, #295 (PHPMD codesize campaign, 39 → 8 open alerts)

## Goal

Clear the last 8 PHPMD `codesize` alerts — six on `Decker_Tasks`
(complexity 445, 4051 lines, 107 methods, 60 public, public count 65, and the
15-parameter `create_or_update_task`) and two on the `Task` model
(complexity 120, 16 fields) — with **zero behaviour change**, through a
sequence of independently mergeable PRs.

End state: `Decker_Tasks` is the coordinator of the `decker_task` post type —
registration of the post type, statuses and meta; constructor-level wiring of
collaborator classes; accessors — under every threshold (≤ 50 complexity,
≤ 25 methods, ≤ 10 public, ≤ 1000 lines). Each subsystem lives in a
collaborator class that registers its own hooks, the pattern validated in
rounds 2–5. `Task` keeps the data and loses the rendering.

## Decisions (made with the maintainer, 2026-07-29)

1. **`create_or_update_task` migrates to a single `$args` array** parsed with
   `wp_parse_args()` against defaults — the WordPress-canonical signature for
   many-argument writes (`wp_insert_post( $postarr )`). All 46 call sites
   (production: `Decker_Ability_Task_Store`, `Decker_Demo_Tasks`,
   `Decker_Email_To_Post`, internal; tests: lock-in suites and the task
   factory) and `docs/agent-interfaces.md` migrate in the same PR. No
   positional wrapper is kept: a wrapper would keep the 15-parameter signature
   alive and with it the alert.
2. **`Task`'s `TooManyFields` is suppressed** with
   `@SuppressWarnings(PHPMD.TooManyFields)` on the class docblock and a
   justifying comment. A task genuinely has 16 attributes; the fields are the
   model. Grouping them into value objects would break every template that
   reads `$task->board`, `$task->stack`, … (~20 call sites for `board` alone)
   or require magic `__get` indirection that trades static analyzability for
   a metric.
3. **Full green via a PR sequence** — one subsystem per PR, each mergeable on
   its own, ordered so the riskiest change (the signature migration, which
   touches the test factory every other suite uses) lands last, on a stable
   base.

## WordPress grounding

- **REST**: core organizes endpoints as one controller class per resource —
  `WP_REST_Revisions_Controller`, `WP_oEmbed_Controller`,
  `WP_REST_URL_Details_Controller` — with `register_routes()`, callbacks and
  permission checks as methods of that controller. The REST split follows
  that shape.
- **Write API**: core's convention for a write entry point with many optional
  arguments is a single args array with `wp_parse_args()` defaults
  (`wp_insert_post`, `wp_update_post`). The signature migration follows it.

## Current anatomy (measured)

119 methods, nine responsibility clusters (per-method cyclomatic complexity
from PHPMD, 2026-07-29):

| Cluster | Methods (line-ordered) | ~CC |
|---|---|---|
| Clone engine | `clone_task`, `build_clone_title`, `parse_duedate_meta`, `get_task_assigned_users`, `get_task_board_id`, `get_task_label_ids` | ~25 |
| Merge engine | `merge_tasks`, `validate_merge_request`, `merge_assigned_users_meta`, `merge_relations_meta`, `move_task_comments`, `merge_task_attachments`, `archive_merged_source`, `normalize_task_user_ids`, `merge_user_date_relations`, `build_merged_task_description` | ~55 |
| REST transport | `register_rest_routes`, `make_permission_callback`, `get_rest_route_definitions`, `get_task_today_route_definitions`, `get_task_lock_route_definitions`, `validate_task_lock_request`, `lock_error_response`, `handle_get/acquire/takeover/release_task_lock`, `refresh_task_lock_heartbeat`, `handle_task_today`, `today_result_message`, `handle_clone_task`, `handle_merge_task`, `assign_user_to_task`, `remove_user_from_task`, `update_task_due_date`, `search_tasks`, `guard_rest_task_update`, `rotate_generation_after_rest_update`, `mark/unmark_user_date_relation` | ~90 |
| Order/stack engine | `get_new_task_order`, `update_task_stack_and_order`, `validate_stack_order_request`, `apply_stack_transition`, `persist_task_menu_order`, `reorder_tasks_in_stack`, `handle_board_change_reorder`, `move_task_to_board_end`, `handle_stack_change_reorder`, `handle_fix_order`, `modify_task_order_before_save`, `resolve_new_task_board`, `resolve_new_task_stack`, `apply_calculated_menu_order` | ~60 |
| Admin meta boxes | `add_meta_boxes`, `display_meta_box`, `display_labels_meta_box`, `display_board_meta_box`, `display_users_meta_box`, `display_user_date_meta_box`, `render_user_date_relations_list`, `render_user_date_meta_box_script`, `display_attachment_meta_box` | ~20 |
| Admin list & chrome | `add_custom_columns`, `render_custom_columns`, `make_columns_sortable`, `custom_order_by_stack`, `filter_tasks_by_status`, `filter_tasks_by_taxonomies`, `map_taxonomy_filter_to_slug`, `add_taxonomy_filters`, `add_taxonomy_filter`, `remove_row_actions`, `remove_add_new_link`, `hide_permalink_and_slug`, `disable_menu_order_field`, `disable_gutenberg`, `change_publish_meta_box_title`, `hide_visibility_options` | ~40 |
| Admin save | `save_meta`, `can_save_task_meta`, `save_task_detail_fields`, `save_task_taxonomies`, `save_task_assigned_users`, `save_task_user_date_relations` | ~35 |
| AJAX save | `handle_save_decker_task`, `guard_existing_task_save`, `build_lock_error_data`, `fail_save`, `rotate_lock_generation`, `read_task_core_fields`, `read_task_option_fields`, `parse_task_due_date`, `read_id_list_field` | ~45 |
| Write core | `create_or_update_task`, `validate_task_fields`, `build_task_tax_input`, `build_task_post_data`, `resolve_assigned_users_and_new`, `update_existing_task`, `insert_new_task` | ~40 |

Remaining in the coordinator afterwards: `__construct`, `define_hooks`,
`get_task_locks`, `get_today_manager`, `register_post_type`,
`register_task_meta`, `register_archived_post_status`,
`append_post_status_list`, `restrict_rest_access`, `custom_task_permalink`,
`custom_unique_filename`, `handle_task_deletion`, `handle_task_status_change`,
`add_user_date_relation`, `remove_user_date_relation`, the stack presentation
statics (`get_stack_label`, `get_stack_icon_classes`, `get_stack_icon_html`),
and thin delegators where required (below). Estimated ≤ 22 methods, ≤ 10
public, CC ≈ 45. If the stack presentation statics push a limit, they move to
a small `Decker_Task_Stack_Labels` helper as part of PR E.

`Task` model: the rendering cluster (`render_task_card`, `render_task_menu`,
`render_people_avatars`, card counters/background/CSS helpers,
`get_relative_time`, menu-item builders — ~45 CC) moves to a renderer;
data, loading (`load_meta_fields`) and domain predicates stay.

## PR sequence

Ordered by increasing risk; each PR is independently mergeable and follows the
established ritual (see Verification).

- **PR A — clone & merge engines.** `Decker_Task_Clone` and
  `Decker_Task_Merge` (together they would exceed the 50 threshold; they also
  change for different reasons). The shared task-meta getters
  (`get_task_assigned_users`, `get_task_board_id`, `get_task_label_ids`,
  `parse_duedate_meta`, `normalize_task_user_ids`) land in the class that uses
  them most with public access for the other, or in a tiny shared reader if
  both need them equally — resolved in the plan by call-graph. The REST/AJAX
  handlers (`handle_clone_task`, `handle_merge_task`) stay in place calling
  the engines, and move with the REST cluster in PR B.
- **PR B — REST controllers.** Three resource controllers in core style:
  `Decker_Tasks_Rest_Locks` (lock routes, handlers, heartbeat),
  `Decker_Tasks_Rest_Today` (today routes + `handle_task_today`),
  `Decker_Tasks_Rest_Ops` (assign/remove user, due date, search, clone/merge
  transport, REST update guard). The shared permission-callback factory
  (`make_permission_callback`) becomes one helper used by all three, not
  three copies. Route paths, args, and permission semantics byte-identical.
- **PR C — order/stack engine.** `Decker_Task_Order`. If the sum lands near
  50, the seam is engine (menu_order calculus, transitions) vs. hook
  adapters (`handle_board_change_reorder`, `handle_stack_change_reorder`,
  `modify_task_order_before_save`), split as `Decker_Task_Order` +
  `Decker_Task_Order_Hooks`.
- **PR D — admin meta boxes.** `Decker_Task_Meta_Boxes`, the
  `Decker_Event_Meta_Box` pattern. Characterization of rendered box HTML
  first if coverage is thin.
- **PR E — admin list & chrome.** `Decker_Task_Admin_List` (columns, sorting,
  filters, row actions, screen tweaks), the `Decker_Event_Admin_Screen`
  pattern.
- **PR F — save paths.** `Decker_Task_Meta_Saver` (admin `save_post` path)
  and `Decker_Task_Ajax_Save` (the `wp_ajax` handler and its readers).
  `handle_save_decker_task` keeps a public delegator on `Decker_Tasks` — six
  integration files call it directly and pin the lock/archive semantics.
- **PR G — write core + model.** `Decker_Task_Writer` owns
  `create_or_update_task( array $args )` (new canonical signature,
  `wp_parse_args` defaults, same validation and hook firing order), plus the
  private validate/build/insert/update pipeline. `Decker_Tasks` keeps
  `create_or_update_task` as a delegator with the **new** array signature —
  it is the documented entry point. All 46 call sites and
  `docs/agent-interfaces.md` migrate here. In the same PR, `Task`'s rendering
  moves to `Decker_Task_Card_Renderer` (templates keep calling
  `$task->render_task_card()` via thin delegation on the model — those calls
  are pinned by templates in ~12 places) and `TooManyFields` gets its
  justified suppression.

Alert arithmetic: A–F shed roughly 335 of 445 complexity and ~85 methods;
after F the class should clear `TooManyMethods`, `ExcessiveClassLength` and
`ExcessiveClassComplexity`; `TooManyPublicMethods`/`ExcessivePublicCount`
clear as the hook callbacks move with their hooks; `ExcessiveParameterList`
clears in G. `Task` clears both in G. Expected end: **0 PHPMD alerts**.

## Compatibility policy

Unchanged since #291: internal reorganisation, recorded per-PR in the
`docs/development.md` table, no shims. Delegators only where an anchor
exists: `handle_save_decker_task` (integration tests), `create_or_update_task`
(documented API, new signature), `$task->render_task_card()` /
`render_task_menu()` / `render_people_avatars()` (template call sites),
and `clone_task` / `merge_tasks` only if the plan's call-graph shows external
callers. Hook callbacks move with their hooks and need none.

## Simplification commitments

- **Dead-code sweep per cluster before moving** — nothing is relocated
  without confirming it has callers.
- **One altitude per concern**: hook wiring in constructors, logic in
  collaborators, no hook registration sprinkled through method bodies.
- **Reuse over copies**: one permission-callback helper for the three REST
  controllers; the clone/merge shared readers resolved once.
- `/simplify` runs on each PR's diff before it opens.

## Verification ritual (every PR)

1. Baseline the relevant suites (serial; the tasks clusters have ~20 test
   files) and add characterization only where a moved behaviour lacks a pin —
   with mutation checks proving new tests can fail.
2. Move method bodies verbatim (script-extracted, `php -l` after every step);
   behaviour diffs limited to delegation prefixes and constant homes.
3. `phpmd` before/after diff keyed on file + rule — the moved warnings gone,
   **zero added** (fresh `~/.pdepend`), every new class clean.
4. Full suite, serial, on a reset tests environment when stale failures
   appear; `phpcs`; `make check-untranslated` (POT line references only).
5. CI green; alert count confirmed via the code-scanning API on the PR ref.

## Risks

- **The signature migration (G) touches the task factory used by every
  suite.** Mitigated by ordering (last, on a stable base) and by the lock-in
  suites that exist precisely to pin `create_or_update_task` semantics.
- **`wp_send_json_*` bare-die in AJAX handlers** (PR F): tests must use the
  existing capture helpers in `Decker_Test_Base`.
- **Static engine methods may have unseen callers** (PR A): the plan opens
  with a call-graph sweep, case-insensitive (a `Decker_KB::` casing variant
  slipped through round 5's sweep and was caught by the full suite).
- **Route definition drift** (PR B): the route table (paths, methods, args,
  permissions) is characterized before the move by asserting against
  `rest_get_server()->get_routes()`.
