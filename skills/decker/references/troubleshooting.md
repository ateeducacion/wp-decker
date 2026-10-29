# Troubleshooting

Run `decker doctor` first. It reports the Node version, the configured site and
CAS host, where the session is stored, which REST routing answers, whether the
session is still valid, and which Decker abilities the site exposes — without
printing a credential or any task content.

## Exit codes

These are a contract: a script or an agent may branch on them.

| Code | Meaning | What to do |
| --- | --- | --- |
| 0 | Success | — |
| 1 | Unknown failure, or `doctor` reporting a problem | Read the `code` field |
| 2 | Usage or configuration error | Fix the command or run `auth login` |
| 3 | Not authenticated, or the session expired | `decker auth login` |
| 4 | Forbidden for this user | Nothing to retry; the server refused |
| 5 | Route or resource not found | Check the id and the plugin version |
| 6 | Conflict — the task changed since it was read | Re-read, rebuild the change, ask again |
| 7 | Rate limited | Wait and retry |
| 8 | Server error | Retry a read; **never** retry a write blindly |
| 9 | Network failure or timeout | Check connectivity and the site URL |
| 10 | **A write whose outcome is unknown** (`rest_outcome_unknown`) | Read the task back before doing anything else; never repeat it |

Successful output is JSON on stdout. Errors are JSON on stderr. Nothing else is
ever written to stdout, so stdout is always parseable.

## Common problems

**`auth_session_expired` / status says `session_expired`.** The WordPress session
is gone. `decker auth login` again. Sessions expire on their own schedule; this
is normal, not a fault.

**`auth_cas_not_reached`.** WordPress did not redirect to the configured CAS
host. Either `--cas` is wrong, or the site is not actually behind CAS, or you
are already logged in through a different mechanism.

**`auth_form_unsupported`.** No password form was found on the CAS page. The
deployment likely requires an interactive login, MFA or a CAPTCHA. This is a
documented limitation, not a bug to route around: log in through a browser if
the institution requires it, and see `authentication.md`.

**`auth_untrusted_redirect`.** A redirect pointed at an origin that is neither
the configured WordPress nor the configured CAS host. The client refuses it. If
the deployment legitimately redirects through a third host, that host must be
configured, not the check removed.

**`rest_unavailable`.** Neither `/wp-json/` nor `?rest_route=` answered with
JSON. A proxy may be intercepting the REST API, or the site URL may be wrong.

**`rest_not_json`.** The server answered with HTML where JSON was expected —
usually a login page or a proxy error page.

**`rest_outcome_unknown` (exit 10).** Any transport failure on a write: a lost
connection, a timeout, an unreadable reply, HTML instead of JSON. The request
left, so the server may well have applied it. **Read the task back and do not
repeat the command** — repeating it is how a task or a comment gets created
twice. The same failures on a read are reported as ordinary read failures,
because nothing was at stake.

**`decker_priority_mismatch` (409) on a placement.** Maximum priority pulls a
card to the front of its stack, so a card with it cannot sit next to one
without it. Match their priority, or place it next to another card.

**`decker_anchor_not_found` (404) on a placement.** The card named as the
neighbour does not exist, is not on a board, or is a hidden task this account
may not see — all three answer the same way on purpose.

**`session_too_large`.** The macOS keychain command interface takes a line of
at most 4096 bytes, and the session did not fit. Log in again, or set
`DECKER_SESSION_STORE=file` to store it in a `0600` file instead.

**`not_found` on a `/wp/v2/users/...` route.** Known on the target deployment:
some user routes return an institutional HTML 404 page. Resolve users through
the routes `doctor` reports as working.

**`method_not_allowed` (405).** The route does not accept that method. Decker's
abilities are reachable with GET (read-only) and POST (mutating) only; none
needs PUT or DELETE, which matters where a proxy handles methods unevenly.

**A 400 where 401 or 403 was expected.** WordPress validates ability input
*before* it calls the permission callback, so a malformed request from an
unauthorized caller reports the malformed input first. Authorization is not
weakened by this; only the order of the errors differs.

**`order_not_as_requested`.** The reorder was applied but the card is not at
the position that was asked for, and the message says where it is. This happens
when the stack holds tasks the account cannot see: they occupy real positions,
so a position derived from the visible order is not the one the server uses.
The board did change — report the actual order to the user rather than retrying.

**`order_stack_truncated`.** The stack holds more tasks than the client can
read in one pass, so it cannot establish an exact position and refuses rather
than guess one. Reorder in the Decker interface, or narrow the board.

**`usage_invalid_switch`.** A switch was given a value that could mean either
thing, such as `--yes=perhaps`. Switches take no value, or one of `true`,
`false`, `1`, `0`, `yes`, `no`. `--yes=false` is a refusal and is honoured as
one.

**The warning about storing cookies in a file.** Expected on any system without
a supported OS keychain. The file is `0600`. Set `DECKER_SESSION_STORE=keychain`
on a machine that has one.

## Reporting a problem

Include the `code` field, the exit code and the `doctor` output. Do **not**
include task content, cookies, nonces or CAS tickets — the client keeps them out
of its own output, so please keep them out of yours.
