# ADR 001 — Task edit concurrency: advisory locking + detect-and-reject

- **Status:** Accepted
- **Date:** 2026-07-21
- **Context:** PR #277 and its five review rounds

## Context

Decker is an internal Kanban board for one team (~17 concurrent users, same
organisation, no hostile actors, no external API consumers). The observed
incident class: a user leaves a task form open, another user takes over
editing and saves, and the first user's stale form later silently overwrites
the newer content. Infrequent, but real. `decker_task` supports WordPress
revisions, so any overwrite is recoverable from the revision history.

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

Mechanism (state: one meta key `_decker_edit_lock_state` =
`{"user":int,"token":"uuid","time":int}`; two small classes):

- **Advisory lock**, core-compatible: acquire/refresh via heartbeat, explicit
  takeover, stale window from `wp_check_post_lock_window`, `_edit_lock`
  mirrored for wp-admin interop. Plain meta writes, exactly like core.
- **Generation token**: minted on ownership change / explicit takeover; kept
  after release (`time = 0`) so a stale form stays invalid after the winner
  leaves; **rotated on each successful save** so any other stale form
  (including a second tab of the same user) is rejected on its next save.
- Enforcement points: the AJAX save, the generic `/wp/v2/tasks/{id}` REST
  update (`rest_pre_insert_decker_task`), and the heartbeat (flags
  `stale_session` so the UI blocks the stale editor immediately).

Serialising concurrent writes (leases, CAS, write-time re-checks) is **out of
scope**: the requirement is "no user's edits are silently overwritten by a
stale form", where "stale" means minutes-to-hours old — human timescales.
Sub-second interleavings are not stale forms; at 17 users their expected
frequency is well below one per decade, and revisions recover them.

## Accepted residual risks

| # | Residual race | Window | Est. frequency @ 17 users | Consequence | Recovery |
|---|---|---|---|---|---|
| 1 | Takeover lands between a save's token validation and its `wp_update_post()` | ~tens of ms, requires a takeover in that exact window | ≪ 1/decade | The pre-takeover save commits; the taker starts from it or overwrites it | Revisions; the taker is looking at the task anyway |
| 2 | Two saves presenting the **same** valid token commit concurrently (double-submit, two same-user tabs racing) | ~request duration | ≪ 1/year (client disables Save while submitting) | Last write wins between the same user's own sessions | Revisions |
| 3 | Two lock operations (acquire/takeover) write lock state in the same instant | ~ms | ≪ 1/year | One lock write lost; state self-heals on next heartbeat (≤ 15 s) | None needed; content is never affected |
| 4 | Concurrent **first** lock writes on a never-locked task duplicate the meta row | ~ms, first lock only | ≪ 1/decade | Redundant meta row; behaviour unchanged (single `get_post_meta` reads one row) | Delete the extra row if ever noticed |
| 5 | wp-admin classic-editor save ignores the generation (core has no enforcement hook there) | n/a | rare (team uses the Decker UI) | Same protection level as every other WordPress post type | Revisions |

If any of these is ever observed more than ~once a year in practice, the
first escalation is **not** a lease: it is `GET_LOCK`/`SELECT ... FOR UPDATE`
around the save endpoint, or moving the team to the collaborative-editing
mode (CRDT) that already ships in Decker and stands the lock down entirely.

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
  argue with its numbers, not with the theoretical existence of the race.
