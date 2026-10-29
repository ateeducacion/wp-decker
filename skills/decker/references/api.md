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
| `/decker/v1/tasks/{id}/order` | POST (also PUT) | Position by index, within a board and stack |
| `/decker/v1/tasks/{id}/place` | POST (also PUT) | Position next to a named card |

Both accept POST as well as PUT, because a PUT body does not survive every
host. Same callback, same domain operation, two transports.

The `today` operation applies to the authenticated user and rejects a
client-supplied `user_id` on both transports.

The order endpoint takes `board_id`, `source_stack`, `target_stack`,
`source_order` and `target_order` — positions, not a bare index. The client
reads the live stack first so `source_order` is the task's real position: a
guessed value silently reorders other people's cards. Even then the result is
not exact when the stack holds tasks the caller cannot list, which is what
`/place` exists for.

The place endpoint takes exactly one of `after_task_id` or `before_task_id` and
nothing else: no board, no stack, no index. The neighbour's board and stack are
the destination, so it moves the card there as well as positioning it, and it
needs no prior read. It answers with `relative_to`, `side`, `stack`, `adjacent`
— read back from the stored order — and `changed`, which is `false` when the
card was already there, in which case nothing is written and no open editor
form is invalidated.

It refuses rather than guessing: 404 for a neighbour that does not exist **or
is a hidden task the caller may not see** (the same answer, so the route cannot
confirm that a hidden task exists), 400 for a neighbour on another board or for
zero or two neighbours, 403 for an archived card, and 409
`decker_priority_mismatch` when only one of the two cards has `max_priority`,
because that pulls it to the front of the stack and the two cannot be
adjacent. That last refusal is deliberate and a little wider than strictly
necessary: the boundary between the two groups is a position where cards of
different priority do meet, and a placement that is **already** true there is
answered with `changed: false` rather than refused, but a move across the
boundary is not attempted, because the renumber pass sorts priority first and
would undo it. The sequence itself is never returned: it contains tasks the
caller may not be allowed to see.

Every `decker/v1/tasks/{id}` route, this one included, refuses a card hidden
from the caller with 403 `rest_forbidden` before the callback runs. The
object-level capability is not enough on its own: `edit_post` is held over
other people's cards by an editor, while Decker's hidden-task rule answers to
participation, not to capabilities.

`adjacent` and `changed` are statements about the whole stack, so both disclose
whether a card the caller cannot see sits between the two named ones — their
presence, never their identity or content. That is accepted: verified adjacency
is the contract this route exists to provide, and it cannot be verified against
a partial view.

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
