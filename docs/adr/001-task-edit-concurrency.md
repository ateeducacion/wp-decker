# ADR 001 — Task edit concurrency: advisory locking + detect-and-reject

- **Status:** Accepted
- **Date:** 2026-07-21
- **Context:** PR #277 and its five review rounds

## Context

Decker is an internal Kanban board for one team in a low-usage deployment,
with no hostile actors or external API consumers. The observed incident class:
a user leaves a task form open, another user takes over editing and saves, and
the first user's stale form later silently overwrites the newer content.
Infrequent, but real. `decker_task` supports WordPress revisions. Recovery is
partial by design: revisions capture the standard WordPress fields (title,
description) but **not** post meta or taxonomy terms (assignees, labels, board,
stack, due date). A clobbered meta field is restored by hand by whoever is
editing the card — in every residual scenario below, that person is actively
looking at it.

WordPress core provides **advisory** locking only: `wp_set_post_lock()` /
`wp_check_post_lock()` write and read `_edit_lock` with plain meta writes,
the edit screen shows a takeover dialog via the Heartbeat
`wp_refresh_post_lock` path — and **no core save path rejects a save because
of the lock** (the only write-path caller in core is `bulk_edit_posts()`,
which skips locked posts). Core's real safety net is revisions. These helpers
are also admin-only, so Decker's front-end app cannot use them directly.

Across five adversarial review rounds, PR #277 grew from "keep the edit lock
after save" into a CAS-based optimistic-concurrency protocol: compare-and-swap
state writes with retry loops, per-request write leases with absolute
deadlines and `lease_id`s, lease renewal at the DB write, shutdown-hook
recovery, anonymous leases for never-locked REST updates, and a fail-closed
write-time guard. Each mechanism closed a real but progressively narrower
race. The cost grew to 4 lock classes, ~1050 LOC and ~65 lock tests.

## Decision

**The invariant Decker enforces is DETECT-AND-REJECT, not SERIALISE-WRITES:**

> Every save of an existing task through Decker's endpoints must present the
> generation token its form rendered with. If the task's current token
> differs — or another user holds a fresh advisory lock — the save is
> rejected with HTTP 409 and the user is told to reload.
>
> Because the editor form resubmits the *whole* task, any mutation that
> reaches a card outside that form must rotate the generation, so a form
> rendered before it cannot silently revert it.

Mechanism (state: one meta key `_decker_edit_lock_state` =
`{"user":int,"token":"uuid","time":int}`; two small classes):

- **Advisory lock**, core-compatible: acquire/refresh via heartbeat, explicit
  takeover, stale window from `wp_check_post_lock_window`, `_edit_lock`
  mirrored for wp-admin interop. Plain meta writes, exactly like core.
- **Generation token**: minted on ownership change / explicit takeover; kept
  after release (`time = 0`) so a stale form stays invalid after the winner
  leaves; **rotated on every committed mutation of the task** so any other
  stale form (including a second tab of the same user) is rejected on its
  next save.
- Enforcement points (reject): the AJAX save, the generic `/wp/v2/tasks/{id}`
  REST update (`rest_pre_insert_decker_task`), and the heartbeat (flags
  `stale_session` so the UI blocks the stale editor immediately).
- Rotation points (invalidate): the AJAX save, the generic REST update
  (`rest_after_insert_decker_task`), and every out-of-band mutating endpoint —
  `/order`, `/stack`, `/assign`, `/leave`, `/update_due_date`, `/merge` — via
  `Decker_Task_Locks::invalidate_sessions()`, which rotates the token while
  preserving the owner and timestamp. Rotation is a no-op on a never-locked
  task, so tokenless internal writes keep working.

  Consequence, accepted deliberately: moving a card someone has open makes
  their next save return 409 and ask for a reload. A visible reload beats a
  silent revert, and the alternative — refusing the drag while a card is open
  — would break the board's normal workflow.

Serialising concurrent writes (leases, CAS, write-time re-checks) is **out of
scope**: the requirement is "no user's edits are silently overwritten by a
stale form", where "stale" means minutes-to-hours old — human timescales.
Sub-second interleavings are not stale forms. For the current low-usage
scenario we judge them *very unlikely* — they need two people writing the same
card within the same request — and partially recoverable. These are qualitative
judgements from the team's observed usage, not measured rates; we have no
instrumentation for concurrent writes. Treat the column below as an ordering
of plausibility, not as frequencies.

## Accepted residual risks

| # | Residual race | Window | Plausibility (qualitative) | Consequence | Recovery |
|---|---|---|---|---|---|
| 1 | Takeover lands between a save's token validation and its `wp_update_post()` | ~tens of ms, requires a takeover in that exact window | very unlikely | The pre-takeover save commits; the taker starts from it or overwrites it | Revisions (title/desc); meta fixed by the taker, who is looking at the card |
| 2 | Two saves presenting the **same** valid token commit concurrently (double-submit, two same-user tabs racing) | ~request duration | unlikely (client disables Save while submitting) | Last write wins between the same user's own sessions | Revisions (title/desc); meta re-entered by the same user |
| 3 | Two lock operations (acquire/takeover) write lock state in the same instant | ~ms | unlikely | One lock write lost; state self-heals on next heartbeat (≤ 15 s) | None needed; content is never affected |
| 4 | Concurrent **first** lock writes on a never-locked task duplicate the meta row | ~ms, first lock only | very unlikely | Redundant meta row; behaviour unchanged (single `get_post_meta` reads one row) | Delete the extra row if ever noticed |
| 5 | wp-admin classic-editor save ignores the generation (core has no enforcement hook there) | n/a | rare (team uses the Decker UI) | Same protection level as every other WordPress post type | Revisions (title/desc); meta by hand |
| 6 | `_edit_lock` interop is one-way: `active_lock()` only consults the native lock when the Decker state is inactive, and the mirror is cleared by owner id without comparing timestamps | n/a | rare (Gutenberg is disabled for `decker_task`; the team edits in the Decker UI) | A wp-admin takeover is not seen by Decker, and A's next heartbeat overwrites it | Reload; do not treat this as full native-editor interop |

If any of these is ever observed in practice — even once — the first
escalation is **not** a lease: it is `GET_LOCK`/`SELECT ... FOR UPDATE`
around the save endpoint, or moving the team to the collaborative-editing
mode (CRDT) that already ships in Decker and stands the lock down entirely.

Note for whoever reaches for `GET_LOCK`: it is connection-scoped. That is
fine in production under PHP-FPM, where each request gets its own connection,
but it cannot be exercised by the in-process concurrency our PHPUnit suite
injects. Budget for an integration harness with real parallel requests, or
the mechanism will look untestable and get replaced by an in-state lease
again.

Revisions recover `post_title` and `post_content` only. Everything Decker
keeps in post meta — stack, board, assignees, labels, due date, priority — is
**not** revisioned and must be re-entered by hand. In each residual above the
person who would re-enter it is already looking at the card, which is why we
accept it; but "revisions recover it" is not true of a whole task.

## Consequences

- 2 lock classes (~680 LOC) instead of 4 (~1050 LOC); no leases, lease ids,
  deadlines, renewal, shutdown recovery, CAS retry loops, or test-only
  injection hooks in production code.
- The protection that addresses every *observed* incident (stale-form
  overwrite after takeover, post-release stale save, heartbeat re-auth,
  same-user second tab, REST bypass, token-scoped release) is kept and
  tested at the unit, integration, REST, JS and E2E levels.
- Future reviewers: before proposing serialisation mechanisms for the
  sub-second windows above, re-read the frequency × recoverability table and
  argue with its assumptions, not with the theoretical existence of the race.
