# User Guide

## Kanban board

Tasks are organized in columns (stacks). The most common are:

- To Do
- In Progress
- Done

You can create, edit, move (drag & drop) and archive tasks from the board.

## Priority system

Decker has a distinctive priority system. Tasks can be marked with **Max Priority** so they stand out.

## Creating tasks

- From the board interface (recommended).
- Via URL parameters (useful for integrations):

```
/?decker_page=task&type=new&title=My+task&stack=to-do&maximum_priority=1
```

Available parameters include `title`, `description`, `board`, `stack`, `maximum_priority`.

## Subtasks

Every task has a *Subtasks* tab. It can be turned off in **Settings → Decker
→ Subtasks**.

- Each subtask has a title, one of the three task states (to-do, in progress,
  done) and an optional assignee. Subtasks have no due date of their own: the
  deadline belongs to the task. Clicking the state icon cycles through the
  three; the change is saved straight away, with no card save.
- The assignee list is limited to the people already on the task, and shows
  their picture. A new subtask starts out yours.
- Pasting several lines into the "add" field creates one subtask per line, which
  is how an existing checklist or spreadsheet column gets in.
- Subtasks are reordered by dragging the handle on the left.
- The board card and the task list show `done/total` plus a bar in the three
  state colors. Clicking it opens the card on the Subtasks tab.
- The board card also shows up to three subtasks as chips, one per row and the
  full width of the card, with a `+N` beside the last one for the rest. Only open ones get a chip — finished work is already in the counter —
  and **your own come first, tinted blue**, so you can see what is yours
  without opening the card. Two people therefore see different chips on the
  same card. The rest of the order is: started before untouched, then the order
  the list is in. A chip links to the card's Subtasks tab; its tooltip carries
  the full title, the owner and the state.
- Cloning a task copies its subtasks.
- A subtask that needs a deadline of its own, or anything else a task has, can
  be promoted with the branch icon: it becomes a task of its own on the same
  board, starting on the parent task's due date, and the item stays in the list
  linked to it.

Subtasks are not tasks: they have no comments, attachments, history or calendar
entry, and they are not found by search. Promote one when it needs any of that.
See [ADR 003](adr/003-lightweight-subtasks.md) for why.

## Editing protection

Opening a task for editing acquires an edit lock. Another user can explicitly
take over; older forms are then rejected on save and must be reloaded. Real-time
collaborative editing is no longer available.

## Email to post

There is a REST endpoint that accepts emails (authenticated with the Shared Key) and turns them into tasks.
