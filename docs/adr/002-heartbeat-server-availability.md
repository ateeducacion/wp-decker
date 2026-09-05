# ADR 002 — Reuse WordPress Heartbeat to report the connection state

- **Status:** Accepted
- **Date:** 2026-09-04

## Context

A Decker page is a long-lived form. Three different failures make a save fail,
and the user cannot tell them apart from the UI: the browser has no network, the
server does not answer, or the WordPress session is gone. The last one is the
nastiest, because the server still answers `200 OK` — nothing looks broken until
Save fails.

Decker already runs the WordPress Heartbeat API for notifications and task edit
locks, so the connection is already being probed every 15 seconds.

## Decision

Reuse the signals that already exist. No new polling, no health endpoint, no
extra request of any kind:

| State | Signal | Source |
|---|---|---|
| No network | `offline` / `online` window events | Browser, fires without any request |
| Server unreachable | `heartbeat-connection-lost` / `-restored` | Heartbeat, after its own retries |
| Session expired | `wp-auth-check === false` on `heartbeat-tick` | Every Heartbeat response |

`wp-auth-check` needs no cooperation from the page: core filters `wp_auth_check()`
onto both `heartbeat_send` **and** `heartbeat_nopriv_send`, so once the cookie is
gone the request falls through to the no-privilege handler and still reports
`false`. Loading the `wp-auth-check` script is not required, and is not done —
its admin-styled overlay would look foreign on a Decker page.

`navigator.onLine` is consulted only to label a Heartbeat failure that happens
while the browser reports no network. It is never used as the sole signal: it is
a link-layer check, so it says nothing about whether WordPress is reachable.

`heartbeat-nonces-expired` is deliberately **not** handled. It fires only while
the user is still logged in with a nonce that no longer verifies, and core heals
that case by itself on the same tick — `wp_refresh_heartbeat_nonces()` returns
fresh Heartbeat and REST nonces, which `heartbeat.js` writes back into
`wpApiSettings`. Reacting to it would show a warning that clears itself on the
next tick.

All three states share **one** banner, because from the user's point of view they
say the same thing: your changes cannot be saved right now. They differ in
wording, in colour (warning for a local network problem, danger for a server or
session problem) and in whether a log-in link is offered. The expired-session
banner links to the login page in a **new tab** rather than reloading: reloading
a form with unsaved changes destroys exactly what the warning is trying to
protect. Logging in in the other tab restores the cookie, and the next Heartbeat
tick clears the banner on its own.

A banner is used rather than a modal so that a user who knows better can keep
reading the board while the server is down.

## Consequences

Detection follows Heartbeat semantics: transient request failures are retried by
WordPress before Decker says anything, and the banner appears one Heartbeat
interval (15 s by default) after the failure at worst. That interval is a fit for
a low-usage deployment; a busier one should raise it, which trades detection
latency for request load.

Because there is no additional polling, the cost of this feature is zero extra
requests per tab — a user with several Decker tabs open pays nothing beyond the
Heartbeat traffic those tabs already generated, which WordPress itself throttles
in background tabs.
