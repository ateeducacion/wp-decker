# ADR 003 — Subtasks are a list on the task, not child tasks

- **Status:** Accepted
- **Date:** 2026-10-28

## Context

Teams were tracking the breakdown of a large task in spreadsheets outside
Decker: one row per step, with a state (not started / started / finished), an
owner, a due date and a rolled-up percentage. The card in Decker and the
spreadsheet then drifted apart, and keeping both in sync cost more time than
the breakdown saved.

The request was to bring that breakdown onto the card itself: a tab where a
task is split into steps, each step marked done in one click, and the board
card showing how far along the task is, in the to-do / in-progress / done
colors the board already uses.

The obvious modelling is a child `decker_task` via `post_parent`. It is also
the expensive one.

## Decision

A subtask is an entry in a single private post meta on its parent
(`_decker_subtasks`), owned by `Decker_Subtasks` and written only through
`decker/v1/tasks/<id>/subtasks`. The route is registered `EDITABLE`, so it
takes a POST as well as a PUT, and the browser posts: some hosts — WordPress
Playground among them — never hand PHP the body of a PUT, and the whole list
travels in that body.

An item holds exactly what the spreadsheets held: a title, one of the three
task states, an assignee, a due date. The states reuse the `stack` vocabulary
verbatim, so `Decker_Tasks::get_stack_label()` and `::get_stack_icon_classes()`
already supply their labels, icons and colors; no second vocabulary exists.

### Why not child posts

`decker_task` is registered `hierarchical => false`, and roughly fifteen places
query the post type: the board columns, the task table, upcoming, analytics,
the today queries, the board stack counters, the `menu_order` engine, the
abilities query service, the iCal feed and global search. Child tasks would
have to be excluded from each one, and the one that got missed would put
subtasks on the board as loose cards — the exact duplication this feature
exists to remove. The ordering engine recomputes `menu_order` per board and
stack, so children would also take part in an ordering they have no place in.

The meta list touches none of that. It is invisible to every existing query by
construction rather than by a filter that has to be remembered.

### The ceiling, and the way out

Items are deliberately not tasks. They have no comments, no attachments, no
revision history, no "for today", no iCal entry, and they are not found by
global search. The list is also not queryable field by field: "every subtask
assigned to me, across tasks" is not a question this storage can answer
cheaply.

When an item turns out to need those things, it is promoted instead of being
modelled for in advance: `POST .../subtasks/<item>/convert` creates a real task
from it (inheriting the parent's board, and its responsable and due date when
the item carries none) and leaves the item in the list, linked to the task it
became. Trello settled on the same split — a checklist item carries an assignee
and a due date, and converts into a card when it outgrows the list.

Once promoted, the row stops being the work and becomes a pointer to it. It is
read as that task from then on — title, responsable, due date and state come
from the task on every read, and the row is no longer editable here: the title
is a link to the task, and the owner is stated rather than picked, since the
task's responsable need not be anyone on this card. The stored copy is left
untouched and is what the row falls back to if the task is ever deleted.

The promoted task says which list it came out of, both on its board card and at
the top of its editor. That link is looked up, not stored: a copy on the child
would have to be written on promotion, cleared on unlink and backfilled for
everything promoted before it existed — three ways to disagree with the list,
which stays the one source. The lookup reads every stored list in one query and
indexes it by child for the rest of the request, because a board asks the
question once per card.

Archiving a promoted task takes its row off the parent's list: that is how
someone says the step is over and out of the way, and a row naming an archived
card is worse than no row. Unarchiving does not put it back — the list is a
list, not a history — and the row's own state otherwise follows the task's
stack, since the two vocabularies are the same three words.

And so
taking it off the list is an unlink, not a delete: a different icon, no red,
and a question that says the task stays on the board. Archiving that task is an
opt-in checkbox on the same question — clearing the list usually means the work
is over, but the client decides, and the archiving goes through `wp/v2/tasks`
after the shorter list is stored, so a failure on either side keeps its own
message. Nothing is written into the child task: the item still holds the only
link, and a reverse one would need its own meta and a backfill.

If cross-task reporting on items ever becomes a real requirement rather than an
anticipated one, that is the point to revisit child posts, with the promotion
path already in place.

### Concurrency

