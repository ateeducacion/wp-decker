# Task model

A Decker task is a `decker_task` post. The fields below are what the API
returns and accepts; the storage column is listed because it explains several
behaviours that look surprising from outside.

| Field | Stored as | Notes |
| --- | --- | --- |
| `id` | post ID | |
| `title` | `post_title` | Required on create |
| `description` | `post_content` | Sanitized to Decker's allowed tags |
| `status` | `post_status` | `publish` or `archived` |
| `stack` | meta `stack` | `to-do`, `in-progress`, `done` |
| `board_id` | taxonomy `decker_board` | Exactly one board; required on create |
| `label_ids` | taxonomy `decker_label` | Any number |
| `assignee_ids` | meta `assigned_users` | Serialized array |
| `author_id` | `post_author` | Not writable through the agent surface |
| `responsible_user_id` | meta `responsable` | **Falls back to the author** when unset |
| `due_date` | meta `duedate` | `Y-m-d`, or empty |
| `max_priority` | meta `max_priority` | Sorts the card to the front of its stack |
| `hidden` | meta `hidden` | Restricts visibility, see below |
| `order` | `menu_order` | Position within board and stack |
| `modified` | `post_modified_gmt` | Also the concurrency token |

There is **no `archived` field in a read**: the archive state is `status`,
either `publish` or `archived`. A write takes `archived` as a boolean.

Three people-shaped fields, three different meanings: the **author** wrote it,
the **responsible user** owns it, the **assignees** work on it. A task with no
`responsable` meta reports its author as the responsible user, which is why
"responsible by me" cannot be a plain meta query.

Not exposed for writing: `id_nextcloud_card` and attachment links are preserved
across every update, and subtasks have their own lightweight model and
endpoints that this client does not touch.

## Omitting a field is not clearing it

A write carries the full task state, built from the current values with the
requested changes merged in. The rule is:

- a field **not mentioned** keeps its stored value;
- a field given **explicitly** replaces it, including an empty array.

So omitting `label_ids` preserves the labels, while `"label_ids": []` clears
them. The same holds for `assignee_ids`. The client's `label-add`,
`label-remove`, `assign` and `unassign` commands exist so that changing one
entry never means rewriting the collection by hand.

## Concurrency

`modified` is the optimistic-concurrency token. A write may carry
`expected_modified`, and the server refuses it with HTTP 409 when the task has
changed since that read, instead of overwriting whatever changed. The client
always sends it.

Two further rules, both server-side:

- Decker's advisory edit lock still applies. A task another user is editing
  cannot be written.
- A successful write **rotates the edit generation**, so a Decker editor form
  opened before the change can no longer save over it; whoever is editing keeps
  the lock and has to reload.

Known limit: `post_modified_gmt` has one-second granularity, so a change saved
within the same second as the caller's read carries an identical timestamp and
is not detected. The edit lock remains the primary defence.

## Hidden tasks

A hidden task is visible only to administrators and the people it concerns: its
author, its responsible user and its assignees. Two rules, deliberately
different:

- `get-task` applies the per-task rule, so an entitled person can always fetch
  a hidden task by id.
- the default listing excludes **every** hidden task, including one the caller
  is entitled to see, until `include_hidden` is set — and then it still only
  shows the ones they may see.

Hidden tasks' comments are protected by the same rule.

## Ordering

Order is `menu_order` within a board and stack, with `max_priority` cards
pulled to the front. The stored rule is `max_priority DESC, menu_order ASC,
post_modified DESC, id ASC`.

It is not an alphabetical or chronological sort, and it is not global: the same
position number means a different place in each board/stack pair. A new task
joins the end of its stack.

Two things worth knowing before touching order:

- Changing a task's stack is not the same as placing it at a position. `move`
  changes the stack and lets the domain recalculate; `reorder` positions it.
- **Position a card by naming its neighbour**, not by index: `--after ID` or
  `--before ID`. The server resolves that, where the whole stack is visible, so
  it is exact whatever the caller can see, and the neighbour's stack is the
  destination, so it moves the card between stacks too — within the same board
  only, as moving between boards is `move-task`. It writes nothing when the card
  is already in that position, and it refuses when only one of the two cards has
  `max_priority`, because priority pulls a card to the front and the two cannot
  then be adjacent.
- A position by index is a position *within the stack as the server sees it*,
  which includes hidden tasks the caller cannot list. The client reads the stack
  with hidden tasks included and paginates it fully, and refuses to reorder when
  it could not read the whole stack. **The index form cannot set a position
  exactly when the stack holds tasks belonging to other people that it cannot
  see**: the index it derives is not the index the engine uses, and the card can
  land anywhere between the neighbours it was aimed at, not merely one slot
  away. So the client reads the order back and compares: a card that did not
  land where it was asked is reported as a conflict naming where it actually is,
  never as a success. This is why `--after`/`--before` exists and is preferred.
- Stored `menu_order` values are **not** guaranteed contiguous. The renumbering
  pass excludes the card being moved, so it keeps an intermediate value while
  the others are renumbered. What holds is the relative sequence, and that no
  two cards in a stack share a value.

Editing a label, a description, an assignee, a comment or the "for today"
relation must never change the order. If it does, that is a bug worth
reporting, not something to compensate for in the client.

## "For today"

A personal, date-specific relation stored in meta `_user_date_relations`, as
`{user_id, date}` records. It is **not** the due date: it means "I plan to work
on this today", it belongs to one user, and marking it does not change the task
itself — no revision, no reordering, and it is independent of the edit lock.

The date is the site's date, in the WordPress timezone, which on a typical host
is not the server's. Marking is idempotent, and it never touches another user's
relation or another date's.
