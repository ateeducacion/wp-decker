# Agent-friendly interfaces

Decker exposes selected task-management operations through structured, permission-aware interfaces. Existing domain methods and REST routes remain the source of truth; the adapter does not create a parallel task API.

## WordPress Abilities API

The integration is enabled only when WordPress provides both `wp_register_ability_category()` and `wp_register_ability()`. Feature detection is used instead of a version-string comparison. Older supported WordPress versions load Decker normally without registering ability hooks.

The `decker` category contains:

| Ability | Type | Required access |
| --- | --- | --- |
| `decker/list-tasks` | Read-only | Authenticated user with `edit_posts` |
| `decker/get-task` | Read-only | `read_post` for the requested task and Decker visibility rules |
| `decker/create-task` | Mutating | Authenticated user with `edit_posts` |
| `decker/update-task` | Mutating, idempotent | `edit_post` and the existing task edit lock |
| `decker/move-task` | Mutating, idempotent | `edit_post` and the existing task edit lock |
| `decker/archive-task` | Mutating, reversible, idempotent | `edit_post`; the archived state must be explicit |
| `decker/list-boards` | Read-only | Authenticated user with `edit_posts` |
| `decker/search-knowledge-base` | Read-only | Authenticated user with `edit_posts` and per-post access |

There is no delete ability. Archiving is reversible and is not marked destructive.

## Schemas and responses

Every ability uses explicit JSON Schema types, required fields, limits, enum values, and `additionalProperties: false`. Arbitrary metadata is not accepted. Task responses include task, board, label, assignee, responsible-user, due-date, priority, visibility, order, status, and modification data.

Dates use `Y-m-d`. Stack values are limited to `to-do`, `in-progress`, and `done`. Referenced users and taxonomy terms must exist.

## Authentication and authorization

Abilities use the current WordPress authentication context. Existing cookie-authenticated REST requests continue to use WordPress nonces where appropriate. Other supported WordPress authentication mechanisms are not forced to provide a cookie nonce.

Authorization is enforced server-side. Object operations validate the requested task, post type, per-post capability, hidden-task visibility, and edit lock. Hidden tasks are available only to administrators or users directly related as author, responsible user, or assignee.

No public or unauthenticated agent endpoint is registered. Multisite execution stays scoped to the current site.

## Relationship to existing behavior

Task writes call `Decker_Tasks::create_or_update_task()`. The adapter does not reimplement creation, update, taxonomy assignment, stack-transition hooks, notifications, or archive behavior. Existing REST routes remain available and backward compatible.

## Browser semantics

The main interface is progressively enhanced with associated labels and stable names for filters, native action buttons, accessible names for icon-only controls, labelled task regions, list semantics, and polite status regions. Existing IDs, classes, data attributes, and backend operations are preserved.

## WebMCP status

WebMCP is deferred. Its browser contract remains experimental, and speculative attributes would create an unstable public surface. A future implementation must remain progressive and call the same secured backend operations.

## Trust boundary and prompt injection

Task titles, descriptions, comments, labels, knowledge-base articles, imported content, and other user-provided text are untrusted data. Stored content must never control authorization, ability registration, tool selection, another operation, secret disclosure, or system configuration.

No task content is automatically executed as a prompt. API keys, nonces, internal paths, provider prompts, and private configuration are excluded from responses.

## Out of scope

This integration does not provide an MCP server, MCP transport, remote MCP adapter, autonomous background actions, Schema.org publication of private tasks, or an API that bypasses Decker permissions.
