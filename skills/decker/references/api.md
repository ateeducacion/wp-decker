# REST surface

Decker's own domain services are the single source of truth. The client adds no
storage and no business rules: it speaks the routes below, and the server
enforces every permission.

## Abilities

WordPress 6.9 ships the Abilities API. Decker registers its operations as
abilities and opts each one into REST, so they are reachable at:

```
/wp-json/wp-abilities/v1/abilities/<namespace>/<ability>/run
```

| Ability | Method | Purpose |
| --- | --- | --- |
| `decker/list-tasks` | GET | List tasks, with the filters below |
| `decker/get-task` | GET | One task by id |
| `decker/list-boards` | GET | Boards |
| `decker/list-users` | GET | Resolve a name to a user id |
| `decker/search-knowledge-base` | GET | Article summaries |
| `decker/get-knowledge-article` | GET | One article, full body |
| `decker/create-task` | POST | Create |
| `decker/update-task` | POST | Update selected fields |
| `decker/move-task` | POST | Stack or board transition |
| `decker/archive-task` | POST | Archive or restore |

Core derives the method from the ability's annotations: read-only abilities
require GET, mutating ones POST. No Decker ability is annotated destructive, so
**none uses PUT or DELETE** — which matters where a proxy handles methods
unevenly. There is no delete ability at all; archiving is reversible.

`list-tasks` accepts `search`, `board_id`, `stack`, `status`, `page`,
`per_page`, `include_hidden`, plus `relation` (`any`, `assigned`,
`responsible`, `author`) and `today`. The last two resolve against the
authenticated user and take **no user id**: listing another person's workload
is not offered.

### Input transport

GET input goes in the `input` query parameter using bracket syntax
(`?input[task_id]=123`), POST input in the JSON body under `input`. Nothing in
WordPress JSON-decodes a string there, so URL-encoded JSON is rejected as
`ability_invalid_input` even though some documentation shows it that way.

WordPress 7.1 coerces GET scalars to the schema types; **6.9 does not**, so on
that baseline every GET value arrives as a string. The client therefore sends
booleans as `1`/`0`, never `true`/`false`, since a string cast makes `"false"`
true.

Core validates input *before* it calls the permission callback, so a malformed
request can report 400 where a well-formed one from the same caller would
report 401 or 403. Authorization is not weakened; only the error order differs.

## Core routes

| Route | Method | Used for |
| --- | --- | --- |
| `/wp/v2/labels` | GET | Label names and ids |
| `/wp/v2/comments` | GET, POST | Task comments |

Comments go through core's own route with Decker's comment protection applied
server-side. There is no separate comment store.

`/wp/v2/users` is deliberately **not** used. `list_users` is an administrator
capability, so core answers an ordinary Decker user with HTTP 200 and an empty
array — which reads as "that person does not exist" and invites picking the
wrong user. `decker/list-users` exists for this, and returns only `id`, `name`
and `slug`.

## Decker routes

| Route | Method | Used for |
| --- | --- | --- |
| `/decker/v1/tasks/{id}/today` | POST (also PUT) | Personal "for today" relation |
| `/decker/v1/tasks/{id}/order` | POST (also PUT) | Position within a board and stack |

Both accept POST as well as PUT, because a PUT body does not survive every
host. Same callback, same domain operation, two transports.

The `today` operation applies to the authenticated user and rejects a
client-supplied `user_id` on both transports.

The order endpoint takes `board_id`, `source_stack`, `target_stack`,
`source_order` and `target_order` — positions, not a bare index. The client
reads the live stack first so `source_order` is the task's real position: a
guessed value silently reorders other people's cards.

Other `decker/v1` routes exist (`stack`, `assign`, `leave`, `lock`,
`update_due_date`, `subtasks`, `attachment-links`, `fix-order`). The client
does not use them, because the abilities cover the same operations with
schemas, and `fix-order` is an administrator repair tool.

## Routing

Both of WordPress's documented routings work: `/wp-json/` and `?rest_route=`.
The client probes for whichever answers with JSON, stores the choice and uses
it consistently. Both are WordPress's own routing; neither bypasses an access
control, and the alternate form is only useful where a proxy does not expose
the pretty one.

## What the client will not do

There is no command that takes an arbitrary REST route, so an agent can only
perform the operations listed here. Nothing is retried automatically, and no
operation falls back to a different route when one fails.
