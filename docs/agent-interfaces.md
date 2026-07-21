# Agent-friendly interfaces

Decker exposes selected task-management operations through structured, permission-aware interfaces. Existing domain methods and REST routes remain the source of truth; the adapter does not create a parallel task API.

## WordPress Abilities API

The integration is enabled only when WordPress provides both `wp_register_ability_category()` and `wp_register_ability()`. Feature detection is used instead of a version-string comparison. Older supported WordPress versions load Decker normally without registering ability hooks.

The `decker` category contains:

| Ability | Type | Required access |
| --- | --- | --- |
| `decker/list-tasks` | Read-only | Authenticated user with `edit_posts`; each task additionally passes the hidden-visibility rule |
| `decker/get-task` | Read-only | `read_post` for the requested task and the hidden-visibility rule |
| `decker/create-task` | Mutating | Authenticated user with `edit_posts` |
| `decker/update-task` | Mutating, idempotent | `edit_post` and, when enabled, the existing task edit lock |
| `decker/move-task` | Mutating, idempotent | `edit_post` and, when enabled, the existing task edit lock |
| `decker/archive-task` | Mutating, reversible, idempotent | `edit_post`; the archived state must be explicit |
| `decker/list-boards` | Read-only | Authenticated user with `edit_posts` |
| `decker/search-knowledge-base` | Read-only | Authenticated user with `edit_posts` and per-post access |
| `decker/get-knowledge-article` | Read-only | Authenticated user with `edit_posts` and per-post access |

There is no delete ability. Archiving is reversible and is not marked destructive.

Decker has no board-level access control: every user with `edit_posts` can see every board and every non-hidden task, exactly as the board UI presents them. The only per-task restriction is the hidden-visibility rule (see below).

## Schemas and responses

Every ability uses explicit JSON Schema types, required fields, limits, enum values, and `additionalProperties: false`. Arbitrary metadata is not accepted. Task responses include task, board, label, assignee, responsible-user, due-date, priority, visibility, order, status, and modification data.

Dates use `Y-m-d`. Stack values are limited to `to-do`, `in-progress`, and `done`. Referenced users and taxonomy terms must exist.

Knowledge-base access is split so responses stay bounded: `search-knowledge-base` returns summaries (id, title, a plain-text excerpt, board, modified), and `get-knowledge-article` returns the full body of a single article by ID.

## Authentication and authorization

Abilities use the current WordPress authentication context. Existing cookie-authenticated REST requests continue to use WordPress nonces where appropriate. Other supported WordPress authentication mechanisms are not forced to provide a cookie nonce.

Authorization is enforced server-side. Object operations validate the requested task, post type, per-post capability, hidden-task visibility, and — when the edit-lock feature is enabled — the existing task edit lock. A hidden task is visible only to administrators or users directly related to it as author, responsible user, or assignee.

`include_hidden` defines the listing contract explicitly:

- **`include_hidden: false` (default)** — no hidden task appears at all, *even one you are related to*. This keeps default listings uncluttered. A hidden task you are entitled to see is still directly retrievable by ID through `get-task`; it simply does not surface in the default list.
- **`include_hidden: true`** — hidden tasks are included, but still only the ones that user is entitled to see (the per-task rule above). Others' hidden tasks never appear.

So `list-tasks` and `get-task` apply the same per-task rule; they differ only in that the default list additionally hides *all* hidden tasks until you opt in — a deliberate default, not an inconsistency.

### Pagination

`total` and `total_pages` always describe exactly the tasks the current user can see, so a page is never padded with, or emptied by, tasks that were filtered out. The default listing excludes hidden tasks in SQL and paginates in the database, so it scales without loading the whole board. The `include_hidden` listing resolves per-user visibility in PHP over a bounded candidate set (2000); if that cap is reached the response includes `truncated: true`.

Missing authentication returns HTTP `401`; an authenticated but forbidden request returns `403`.

The edit lock is advisory and only enforced when Decker's post-locking feature is active. There is no optimistic-concurrency token (ETag / `If-Unmodified-Since`) yet, so a concurrent UI edit and agent write can still race; add one if stronger guarantees are required.

No public or unauthenticated agent endpoint is registered. Multisite execution stays scoped to the current site.

## Relationship to existing behavior

Task writes call `Decker_Tasks::create_or_update_task()`. The adapter does not reimplement creation, update, taxonomy assignment, stack-transition hooks, notifications, or archive behavior. Existing REST routes remain available and backward compatible.

Writes carry the complete task state: unspecified fields keep their stored values, and an empty `label_ids` clears the task's labels rather than leaving them untouched. The adapter also re-reads and preserves the task's Nextcloud card link, which the shared domain method rewrites on every save.

## Browser semantics

The main interface is progressively enhanced with associated labels and stable names for filters, accessible names for icon-only controls, labelled task regions, list semantics, and appropriate status/alert roles. Enhancements are additive and idempotent — they only set attributes and add hidden labels on the existing markup, never replacing nodes, so event listeners, saved references, and Bootstrap component instances survive. `window.DeckerAgentSemantics.enhance()` may be re-run after a dynamic render. The native "Fix Order" control is a real `<button>` in the server-rendered markup rather than a scripted anchor swap. Existing IDs, classes, data attributes, and backend operations are preserved.

## WebMCP status

WebMCP is deferred. Its browser contract remains experimental, and speculative attributes would create an unstable public surface. A future implementation must remain progressive and call the same secured backend operations.

## Trust boundary and prompt injection

Task titles, descriptions, comments, labels, knowledge-base articles, imported content, and other user-provided text are untrusted data. Stored content must never control authorization, ability registration, tool selection, another operation, secret disclosure, or system configuration.

No task content is automatically executed as a prompt. API keys, nonces, internal paths, provider prompts, and private configuration are excluded from responses.

## Out of scope

This integration does not provide an MCP server, MCP transport, remote MCP adapter, autonomous background actions, Schema.org publication of private tasks, or an API that bypasses Decker permissions.