The list is a shared task field, so its write path takes the editor form's
generation token and `Decker_Task_Locks::assert_user_can_save()` rejects a
stale form — the same contract as the attachment-link route. One route
replaces the whole list, which is why reordering, editing and deleting all
share a single write and a single sanitization pass.

The meta is **not** registered with `register_post_meta()`. Exposing it on the
generic `/wp/v2/tasks` endpoint would let a client write items straight into
the meta, bypassing both the per-field sanitization and that lock check.

### What the card shows

Showing one chip per subtask works at three items and buries the card at
fifty, which is the size the spreadsheets this replaces actually reach. The
card therefore takes at most three chips plus a `+N`, and
`Decker_Subtasks::select_card_chips()` decides which three: finished items
never take one (the counter and the bar already carry them, and a finished
task showing no chips is the right signal), and the rest are ordered by the
viewer's own items first, then started ones.

That ordering is the reason the chips exist at all — people scan a board for
their own work — and it has a consequence worth stating: two people see
different chips on the same card, so a chip row is not something two of them
can compare over the phone.

Each chip takes a row of its own, the full width of the card. Side by side
they had to be capped at 150px and most of a real title was ellipsis, which
defeats the point of showing it; a card is a column, so vertical space is the
cheap one. Only the last chip gives up part of its row, to the `+N`.

## Consequences

- No existing task query, counter or ordering behavior changes.
- The feature is gated by `decker_settings[subtasks_enabled]`, which is on
  unless it has been explicitly cleared; turning it off hides the tab, the card
  counter and the menu entry, and the stored lists are kept.
- Cloning a task copies its list, since a task broken into steps is usually
  cloned to reuse that breakdown.
- Item titles accept a multi-line paste as one item per line, which is how an
  existing spreadsheet column gets in.
- A task holds at most 200 items, bounding the serialized meta and the render.

## Addendum — 2026-10-29: Remove due dates from lightweight subtasks

### Context

The first implementation gave every item a due date of its own, prefilled from
the parent task's when the item was created. In use that turned out to be a
second planning layer: moving the task's deadline left every item sitting on
the old one, and nothing reconciled the two. Those dates also went nowhere —
they were not in the calendar, not in search, not in upcoming, not in any of
the planning views — so they could be wrong without anything noticing, while
looking exactly as authoritative as the task's own date.

### Decision

Subtasks have no due date. The deadline belongs to the task; an item is a step
of executing it, not something scheduled on its own. A step that needs its own
deadline is promoted into a task, which is what promotion was already for.

`duedate` is gone from the item model, from `Decker_Subtask_Sanitizer`, from
the REST payload and from the row. A `duedate` sent by a client is dropped like
any other unknown field.

### Consequences

- One field fewer in the model, and `sanitize_date()`,
  `resolve_promoted_duedate()`, `buildDueDateField()`, `defaultDueDate()` and
  the overdue flagging all go with it.
- The row loses a column, so the pane is narrower and reads as a checklist.
- There are no longer two dates that can disagree about the same work.
- A single step cannot carry its own deadline any more.
- Promotion is how that is recovered: the new task starts on the parent's due
  date and is edited from there like any other card.
- States, assignees, ordering, progress and the card chips are unchanged.

### Backward compatibility

The feature had not been released, so no site holds subtask dates that anyone
depends on; no backup meta and no migration are shipped for them. A stored
`duedate` left over from a development install is simply ignored on read — the
sanitizer does not return it — and disappears the next time the list is
written. Everything else on such an item (title, state, assignee, promotion
link) is preserved.

Tasks promoted before this change keep their due dates: they are ordinary
tasks, and nothing here touches task meta. A legacy item date is not used when
promoting after this change either — the new task starts on the parent task's
current due date, or on none when the parent has none.

### Alternatives considered

1. **Keep independent per-subtask dates.** The status quo, and the source of
   the drift described above.
2. **Inherit the parent's date dynamically.** The row would always agree with
   the task, but then the field shows information it cannot change, which is a
   worse version of showing nothing.
3. **Keep the field, hide it in the UI.** Dead data that the API still returns
   and that the next person has to decide what to do with.

Removal won on both counts: nothing to keep in sync, and nothing to explain.

This addendum supersedes the part of the original decision above that lists a
due date among a subtask's properties. The rest of that decision — the
serialized meta on the parent, the write path and its lock contract, the card
chips and the promotion path — stands unchanged.
