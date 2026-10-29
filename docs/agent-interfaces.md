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

## REST exposure

Every Decker ability sets `meta.show_in_rest => true`, so each one is reachable at:

```
/wp-json/wp-abilities/v1/abilities/<namespace>/<ability>/run
```

`show_in_rest` is set explicitly rather than through the `public` meta, which only
exists from WordPress 7.1; an explicit value is honoured by every WordPress that
has the Abilities API at all. The route base is `abilities` in both 6.9 and 7.1.

Core derives the HTTP method from the annotations: read-only abilities require
`GET`, mutating ones `POST`. No Decker ability is annotated destructive, so none
requires `DELETE` — and none requires `PUT`, which matters where a proxy handles
methods unevenly.

### Input transport differs by WordPress version

`GET` input is read from the `input` query parameter and `POST` input from
`input` in the JSON body. On `GET` the parameter must use bracket syntax
(`?input[task_id]=123`), not URL-encoded JSON: nothing in the request path
JSON-decodes a string, so `?input={"task_id":123}` is rejected as
`ability_invalid_input` with HTTP 400.

WordPress 7.1 coerces `GET` scalars to the schema types before the ability runs;
**6.9 does not**, so on the deployed 6.9 baseline every `GET` value arrives as a
string. Decker's read path therefore coerces defensively — `absint()` for ids and
`rest_sanitize_boolean()` for flags, so that `input[include_hidden]=false` is
false rather than a non-empty string. Mutating abilities are `POST`-only and
receive natively typed JSON, so they rely on schema validation.

Abilities whose input is entirely optional declare a `default` of `{}`, so a bare
`GET` on the run route works instead of failing type validation.

Core validates input *before* it calls the permission callback, so a malformed
request can return 400 where a well-formed one from the same caller would return
401 or 403. Authorization is not weakened by this; only the error ordering differs.

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

## The installable Agent Skill

`skills/decker/` is an Agent Skill: `SKILL.md`, five `references/` documents and
a dependency-free Node client. It is installed with

```
gh skill install ateeducacion/wp-decker skills/decker --agent <agent> --scope user
```

and is verified by installing it, for Claude Code and for Codex, rather than by
checking that the file exists. `make check-skill` validates the metadata, that
every file `SKILL.md` references exists, that the client runs, and that git
tracks all of it.

That last check earns its place: an unanchored `decker/` rule in `.gitignore`
hid the whole directory twice during development. `git add -A` skipped the files
in silence, the working tree looked clean, and the only thing that noticed was
an actual install reporting no `SKILL.md` in the repository. The ignore rules
for a built or extracted plugin copy are now anchored to the root.

### Versioning

Both artifacts come from the same repository and the same tag, so there is
nothing to keep in step: the plugin ZIP is built by `make package VERSION=x`
from the working tree, and the skill is installed from the same revision.

The client carries **no version constant of its own** and no compatibility
table to maintain. It discovers what the site supports at runtime: `doctor`
reports which routing answers and which Decker abilities the site exposes, so a
plugin too old to expose them is reported as `none exposed: update the Decker
plugin` instead of failing later with a confusing 404.

The skill is excluded from the plugin ZIP (`/skills` in `.distignore`) because
it is not plugin runtime code, but deliberately **not** export-ignored in
`.gitattributes`, since `gh skill install` reads the repository. `make
check-skill` asserts both.

## Relationship to existing behavior

Task writes call `Decker_Task_Writer::create_or_update_task( array $args )`. The adapter does not reimplement creation, update, taxonomy assignment, stack-transition hooks, notifications, or archive behavior. Existing REST routes remain available and backward compatible.

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
